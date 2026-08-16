<?php
/**
 * Doble de los ajustes públicos compartidos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Core;

/**
 * Representa la clase pública requerida de core.
 */
final class Settings {
	/**
	 * Registra una pestaña sin renderizar administración en la suite unitaria.
	 *
	 * @param string   $slug            Identificador.
	 * @param string   $label           Etiqueta.
	 * @param callable $render_callback Callback.
	 * @param string   $capability      Capability.
	 * @return void
	 */
	public static function register_tab( string $slug, string $label, callable $render_callback, string $capability = 'manage_options' ): void {
		unset( $slug, $label, $render_callback, $capability );
	}
}
