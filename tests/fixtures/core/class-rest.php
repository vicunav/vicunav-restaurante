<?php
/**
 * Doble de la base pública REST.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Core;

/**
 * Representa la clase pública requerida de core.
 */
final class Rest {
	public const NAMESPACE = 'vicu/v1';

	/**
	 * Delega el registro igual que el contrato público real.
	 *
	 * @param string               $route    Ruta relativa.
	 * @param array<string, mixed> $args     Definición de endpoint.
	 * @param bool                 $override Si reemplaza otra ruta.
	 * @return bool
	 */
	public static function register_route( string $route, array $args, bool $override = false ): bool {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return false;
		}

		return register_rest_route( self::NAMESPACE, '/' . ltrim( $route, '/' ), $args, $override );
	}
}
