<?php
/**
 * Base para las rutas REST del plugin.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * Registra rutas bajo el namespace versionado y exige autorización explícita.
 */
final class Rest {
	/**
	 * Namespace REST estable de las rutas del plugin.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'vicu/v1';

	/**
	 * Registra una ruta solo durante el hook correcto y con permiso explícito.
	 *
	 * @param string               $route    Ruta relativa al namespace.
	 * @param array<string, mixed> $args     Definición aceptada por WordPress.
	 * @param bool                 $override Si reemplaza una ruta existente.
	 * @return bool
	 */
	public static function register_route( string $route, array $args, bool $override = false ): bool {
		if ( ! doing_action( 'rest_api_init' ) || ! self::has_permission_callbacks( $args ) ) {
			return false;
		}

		$normalized_route = '/' . ltrim( $route, '/' );

		return register_rest_route( self::NAMESPACE, $normalized_route, $args, $override );
	}

	/**
	 * Comprueba definiciones simples o múltiples antes de registrar alguna.
	 *
	 * @param array<string, mixed> $args Definición de endpoints.
	 * @return bool
	 */
	private static function has_permission_callbacks( array $args ): bool {
		if ( array_key_exists( 'callback', $args ) || array_key_exists( 'methods', $args ) ) {
			return isset( $args['permission_callback'] ) && is_callable( $args['permission_callback'] );
		}

		$has_endpoint = false;

		foreach ( $args as $key => $endpoint ) {
			if ( ! is_int( $key ) ) {
				continue;
			}

			$has_endpoint = true;

			if (
				! is_array( $endpoint ) ||
				! isset( $endpoint['permission_callback'] ) ||
				! is_callable( $endpoint['permission_callback'] )
			) {
				return false;
			}
		}

		return $has_endpoint;
	}
}
