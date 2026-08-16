<?php
/**
 * Registro idempotente del checkout.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Order;

use Vicu\Restaurante\Catalog\CatalogDatabase;
use Vicu\Restaurante\Schema;
use WP_Error;

/**
 * Guarda hashes de claves y huellas, nunca la clave original.
 */
final class OrderIdempotency {
	/**
	 * Reclama una clave dentro de la transacción actual.
	 *
	 * @param string $scope        Scope por identidad.
	 * @param string $key          Clave externa.
	 * @param string $request_hash Huella canónica.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function claim( string $scope, string $key, string $request_hash ): array|WP_Error {
		global $wpdb;

		if ( ! self::valid_key( $key ) || 191 < strlen( $scope ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $request_hash ) ) {
			return self::invalid();
		}

		$key_hash = self::key_hash( $key );
		$table    = Schema::idempotency_table_name();
		// La lectura con bloqueo serializa dos checkouts con la misma clave.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE scope = %s AND key_hash = %s FOR UPDATE", $scope, $key_hash ), ARRAY_A );

		if ( is_array( $row ) ) {
			return self::existing( $row, $request_hash );
		}

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'scope'         => $scope,
				'key_hash'      => $key_hash,
				'request_hash'  => $request_hash,
				'status'        => 'processing',
				'response_code' => null,
				'response_json' => null,
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			// Un insert concurrente puede haber ganado mientras esta transacción esperaba.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE scope = %s AND key_hash = %s FOR UPDATE", $scope, $key_hash ), ARRAY_A );

			return is_array( $row ) ? self::existing( $row, $request_hash ) : CatalogDatabase::storage_error();
		}

		return array(
			'mode' => 'new',
			'id'   => (int) $wpdb->insert_id,
		);
	}

	/**
	 * Congela el resultado mínimo no secreto.
	 *
	 * @param int                  $id       Registro reclamado.
	 * @param int                  $code     HTTP persistido.
	 * @param array<string, mixed> $response Resultado mínimo.
	 * @return bool
	 */
	public static function complete( int $id, int $code, array $response ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			Schema::idempotency_table_name(),
			array(
				'status'        => 'completed',
				'response_code' => $code,
				'response_json' => wp_json_encode( $response ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array(
				'id'     => $id,
				'status' => 'processing',
			),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);

		return 1 === $updated;
	}

	/**
	 * Deriva el token recuperable solo con la clave que conoce el cliente.
	 *
	 * @param string $order_public_id UUID del pedido.
	 * @param string $key             Clave idempotente.
	 * @return string
	 */
	public static function access_token( string $order_public_id, string $key ): string {
		return hash_hmac( 'sha256', 'order|' . $order_public_id . '|' . $key, wp_salt( 'secure_auth' ) );
	}

	/**
	 * Hash persistible de un token de gestión.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function access_token_hash( string $token ): string {
		return hash_hmac( 'sha256', 'order-access|' . $token, wp_salt( 'auth' ) );
	}

	/**
	 * Compara una clave ya existente y devuelve replay o colisión.
	 *
	 * @param array<string, mixed> $row          Fila.
	 * @param string               $request_hash Huella.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function existing( array $row, string $request_hash ): array|WP_Error {
		if ( ! hash_equals( (string) $row['request_hash'], $request_hash ) ) {
			return new WP_Error( 'vicu_restaurante_idempotency_collision', __( 'La clave idempotente ya se usó con otros datos.', 'vicunav-restaurante' ), array( 'status' => 409 ) );
		}

		if ( 'completed' !== $row['status'] ) {
			return new WP_Error(
				'vicu_restaurante_unavailable',
				__( 'El checkout todavía se está procesando.', 'vicunav-restaurante' ),
				array(
					'status'    => 409,
					'retryable' => true,
				)
			);
		}

		$response = json_decode( (string) $row['response_json'], true );

		return is_array( $response )
			? array(
				'mode'     => 'replay',
				'response' => $response,
				'code'     => (int) $row['response_code'],
			)
			: CatalogDatabase::storage_error();
	}

	/**
	 * Valida longitud y ausencia de controles.
	 *
	 * @param string $key Clave.
	 * @return bool
	 */
	private static function valid_key( string $key ): bool {
		$length = strlen( $key );

		return 16 <= $length && 191 >= $length && 0 === preg_match( '/[\x00-\x1F\x7F]/', $key );
	}

	/**
	 * Hash separado de otras clases de secreto.
	 *
	 * @param string $key Clave.
	 * @return string
	 */
	private static function key_hash( string $key ): string {
		return hash_hmac( 'sha256', 'idempotency|' . $key, wp_salt( 'auth' ) );
	}

	/**
	 * Error de clave inválida.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_request', __( 'La clave idempotente no es válida.', 'vicunav-restaurante' ), array( 'status' => 400 ) );
	}
}
