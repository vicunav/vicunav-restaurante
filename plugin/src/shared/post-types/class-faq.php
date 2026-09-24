<?php
/**
 * Tipo de contenido compartido para preguntas frecuentes.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Shared\PostTypes;

defined( 'ABSPATH' ) || exit;

use Vicu\Restaurante\Shared\PostType;

/**
 * Registra preguntas frecuentes editables y consultables por REST.
 */
final class Faq extends PostType {
	/**
	 * Devuelve el slug contractual del CPT.
	 *
	 * @return string
	 */
	protected function get_slug(): string {
		return 'vicu_faq';
	}

	/**
	 * Devuelve la configuración funcional del CPT.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_args(): array {
		return array(
			'labels'       => array(
				'name'          => __( 'Preguntas frecuentes', 'vicunav-restaurante' ),
				'singular_name' => __( 'Pregunta frecuente', 'vicunav-restaurante' ),
				'add_new'       => __( 'Añadir nueva', 'vicunav-restaurante' ),
				'add_new_item'  => __( 'Añadir pregunta frecuente', 'vicunav-restaurante' ),
				'edit_item'     => __( 'Editar pregunta frecuente', 'vicunav-restaurante' ),
				'new_item'      => __( 'Nueva pregunta frecuente', 'vicunav-restaurante' ),
				'view_item'     => __( 'Ver pregunta frecuente', 'vicunav-restaurante' ),
				'search_items'  => __( 'Buscar preguntas frecuentes', 'vicunav-restaurante' ),
				'not_found'     => __( 'No se encontraron preguntas frecuentes.', 'vicunav-restaurante' ),
				'menu_name'     => __( 'Preguntas frecuentes', 'vicunav-restaurante' ),
			),
			'public'       => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-editor-help',
			'supports'     => array( 'title', 'editor', 'page-attributes' ),
		);
	}
}
