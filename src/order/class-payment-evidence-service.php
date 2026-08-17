<?php
/**
 * Evidencia textual privada del proveedor manual.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Order;

use Vicu\Pagos\ManualPaymentProvider;
use Vicu\Restaurante\Catalog\CatalogDatabase;
use Vicu\Restaurante\Schema;
use WP_Error;

/**
 * Entrega a pagos solo una referencia opaca, nunca el texto del cliente.
 */
final class PaymentEvidenceService {
	/**
	 * Persiste y entrega evidencia de forma recuperable e idempotente.
	 *
	 * @param string                    $order_public_id UUID del pedido.
	 * @param array<string, int|string> $identity        Identidad propietaria.
	 * @param string                    $order_token     Token invitado.
	 * @param string                    $idempotency_key Clave del request.
	 * @param mixed                     $reference       Referencia textual.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function submit( string $order_public_id, array $identity, string $order_token, string $idempotency_key, mixed $reference ): array|WP_Error {
		global $wpdb;

		$order = OrderService::get( $order_public_id, $identity, $order_token );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$reference = self::normalize_reference( $reference );

		if ( null === $reference || ! self::valid_key( $idempotency_key ) ) {
			return self::invalid();
		}

		$payment = PaymentIntegration::ensure_request( $order_public_id );

		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		$order_record = OrderService::payment_record( $order_public_id );

		if ( null === $order_record || null === $order_record['payment_request_id'] ) {
			return self::dependency_error();
		}

		$key_hash     = hash_hmac( 'sha256', 'payment-evidence|' . $idempotency_key, wp_salt( 'auth' ) );
		$request_hash = hash( 'sha256', $reference );
		$table        = Schema::payment_evidence_table_name();

		if ( ! CatalogDatabase::begin() ) {
			return self::storage_error();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d AND idempotency_hash = %s FOR UPDATE", $order_record['internal_id'], $key_hash ), ARRAY_A );

		if ( is_array( $row ) && ! hash_equals( (string) $row['request_hash'], $request_hash ) ) {
			CatalogDatabase::rollback();
			return new WP_Error( 'vicu_restaurante_idempotency_collision', __( 'La clave idempotente ya se usó con otra evidencia.', 'vicunav-restaurante' ), array( 'status' => 409 ) );
		}

		if ( ! is_array( $row ) ) {
			$now = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert(
				$table,
				array(
					'public_id'                => wp_generate_uuid4(),
					'order_id'                 => $order_record['internal_id'],
					'idempotency_hash'         => $key_hash,
					'request_hash'             => $request_hash,
					'reference_text'           => $reference,
					'payment_submission_id'    => null,
					'payment_request_revision' => 0,
					'status'                   => 'pending',
					'last_error'               => null,
					'created_at'               => $now,
					'updated_at'               => $now,
				),
				array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
			);

			if ( false === $inserted ) {
				CatalogDatabase::rollback();
				return self::storage_error();
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
		}

		if ( ! CatalogDatabase::commit() || ! is_array( $row ) ) {
			CatalogDatabase::rollback();
			return self::storage_error();
		}

		$result = ManualPaymentProvider::submit_proof(
			$order_record['payment_request_id'],
			array(
				'proof_reference' => 'vicu-order-evidence:' . $row['public_id'],
				'idempotency_key' => hash_hmac( 'sha256', 'payment-submission|' . $row['public_id'], wp_salt( 'secure_auth' ) ),
			),
			$order_record['payment_revision']
		);

		if ( is_wp_error( $result ) ) {
			if ( ! self::update_result( (int) $row['id'], 'error', null, 0, $result->get_error_code() ) ) {
				return self::storage_error();
			}
			OrderService::mark_payment_error( $order_public_id, 'vicu_restaurante_dependency_unavailable' );
			return $result;
		}

		if ( ! self::update_result(
			(int) $row['id'],
			'submitted',
			(int) $result['submission']['id'],
			(int) $result['request']['revision'],
			null
		) ) {
			return self::storage_error();
		}
		$observed = OrderService::observe_payment( $result['request'], 'evidence' );

		if ( is_wp_error( $observed ) ) {
			return $observed;
		}
		$fresh = self::find( (int) $row['id'] );

		return null === $fresh
			? self::storage_error()
			: array(
				'evidence' => self::public_response( $fresh ),
				'order'    => OrderService::get( $order_public_id, $identity, $order_token ),
			);
	}

	/**
	 * Lista evidencia privada para administración autorizada.
	 *
	 * @param int $order_id ID interno del pedido.
	 * @return array<int, array<string, mixed>>
	 */
	public static function admin_for_order( int $order_id ): array {
		global $wpdb;

		$table = Schema::payment_evidence_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY created_at ASC, id ASC", $order_id ), ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				return array(
					'public_id'      => (string) $row['public_id'],
					'reference_text' => (string) $row['reference_text'],
					'status'         => (string) $row['status'],
					'last_error'     => null === $row['last_error'] ? null : (string) $row['last_error'],
					'created_at'     => mysql_to_rfc3339( (string) $row['created_at'] ),
				);
			},
			$rows
		);
	}

	/**
	 * Actualiza el resultado recuperable de la entrega.
	 *
	 * @param int         $id            Evidencia.
	 * @param string      $status        Estado.
	 * @param int|null    $submission_id ID público de pagos.
	 * @param int         $revision      Revisión de pagos.
	 * @param string|null $error         Código seguro.
	 * @return bool
	 */
	private static function update_result( int $id, string $status, ?int $submission_id, int $revision, ?string $error ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			Schema::payment_evidence_table_name(),
			array(
				'payment_submission_id'    => $submission_id,
				'payment_request_revision' => $revision,
				'status'                   => $status,
				'last_error'               => null === $error ? null : substr( sanitize_key( $error ), 0, 64 ),
				'updated_at'               => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Busca por ID interno ya autorizado.
	 *
	 * @param int $id ID.
	 * @return array<string, mixed>|null
	 */
	private static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::payment_evidence_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Forma pública sin referencia, hashes ni IDs del proveedor.
	 *
	 * @param array<string, mixed> $row Evidencia.
	 * @return array<string, mixed>
	 */
	private static function public_response( array $row ): array {
		return array(
			'public_id'    => (string) $row['public_id'],
			'status'       => (string) $row['status'],
			'submitted_at' => mysql_to_rfc3339( (string) $row['created_at'] ),
		);
	}

	/**
	 * Normaliza referencia sin aceptar transformaciones ambiguas.
	 *
	 * @param mixed $value Valor.
	 * @return string|null
	 */
	private static function normalize_reference( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$normalized = trim( sanitize_text_field( $value ) );

		return '' !== $normalized && 191 >= strlen( $normalized ) && trim( $value ) === $normalized ? $normalized : null;
	}

	/**
	 * Valida una clave idempotente externa.
	 *
	 * @param string $key Clave.
	 * @return bool
	 */
	private static function valid_key( string $key ): bool {
		$length = strlen( $key );

		return 16 <= $length && 191 >= $length && 0 === preg_match( '/[\x00-\x1F\x7F]/', $key );
	}

	/**
	 * Error de entrada estable.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_request', __( 'La evidencia manual no es válida.', 'vicunav-restaurante' ), array( 'status' => 400 ) );
	}

	/**
	 * Error de persistencia estable.
	 *
	 * @return WP_Error
	 */
	private static function storage_error(): WP_Error {
		return new WP_Error( 'vicu_restaurante_storage_error', __( 'No se pudo guardar la evidencia manual.', 'vicunav-restaurante' ), array( 'status' => 500 ) );
	}

	/**
	 * Error recuperable del contrato de pagos.
	 *
	 * @return WP_Error
	 */
	private static function dependency_error(): WP_Error {
		return new WP_Error( 'vicu_restaurante_dependency_unavailable', __( 'No se pudo sincronizar la solicitud de pago.', 'vicunav-restaurante' ), array( 'status' => 503 ) );
	}
}
