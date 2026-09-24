<?php
/**
 * Servicio público de solicitudes de pago.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Payments;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Vicu\Restaurante\Payments\PostTypes\PaymentRequest;
use WP_Error;

/**
 * Publica creación, consulta, transición y expiración sin exponer persistencia.
 */
final class PaymentRequests {
	/**
	 * Crea o devuelve idempotentemente una solicitud por referencia externa.
	 *
	 * @param array<string, mixed> $attributes Datos contractuales de creación.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( array $attributes ): array|WP_Error {
		$data = self::normalize_creation_attributes( $attributes );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['reference_hash'] = self::reference_hash( $data['external_type'], $data['external_id'] );
		$data['now']            = current_time( 'mysql', true );

		if ( ! PaymentRequestRepository::begin() ) {
			return self::storage_error();
		}

		$row_id = PaymentRequestRepository::reserve( $data );

		if ( false === $row_id ) {
			PaymentRequestRepository::rollback();

			$existing = PaymentRequestRepository::find_by_reference_hash( $data['reference_hash'] );

			if ( null === $existing ) {
				return self::storage_error();
			}

			if ( self::matches_creation( $existing, $data ) ) {
				return self::to_public_request( $existing );
			}

			return new WP_Error(
				'vicu_pagos_reference_collision',
				__( 'La referencia externa ya pertenece a una solicitud con datos incompatibles.', 'vicunav-restaurante' ),
				array(
					'status'     => 409,
					'request_id' => (int) $existing['post_id'],
				)
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => PaymentRequest::SLUG,
				'post_status' => 'publish',
				'post_title'  => sprintf(
					/* translators: 1: external type, 2: external identifier. */
					__( 'Solicitud de pago %1$s:%2$s', 'vicunav-restaurante' ),
					$data['external_type'],
					$data['external_id']
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			PaymentRequestRepository::rollback();
			return self::storage_error();
		}

		if (
			! PaymentRequestRepository::attach_post( $row_id, $post_id ) ||
			! self::persist_initial_meta( $post_id, $data ) ||
			! PaymentRequestRepository::commit()
		) {
			PaymentRequestRepository::rollback();
			clean_post_cache( $post_id );
			return self::storage_error();
		}

		clean_post_cache( $post_id );

		$row = PaymentRequestRepository::find_by_post_id( $post_id );

		if ( null === $row ) {
			return self::storage_error();
		}

		$request = self::to_public_request( $row );
		EventPublisher::created( $request );

		return $request;
	}

	/**
	 * Consulta una solicitud mediante su identificador público.
	 *
	 * @param int $request_id ID de solicitud.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( int $request_id ): array|WP_Error {
		if ( 1 > $request_id ) {
			return self::not_found_error();
		}

		$row = PaymentRequestRepository::find_by_post_id( $request_id );

		return null === $row ? self::not_found_error() : self::to_public_request( $row );
	}

	/**
	 * Aplica una transición permitida con protección de revisión.
	 *
	 * @param int      $request_id       ID de solicitud.
	 * @param string   $target_state     Estado de destino.
	 * @param int|null $expected_revision Revisión observada por el consumidor.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function transition(
		int $request_id,
		string $target_state,
		?int $expected_revision = null
	): array|WP_Error {
		return self::transition_internal( $request_id, $target_state, $expected_revision, false );
	}

	/**
	 * Expira idempotentemente una solicitud.
	 *
	 * @param int      $request_id        ID de solicitud.
	 * @param int|null $expected_revision Revisión observada por el consumidor.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function expire( int $request_id, ?int $expected_revision = null ): array|WP_Error {
		return self::transition_internal(
			$request_id,
			PaymentRequestState::EXPIRED,
			$expected_revision,
			true
		);
	}

	/**
	 * Expira un lote vencido sin duplicar revisiones ni eventos.
	 *
	 * @param string|null $now   Instante RFC 3339; null usa la hora actual UTC.
	 * @param int         $limit Máximo de solicitudes del lote.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function expire_due( ?string $now = null, int $limit = 100 ): array|WP_Error {
		$normalized_now = null === $now ? current_time( 'mysql', true ) : self::normalize_datetime( $now );

		if ( is_wp_error( $normalized_now ) ) {
			return $normalized_now;
		}

		$limit   = max( 1, min( 500, $limit ) );
		$ids     = PaymentRequestRepository::find_due_ids( $normalized_now, $limit );
		$expired = 0;
		$skipped = 0;
		$errors  = array();

		foreach ( $ids as $request_id ) {
			$result = self::expire( $request_id );

			if ( is_wp_error( $result ) ) {
				++$skipped;
				$errors[ $request_id ] = $result->get_error_code();
				continue;
			}

			++$expired;
		}

		return array(
			'expired' => $expired,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}

	/**
	 * Ejecuta la transición dentro de una transacción bloqueada.
	 *
	 * @param int      $request_id        ID de solicitud.
	 * @param string   $target_state      Estado de destino.
	 * @param int|null $expected_revision Revisión del consumidor.
	 * @param bool     $idempotent_target Devuelve el destino ya persistido sin efecto.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function transition_internal(
		int $request_id,
		string $target_state,
		?int $expected_revision,
		bool $idempotent_target
	): array|WP_Error {
		$target_state = sanitize_key( $target_state );

		if (
			1 > $request_id ||
			! in_array( $target_state, PaymentRequestState::all(), true ) ||
			( null !== $expected_revision && 1 > $expected_revision )
		) {
			return self::invalid_request_error();
		}

		if ( ! PaymentRequestRepository::begin() ) {
			return self::storage_error();
		}

		$row = PaymentRequestRepository::find_by_post_id( $request_id, true );

		if ( null === $row ) {
			PaymentRequestRepository::rollback();
			return self::not_found_error();
		}

		$current_state    = (string) $row['state'];
		$current_revision = (int) $row['revision'];

		if ( $idempotent_target && $target_state === $current_state ) {
			if ( ! PaymentRequestRepository::commit() ) {
				return self::storage_error();
			}

			return self::to_public_request( $row );
		}

		if ( null !== $expected_revision && $expected_revision !== $current_revision ) {
			PaymentRequestRepository::rollback();

			return new WP_Error(
				'vicu_pagos_concurrent_transition',
				__( 'La solicitud cambió después de la revisión esperada.', 'vicunav-restaurante' ),
				array(
					'status'            => 409,
					'expected_revision' => $expected_revision,
					'current_revision'  => $current_revision,
				)
			);
		}

		if ( ! PaymentRequestState::can_transition( $current_state, $target_state ) ) {
			PaymentRequestRepository::rollback();

			return new WP_Error(
				'vicu_pagos_invalid_transition',
				__( 'La transición solicitada no está permitida.', 'vicunav-restaurante' ),
				array(
					'status' => 409,
					'from'   => $current_state,
					'to'     => $target_state,
				)
			);
		}

		$updated_at = current_time( 'mysql', true );

		if (
			! PaymentRequestRepository::transition(
				(int) $row['id'],
				$current_state,
				$target_state,
				$current_revision,
				$updated_at
			) ||
			! self::persist_transition_meta( $request_id, $target_state, $current_revision + 1 ) ||
			! PaymentRequestRepository::commit()
		) {
			PaymentRequestRepository::rollback();
			clean_post_cache( $request_id );
			return self::storage_error();
		}

		clean_post_cache( $request_id );
		$updated = PaymentRequestRepository::find_by_post_id( $request_id );

		if ( null === $updated ) {
			return self::storage_error();
		}

		$request = self::to_public_request( $updated );
		EventPublisher::transitioned( $current_state, $target_state, $request );

		return $request;
	}

	/**
	 * Normaliza y valida atributos antes de iniciar escrituras.
	 *
	 * @param array<string, mixed> $attributes Datos recibidos.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function normalize_creation_attributes( array $attributes ): array|WP_Error {
		$external_type = PaymentRequest::sanitize_external_type( $attributes['external_type'] ?? '' );
		$external_id   = PaymentRequest::sanitize_external_id( $attributes['external_id'] ?? '' );
		$amount_minor  = PaymentRequest::sanitize_amount_minor( $attributes['amount_minor'] ?? 0 );
		$currency      = PaymentRequest::sanitize_currency( $attributes['currency'] ?? '' );
		$expires_at    = self::normalize_optional_datetime( $attributes['expires_at'] ?? null );

		if (
			'' === $external_type ||
			'' === $external_id ||
			1 > $amount_minor ||
			'' === $currency ||
			is_wp_error( $expires_at )
		) {
			return self::invalid_request_error();
		}

		return array(
			'external_type' => $external_type,
			'external_id'   => $external_id,
			'amount_minor'  => $amount_minor,
			'currency'      => $currency,
			'expires_at'    => $expires_at,
		);
	}

	/**
	 * Normaliza un vencimiento opcional.
	 *
	 * @param mixed $value Valor recibido.
	 * @return string|null|WP_Error
	 */
	private static function normalize_optional_datetime( mixed $value ): string|null|WP_Error {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( ! is_string( $value ) ) {
			return self::invalid_request_error();
		}

		return self::normalize_datetime( $value );
	}

	/**
	 * Convierte RFC 3339 a fecha UTC de MySQL.
	 *
	 * @param string $value Fecha pública.
	 * @return string|WP_Error
	 */
	private static function normalize_datetime( string $value ): string|WP_Error {
		$normalized = str_ends_with( $value, 'Z' ) ? substr( $value, 0, -1 ) . '+00:00' : $value;
		$date       = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:sP', $normalized );
		$errors     = DateTimeImmutable::getLastErrors();

		if (
			false === $date ||
			( is_array( $errors ) && ( 0 < $errors['warning_count'] || 0 < $errors['error_count'] ) )
		) {
			return self::invalid_request_error();
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Persiste el espejo inicial dentro de la misma transacción.
	 *
	 * @param int                  $post_id ID administrativo.
	 * @param array<string, mixed> $data    Datos normalizados.
	 * @return bool
	 */
	private static function persist_initial_meta( int $post_id, array $data ): bool {
		$values = array(
			PaymentRequest::META_EXTERNAL_TYPE => $data['external_type'],
			PaymentRequest::META_EXTERNAL_ID   => $data['external_id'],
			PaymentRequest::META_AMOUNT_MINOR  => $data['amount_minor'],
			PaymentRequest::META_CURRENCY      => $data['currency'],
			PaymentRequest::META_STATE         => PaymentRequestState::PENDING,
			PaymentRequest::META_REVISION      => 1,
			PaymentRequest::META_EXPIRES_AT    => self::to_public_datetime( $data['expires_at'] ),
		);

		foreach ( $values as $meta_key => $value ) {
			if ( false === add_post_meta( $post_id, $meta_key, $value, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Actualiza el espejo de estado y revisión dentro de la transacción.
	 *
	 * @param int    $post_id  ID administrativo.
	 * @param string $state    Estado nuevo.
	 * @param int    $revision Revisión nueva.
	 * @return bool
	 */
	private static function persist_transition_meta( int $post_id, string $state, int $revision ): bool {
		$state_result    = update_post_meta( $post_id, PaymentRequest::META_STATE, $state );
		$revision_result = update_post_meta( $post_id, PaymentRequest::META_REVISION, $revision );

		return false !== $state_result && false !== $revision_result;
	}

	/**
	 * Compara los datos inmutables de una creación repetida.
	 *
	 * @param array<string, mixed> $existing Fila persistida.
	 * @param array<string, mixed> $data     Datos normalizados.
	 * @return bool
	 */
	private static function matches_creation( array $existing, array $data ): bool {
		return (string) $existing['external_type'] === $data['external_type'] &&
			(string) $existing['external_id'] === $data['external_id'] &&
			(int) $existing['amount_minor'] === $data['amount_minor'] &&
			(string) $existing['currency'] === $data['currency'] &&
			self::nullable_string( $existing['expires_at'] ) === $data['expires_at'];
	}

	/**
	 * Construye el array público sin exponer columnas internas.
	 *
	 * @param array<string, mixed> $row Fila persistida.
	 * @return array<string, mixed>
	 */
	private static function to_public_request( array $row ): array {
		return array(
			'id'                 => (int) $row['post_id'],
			'external_reference' => array(
				'type' => (string) $row['external_type'],
				'id'   => (string) $row['external_id'],
			),
			'amount_minor'       => (int) $row['amount_minor'],
			'currency'           => (string) $row['currency'],
			'provider'           => self::nullable_string( $row['provider'] ?? null ),
			'state'              => (string) $row['state'],
			'revision'           => (int) $row['revision'],
			'expires_at'         => self::to_public_datetime( $row['expires_at'] ),
			'created_at'         => self::to_public_datetime( $row['created_at'] ),
			'updated_at'         => self::to_public_datetime( $row['updated_at'] ),
		);
	}

	/**
	 * Convierte una fecha MySQL UTC a RFC 3339.
	 *
	 * @param mixed $value Fecha de base de datos.
	 * @return string|null
	 */
	private static function to_public_datetime( mixed $value ): ?string {
		$value = self::nullable_string( $value );

		if ( null === $value ) {
			return null;
		}

		try {
			$date = new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
		} catch ( Throwable ) {
			return null;
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_RFC3339 );
	}

	/**
	 * Normaliza valores de base de datos que representan null.
	 *
	 * @param mixed $value Valor recibido.
	 * @return string|null
	 */
	private static function nullable_string( mixed $value ): ?string {
		return null === $value || '' === $value ? null : (string) $value;
	}

	/**
	 * Calcula la clave única sin publicar su forma interna.
	 *
	 * @param string $external_type Tipo externo.
	 * @param string $external_id   Identificador externo.
	 * @return string
	 */
	private static function reference_hash( string $external_type, string $external_id ): string {
		return hash( 'sha256', $external_type . "\0" . $external_id );
	}

	/**
	 * Error común de entrada.
	 *
	 * @return WP_Error
	 */
	private static function invalid_request_error(): WP_Error {
		return new WP_Error(
			'vicu_pagos_invalid_request',
			__( 'Los datos de la solicitud de pago no son válidos.', 'vicunav-restaurante' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Error común de solicitud ausente.
	 *
	 * @return WP_Error
	 */
	private static function not_found_error(): WP_Error {
		return new WP_Error(
			'vicu_pagos_request_not_found',
			__( 'La solicitud de pago no existe.', 'vicunav-restaurante' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Error común de persistencia.
	 *
	 * @return WP_Error
	 */
	private static function storage_error(): WP_Error {
		return new WP_Error(
			'vicu_pagos_storage_error',
			__( 'No fue posible persistir la solicitud de pago.', 'vicunav-restaurante' ),
			array( 'status' => 500 )
		);
	}
}
