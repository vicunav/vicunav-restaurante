<?php
/**
 * Base compartida para tipos de contenido de WordPress.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Shared;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_Post_Type;

/**
 * Registra un tipo de contenido mediante una subclase explícita.
 */
abstract class PostType {
	/**
	 * Indica si la instancia ya enlazó su callback con WordPress.
	 *
	 * @var bool
	 */
	private bool $hooks_registered = false;

	/**
	 * Devuelve el slug estable del tipo de contenido.
	 *
	 * @return string
	 */
	abstract protected function get_slug(): string;

	/**
	 * Devuelve los argumentos para register_post_type().
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function get_args(): array;

	/**
	 * Enlaza el registro con init una sola vez por instancia.
	 *
	 * @return void
	 */
	final public function register_hooks(): void {
		if ( $this->hooks_registered ) {
			return;
		}

		add_action( 'init', array( $this, 'register' ) );
		$this->hooks_registered = true;
	}

	/**
	 * Valida y registra el tipo de contenido.
	 *
	 * @return WP_Post_Type|WP_Error Tipo registrado o error de validación.
	 */
	final public function register(): WP_Post_Type|WP_Error {
		$slug = $this->get_slug();

		if ( ! self::is_valid_slug( $slug ) ) {
			return new WP_Error(
				'vicu_core_invalid_post_type',
				__( 'El slug del tipo de contenido no es válido.', 'vicunav-restaurante' ),
				array( 'slug' => $slug )
			);
		}

		return register_post_type( $slug, $this->get_args() );
	}

	/**
	 * Comprueba las reglas de nombres de CPT.
	 *
	 * @param string $slug Slug que se comprobará.
	 * @return bool
	 */
	private static function is_valid_slug( string $slug ): bool {
		return strlen( $slug ) <= 20 && 1 === preg_match( '/^vicu_[a-z0-9_]+$/', $slug );
	}
}
