<?php
/**
 * Servicio público del proveedor manual.
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
 * Recibe referencias opacas con idempotencia y transición atómica.
 */
final class ManualPaymentProvider {
	public const CODE = 'manual';

	private const OPTION_ENABLED = 'vicu_pagos_manual_enabled';

	/**
	 * Persiste la configuración completa del proveedor.
	 *
	 * @param array<string, mixed> $configuration Configuración contractual.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function configure( array $configuration ): array|WP_Error {
		if (
			array( 'enabled' ) !== array_keys( $configuration ) ||
			! is_bool( $configuration['enabled'] )
		) {
			return self::invalid_configuration_error();
		}

		update_option( self::OPTION_ENABLED, $configuration['enabled'], false );

		$persisted = self::get_configuration();

		return $configuration['enabled'] === $persisted['enabled'] ? $persisted : self::storage_error();
	}

	/**
	 * Devuelve una configuración pública completa y estable.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_configuration(): array {
		return array(
			'provider' => self::CODE,
			'enabled'  => rest_sanitize_boolean( get_option( self::OPTION_ENABLED, false ) ),
		);
	}

	/**
	 * Persiste una referencia de comprobante y transiciona la solicitud.
	 *
	 * @param int                  $request_id        ID público de solicitud.
	 * @param array<string, mixed> $submission        Payload contractual.
	 * @param int|null             $expected_revision Revisión observada.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function submit_proof(
		int $request_id,
		array $submission,
		?int $expected_revision = null
	): array|WP_Error {
		$submission = self::normalize_submission( $submission );

		if (
			is_wp_error( $submission ) ||
			1 > $request_id ||
			( null !== $expected_revision && 1 > $expected_revision )
		) {
			return is_wp_error( $submission ) ? $submission : self::invalid_submission_error();
		}

		if ( ! self::get_configuration()['enabled'] ) {
			return new WP_Error(
				'vicu_pagos_manual_provider_disabled',
				__( 'El proveedor manual está deshabilitado.', 'vicunav-restaurante' ),
				array( 'status' => 409 )
			);
		}

		if ( ! PaymentRequestRepository::begin() ) {
			return self::storage_error();
		}

		$request_row = PaymentRequestRepository::find_by_post_id( $request_id, true );

		if ( null === $request_row ) {
			PaymentRequestRepository::rollback();
			return self::request_not_found_error();
		}

		$idempotency_hash = hash( 'sha256', $submission['idempotency_key'] );
		$existing         = ManualSubmissionRepository::find_by_idempotency( $request_id, $idempotency_hash );

		if ( null !== $existing ) {
			if ( $submission['proof_reference'] !== (string) $existing['proof_reference'] ) {
				PaymentRequestRepository::rollback();

				return new WP_Error(
					'vicu_pagos_manual_submission_collision',
					__( 'La clave idempotente ya identifica otra referencia de comprobante.', 'vicunav-restaurante' ),
					array(
						'status'        => 409,
						'request_id'    => $request_id,
						'submission_id' => (int) $existing['id'],
					)
				);
			}

			if ( ! PaymentRequestRepository::commit() ) {
				PaymentRequestRepository::rollback();
				return self::storage_error();
			}

			$request = PaymentRequests::get( $request_id );

			if ( is_wp_error( $request ) ) {
				return self::storage_error();
			}

			return array(
				'request'    => $request,
				'submission' => self::to_public_submission( $existing ),
			);
		}

		$current_state    = (string) $request_row['state'];
		$current_revision = (int) $request_row['revision'];

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

		if ( ! PaymentRequestState::can_transition( $current_state, PaymentRequestState::PROOF_UPLOADED ) ) {
			PaymentRequestRepository::rollback();

			return new WP_Error(
				'vicu_pagos_invalid_transition',
				__( 'La transición solicitada no está permitida.', 'vicunav-restaurante' ),
				array(
					'status' => 409,
					'from'   => $current_state,
					'to'     => PaymentRequestState::PROOF_UPLOADED,
				)
			);
		}

		$created_at       = current_time( 'mysql', true );
		$request_revision = $current_revision + 1;
		$submission_id    = ManualSubmissionRepository::insert(
			array(
				'request_id'       => $request_id,
				'idempotency_hash' => $idempotency_hash,
				'proof_reference'  => $submission['proof_reference'],
				'request_revision' => $request_revision,
				'created_at'       => $created_at,
			)
		);

		if (
			false === $submission_id ||
			! PaymentRequestRepository::transition(
				(int) $request_row['id'],
				$current_state,
				PaymentRequestState::PROOF_UPLOADED,
				$current_revision,
				$created_at,
				self::CODE
			) ||
			! self::persist_transition_meta( $request_id, $request_revision ) ||
			! PaymentRequestRepository::commit()
		) {
			PaymentRequestRepository::rollback();
			clean_post_cache( $request_id );
			return self::storage_error();
		}

		clean_post_cache( $request_id );
		$request        = PaymentRequests::get( $request_id );
		$submission_row = ManualSubmissionRepository::find_by_id( $request_id, $submission_id );

		if ( is_wp_error( $request ) || null === $submission_row ) {
			return self::storage_error();
		}

		$public_submission = self::to_public_submission( $submission_row );
		EventPublisher::manual_proof_received( $current_state, $request, $public_submission );

		return array(
			'request'    => $request,
			'submission' => $public_submission,
		);
	}

	/**
	 * Consulta una entrega sin exponer su identidad idempotente interna.
	 *
	 * @param int $request_id    ID público de solicitud.
	 * @param int $submission_id ID de entrega.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_submission( int $request_id, int $submission_id ): array|WP_Error {
		if ( 1 > $request_id || 1 > $submission_id ) {
			return self::submission_not_found_error();
		}

		$row = ManualSubmissionRepository::find_by_id( $request_id, $submission_id );

		return null === $row ? self::submission_not_found_error() : self::to_public_submission( $row );
	}

	/**
	 * Normaliza el payload estricto antes de cualquier escritura.
	 *
	 * @param array<string, mixed> $submission Payload recibido.
	 * @return array<string, string>|WP_Error
	 */
	private static function normalize_submission( array $submission ): array|WP_Error {
		$keys = array_keys( $submission );
		sort( $keys );

		if (
			array( 'idempotency_key', 'proof_reference' ) !== $keys ||
			! is_string( $submission['proof_reference'] ) ||
			! is_string( $submission['idempotency_key'] )
		) {
			return self::invalid_submission_error();
		}

		$proof_reference = sanitize_text_field( wp_unslash( $submission['proof_reference'] ) );
		$idempotency_key = sanitize_text_field( wp_unslash( $submission['idempotency_key'] ) );

		if (
			'' === $proof_reference ||
			'' === $idempotency_key ||
			191 < strlen( $proof_reference ) ||
			191 < strlen( $idempotency_key )
		) {
			return self::invalid_submission_error();
		}

		return array(
			'proof_reference' => $proof_reference,
			'idempotency_key' => $idempotency_key,
		);
	}

