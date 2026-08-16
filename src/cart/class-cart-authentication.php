<?php
/**
 * Autenticación y protección CSRF del carrito.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Cart;

use WP_Error;
use WP_REST_Request;

/**
 * Separa identidad de usuario y sesión sin usar IDs internos como autorización.
 */
final class CartAuthentication {
	/**
	 * Resuelve la identidad aplicable y valida la escritura cuando corresponde.
	 *
	 * @param WP_REST_Request $request Solicitud REST.
	 * @param bool            $write   Si modificará estado.
	 * @return array<string, int|string>|WP_Error
	 */
	public static function resolve( WP_REST_Request $request, bool $write ): array|WP_Error {
		$user_id = get_current_user_id();

		if ( 0 < $user_id ) {
			$nonce = (string) $request->get_header( 'x-wp-nonce' );

			if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return self::forbidden();
			}

			return CartSessionService::authenticated( $user_id );
		}

		$credential = isset( $_COOKIE[ CartSessionService::COOKIE_NAME ] )
			? sanitize_text_field( wp_unslash( (string) $_COOKIE[ CartSessionService::COOKIE_NAME ] ) )
			: '';
		$identity   = CartSessionService::resolve_anonymous( $credential );

		if ( is_wp_error( $identity ) || ! $write ) {
			return $identity;
		}

		if ( ! self::same_origin( $request ) ) {
			return self::forbidden();
		}

		$csrf = trim( (string) $request->get_header( 'x-vicu-csrf' ) );

		if ( '' === $csrf || ! hash_equals( (string) $identity['csrf_token'], $csrf ) ) {
			return self::forbidden();
		}

		return $identity;
	}

	/**
	 * Exige que Origin o Referer coincidan exactamente con el sitio.
	 *
	 * @param WP_REST_Request $request Solicitud anónima.
	 * @return bool
	 */
	public static function same_origin( WP_REST_Request $request ): bool {
		$source = trim( (string) $request->get_header( 'origin' ) );

		if ( '' === $source ) {
			$source = trim( (string) $request->get_header( 'referer' ) );
		}

		$source_parts = wp_parse_url( $source );
		$home_parts   = wp_parse_url( home_url( '/' ) );

		if ( ! is_array( $source_parts ) || ! is_array( $home_parts ) ) {
			return false;
		}

		$source_port = isset( $source_parts['port'] ) ? (int) $source_parts['port'] : self::default_port( (string) ( $source_parts['scheme'] ?? '' ) );
		$home_port   = isset( $home_parts['port'] ) ? (int) $home_parts['port'] : self::default_port( (string) ( $home_parts['scheme'] ?? '' ) );

		return strtolower( (string) ( $source_parts['scheme'] ?? '' ) ) === strtolower( (string) ( $home_parts['scheme'] ?? '' ) ) &&
			strtolower( (string) ( $source_parts['host'] ?? '' ) ) === strtolower( (string) ( $home_parts['host'] ?? '' ) ) &&
			$source_port === $home_port;
	}

	/**
	 * Resuelve el puerto estándar de un esquema conocido.
	 *
	 * @param string $scheme Esquema.
	 * @return int
	 */
	private static function default_port( string $scheme ): int {
		return 'https' === strtolower( $scheme ) ? 443 : 80;
	}

	/**
	 * Error seguro para nonce, CSRF u origen inválidos.
	 *
	 * @return WP_Error
	 */
	private static function forbidden(): WP_Error {
		return new WP_Error(
			'vicu_restaurante_forbidden',
			__( 'La solicitud no está autorizada.', 'vicunav-restaurante' ),
			array( 'status' => 403 )
		);
	}
}
