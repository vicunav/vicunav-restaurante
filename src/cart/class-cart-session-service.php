<?php
/**
 * Identidad segura para carritos anónimos y autenticados.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Cart;

use Vicu\Restaurante\Catalog\CatalogDatabase;
use Vicu\Restaurante\Schema;
use Vicu\Restaurante\Settings\RestaurantSettings;
use WP_Error;

/**
 * Persiste únicamente hashes y nunca devuelve secretos almacenados.
 */
final class CartSessionService {
	public const COOKIE_NAME = 'vicu_rest_session';

	/**
	 * Crea una identidad anónima con entropía criptográfica.
	 *
	 * @return array<string, int|string>|WP_Error
	 */
	public static function create_anonymous(): array|WP_Error {
		global $wpdb;

		$public_id  = wp_generate_uuid4();
		$secret     = bin2hex( random_bytes( 32 ) );
		$csrf_token = self::derive_csrf( $public_id, $secret );
		$now        = current_time( 'mysql', true );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + RestaurantSettings::cart_lifetime_hours() * HOUR_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			Schema::cart_sessions_table_name(),
			array(
				'public_id'   => $public_id,
				'secret_hash' => self::hash_secret( $secret, 'session' ),
				'csrf_hash'   => self::hash_secret( $csrf_token, 'csrf' ),
				'user_id'     => null,
				'expires_at'  => $expires_at,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return CatalogDatabase::storage_error();
		}

		return array(
			'type'       => 'session',
			'key'        => 'session:' . (int) $wpdb->insert_id,
			'session_id' => (int) $wpdb->insert_id,
			'user_id'    => 0,
			'credential' => $public_id . '.' . $secret,
			'csrf_token' => $csrf_token,
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Resuelve una credencial sin publicar si el UUID existía.
	 *
	 * @param string $credential Valor de cookie.
	 * @return array<string, int|string>|WP_Error
	 */
	public static function resolve_anonymous( string $credential ): array|WP_Error {
		global $wpdb;

		$parts = explode( '.', $credential, 2 );

		if ( 2 !== count( $parts ) || ! wp_is_uuid( $parts[0], 4 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $parts[1] ) ) {
			return self::authentication_error();
		}

		$table = Schema::cart_sessions_table_name();
		// El identificador pertenece al schema fijo y el UUID usa placeholder.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s", $parts[0] ), ARRAY_A );

		if (
			! is_array( $row ) ||
			! hash_equals( (string) $row['secret_hash'], self::hash_secret( $parts[1], 'session' ) ) ||
			(string) $row['expires_at'] <= current_time( 'mysql', true )
		) {
			return self::authentication_error();
		}

		$csrf_token = self::derive_csrf( $parts[0], $parts[1] );

		if ( ! hash_equals( (string) $row['csrf_hash'], self::hash_secret( $csrf_token, 'csrf' ) ) ) {
			return self::authentication_error();
		}

		return array(
			'type'       => 'session',
			'key'        => 'session:' . (int) $row['id'],
			'session_id' => (int) $row['id'],
			'user_id'    => (int) $row['user_id'],
			'csrf_token' => $csrf_token,
			'expires_at' => (string) $row['expires_at'],
		);
	}

	/**
	 * Construye una identidad WordPress sin depender de un rol nominal.
	 *
	 * @param int $user_id Usuario autenticado.
	 * @return array<string, int|string>
	 */
	public static function authenticated( int $user_id ): array {
		return array(
			'type'       => 'user',
			'key'        => 'user:' . $user_id,
			'session_id' => 0,
			'user_id'    => $user_id,
			'csrf_token' => '',
			'expires_at' => '',
		);
	}

	/**
	 * Define la cookie de sesión endurecida y limitada al sitio.
	 *
	 * @param string $credential Credencial opaca.
	 * @param string $expires_at Vencimiento UTC.
	 * @return bool
	 */
	public static function send_cookie( string $credential, string $expires_at ): bool {
		if ( headers_sent() ) {
			return false;
		}

		$path = defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/';

		return setcookie(
			self::COOKIE_NAME,
			$credential,
			array(
				'expires'  => strtotime( $expires_at . ' UTC' ),
				'path'     => $path,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Retira del navegador una credencial ya rotada en servidor.
	 *
	 * @return bool
	 */
	public static function clear_cookie(): bool {
		if ( headers_sent() ) {
			return false;
		}

		$path = defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/';

		return setcookie(
			self::COOKIE_NAME,
			'',
			array(
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => $path,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Deriva el token antifalsificación sin persistirlo en claro.
	 *
	 * @param string $public_id UUID de sesión.
	 * @param string $secret    Secreto de sesión.
	 * @return string
	 */
	private static function derive_csrf( string $public_id, string $secret ): string {
		return hash_hmac( 'sha256', 'csrf|' . $public_id . '|' . $secret, wp_salt( 'nonce' ) );
	}

	/**
	 * Genera un hash con separación de propósito.
	 *
	 * @param string $secret  Secreto en memoria.
	 * @param string $purpose Dominio del hash.
	 * @return string
	 */
	private static function hash_secret( string $secret, string $purpose ): string {
		return hash_hmac( 'sha256', $purpose . '|' . $secret, wp_salt( 'auth' ) );
	}

	/**
	 * Error indistinguible para sesiones ausentes, inválidas o vencidas.
	 *
	 * @return WP_Error
	 */
	private static function authentication_error(): WP_Error {
		return new WP_Error(
			'vicu_restaurante_authentication_required',
			__( 'Se requiere una sesión de carrito válida.', 'vicunav-restaurante' ),
			array( 'status' => 401 )
		);
	}
}
