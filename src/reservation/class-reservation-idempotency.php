<?php
/**
 * Idempotencia y token opaco de reservas.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Reservation;

use Vicu\Restaurante\Catalog\CatalogDatabase;
use Vicu\Restaurante\Schema;
use WP_Error;

/**
 * Conserva fingerprints y resultados mínimos sin guardar secretos en texto plano.
 */
final class ReservationIdempotency {
	/**
	 * Reclama una clave dentro de la transacción de creación.
	 *
	 * @param string $scope        Identidad idempotente.
	 * @param string $key          Clave aportada por el cliente.
	 * @param string $request_hash Fingerprint normalizado.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function claim( string $scope, string $key, string $request_hash ): array|WP_Error {
		global $wpdb;

		if ( ! self::valid_key( $key ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $request_hash ) ) {
			return self::invalid();
		}

		$key_hash = hash_hmac( 'sha256', 'reservation-idempotency|' . $key, wp_salt( 'auth' ) );
		$table    = Schema::idempotency_table_name();
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
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + 180 * DAY_IN_SECONDS ),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? CatalogDatabase::storage_error() : array(
			'mode' => 'new',
			'id'   => (int) $wpdb->insert_id,
		);
	}

	/**
	 * Marca el resultado como completado sin persistir el token.
	 *
	 * @param int    $id        Registro idempotente.
	 * @param string $public_id UUID de reserva.
	 * @return bool
	 */
	public static function complete( int $id, string $public_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			Schema::idempotency_table_name(),
			array(
				'status'        => 'completed',
				'response_code' => 201,
				'response_json' => wp_json_encode( array( 'reservation_public_id' => $public_id ) ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array(
				'id'     => $id,
				'status' => 'processing',
			),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);

		return 1 === $result;
	}

	/**
	 * Deriva el token reproducible solo para un replay con la misma clave.
	 *
	 * @param string $public_id UUID de reserva.
	 * @param string $key       Clave idempotente.
	 * @return string
	 */
	public static function access_token( string $public_id, string $key ): string {
		return hash_hmac( 'sha256', 'reservation|' . $public_id . '|' . $key, wp_salt( 'secure_auth' ) );
	}

	/**
	 * Hashea el token antes de compararlo con persistencia.
	 *
	 * @param string $token Token opaco.
	 * @return string
	 */
	public static function token_hash( string $token ): string {
		return hash_hmac( 'sha256', 'reservation-access|' . $token, wp_salt( 'auth' ) );
	}

	/**
	 * Resuelve replay, colisión o procesamiento inconcluso.
	 *
	 * @param array<string, mixed> $row          Registro bloqueado.
	 * @param string               $request_hash Fingerprint actual.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function existing( array $row, string $request_hash ): array|WP_Error {
		if ( ! hash_equals( (string) $row['request_hash'], $request_hash ) ) {
			return new WP_Error( 'vicu_restaurante_idempotency_collision', __( 'La clave idempotente ya se usó con otros datos.', 'vicunav-restaurante' ), array( 'status' => 409 ) );
		}

		$response = json_decode( (string) $row['response_json'], true );

		return 'completed' === $row['status'] && is_array( $response )
			? array(
				'mode'     => 'replay',
				'response' => $response,
			)
			: new WP_Error(
				'vicu_restaurante_unavailable',
				__( 'La reserva todavía se está procesando.', 'vicunav-restaurante' ),
				array(
					'status'    => 409,
					'retryable' => true,
				)
			);
	}

	/**
	 * Valida longitud y caracteres de una clave.
	 *
	 * @param string $key Clave.
	 * @return bool
	 */
	private static function valid_key( string $key ): bool {
		$length = strlen( $key );

		return 16 <= $length && 191 >= $length && 0 === preg_match( '/[\x00-\x1F\x7F]/', $key );
	}

	/**
	 * Construye un error público estable.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_request', __( 'La clave idempotente no es válida.', 'vicunav-restaurante' ), array( 'status' => 400 ) );
	}
}
