<?php
/**
 * Tipo de contenido compartido para testimonios.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Shared\PostTypes;

defined( 'ABSPATH' ) || exit;

use Vicu\Restaurante\Shared\PostType;

/**
 * Registra testimonios editables y consultables por REST.
 */
final class Testimonial extends PostType {
	/**
	 * Devuelve el slug contractual del CPT.
	 *
	 * @return string
	 */
	protected function get_slug(): string {
		return 'vicu_testimonial';
	}

	/**
	 * Devuelve la configuración funcional del CPT.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_args(): array {
		return array(
			'labels'       => array(
				'name'          => __( 'Testimonios', 'vicunav-restaurante' ),
				'singular_name' => __( 'Testimonio', 'vicunav-restaurante' ),
				'add_new'       => __( 'Añadir nuevo', 'vicunav-restaurante' ),
				'add_new_item'  => __( 'Añadir testimonio', 'vicunav-restaurante' ),
				'edit_item'     => __( 'Editar testimonio', 'vicunav-restaurante' ),
				'new_item'      => __( 'Nuevo testimonio', 'vicunav-restaurante' ),
				'view_item'     => __( 'Ver testimonio', 'vicunav-restaurante' ),
				'search_items'  => __( 'Buscar testimonios', 'vicunav-restaurante' ),
				'not_found'     => __( 'No se encontraron testimonios.', 'vicunav-restaurante' ),
				'menu_name'     => __( 'Testimonios', 'vicunav-restaurante' ),
			),
			'public'       => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-format-quote',
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
		);
	}
}
