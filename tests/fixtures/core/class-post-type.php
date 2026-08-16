<?php
/**
 * Doble de la base pública de tipos de contenido.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Core;

use WP_Error;
use WP_Post_Type;

/**
 * Representa la clase pública requerida de core.
 */
abstract class PostType {
	/**
	 * Devuelve el slug de la subclase.
	 *
	 * @return string
	 */
	abstract protected function get_slug(): string;

	/**
	 * Devuelve los argumentos del CPT.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function get_args(): array;

	/**
	 * Enlaza el registro con init.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Registra el tipo en WordPress real cuando está disponible.
	 *
	 * @return WP_Post_Type|WP_Error|mixed
	 */
	public function register(): mixed {
		return register_post_type( $this->get_slug(), $this->get_args() );
	}
}