	/**
	 * Actualiza el espejo administrativo dentro de la transacción activa.
	 *
	 * @param int $request_id      ID público de solicitud.
	 * @param int $request_revision Revisión nueva.
	 * @return bool
	 */
	private static function persist_transition_meta( int $request_id, int $request_revision ): bool {
		update_post_meta( $request_id, PaymentRequest::META_PROVIDER, self::CODE );
		update_post_meta( $request_id, PaymentRequest::META_STATE, PaymentRequestState::PROOF_UPLOADED );
		update_post_meta( $request_id, PaymentRequest::META_REVISION, $request_revision );

		return self::CODE === get_post_meta( $request_id, PaymentRequest::META_PROVIDER, true ) &&
			PaymentRequestState::PROOF_UPLOADED === get_post_meta( $request_id, PaymentRequest::META_STATE, true ) &&
			(int) get_post_meta( $request_id, PaymentRequest::META_REVISION, true ) === $request_revision;
	}

	/**
	 * Construye la forma pública estable de una entrega.
	 *
	 * @param array<string, mixed> $row Fila interna.
	 * @return array<string, mixed>
	 */
	private static function to_public_submission( array $row ): array {
		return array(
			'id'               => (int) $row['id'],
			'provider'         => self::CODE,
			'proof_reference'  => (string) $row['proof_reference'],
			'request_revision' => (int) $row['request_revision'],
			'submitted_at'     => self::to_public_datetime( (string) $row['created_at'] ),
		);
	}

	/**
	 * Convierte una fecha UTC interna a RFC 3339.
	 *
	 * @param string $value Fecha de base de datos.
	 * @return string|null
	 */
	private static function to_public_datetime( string $value ): ?string {
		try {
			$date = new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
		} catch ( Throwable ) {
			return null;
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_RFC3339 );
	}

	/**
	 * Devuelve un error de configuración inválida.
	 *
	 * @return WP_Error
	 */
	private static function invalid_configuration_error(): WP_Error {
		return new WP_Error(
			'vicu_pagos_manual_invalid_configuration',
			__( 'La configuración del proveedor manual no es válida.', 'vicunav-restaurante' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Devuelve un error de entrega inválida.
	 *
	 * @return WP_Error
	 */
	private static function invalid_submission_error(): WP_Error {
		return new WP_Error(
			'vicu_pagos_manual_invalid_submission',
			__( 'Los datos del comprobante manual no son válidos.', 'vicunav-restaurante' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Devuelve un error de solicitud ausente.
	 *
	 * @return WP_Error
	 */
	private static function request_not_found_error(): WP_Error {
		return new WP_Error(
			'vicu_pagos_request_not_found',
			__( 'La solicitud de pago no existe.', 'vicunav-restaurante' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Devuelve un error de entrega ausente.
	 *
	 * @return WP_Error
	 */
	private static function submission_not_found_error(): WP_Error {
		return new WP_Error(
			'vicu_pagos_manual_submission_not_found',
			__( 'La entrega manual no existe para la solicitud indicada.', 'vicunav-restaurante' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Devuelve un error de persistencia.
	 *
	 * @return WP_Error
	 */
	private static function storage_error(): WP_Error {
		return new WP_Error(
			'vicu_pagos_storage_error',
			__( 'No fue posible persistir la operación de pago.', 'vicunav-restaurante' ),
			array( 'status' => 500 )
		);
	}
}
