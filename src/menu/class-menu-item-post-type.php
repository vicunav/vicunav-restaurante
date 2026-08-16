<?php
/**
 * Tipo de contenido editorial del menú.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Menu;

use Vicu\Core\PostType;
use Vicu\Restaurante\Rest\MenuItemController;

/**
 * Mantiene copy y media en WordPress sin delegar reglas operativas al theme.
 */
final class MenuItemPostType extends PostType {
	public const POST_TYPE = 'vicu_menu_item';

	/**
	 * {@inheritDoc}
	 */
	protected function get_slug(): string {
		return self::POST_TYPE;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_args(): array {
		$capability = 'manage_vicu_restaurant_catalog';

		return array(
			'labels'                => array(
				'name'               => __( 'Menú', 'vicunav-restaurante' ),
				'singular_name'      => __( 'Elemento de menú', 'vicunav-restaurante' ),
				'add_new_item'       => __( 'Añadir elemento de menú', 'vicunav-restaurante' ),
				'edit_item'          => __( 'Editar elemento de menú', 'vicunav-restaurante' ),
				'new_item'           => __( 'Nuevo elemento de menú', 'vicunav-restaurante' ),
				'view_item'          => __( 'Ver elemento de menú', 'vicunav-restaurante' ),
				'search_items'       => __( 'Buscar en el menú', 'vicunav-restaurante' ),
				'not_found'          => __( 'No se encontraron elementos.', 'vicunav-restaurante' ),
				'not_found_in_trash' => __( 'No hay elementos en la papelera.', 'vicunav-restaurante' ),
			),
			'public'                => true,
			'show_ui'               => true,
			'show_in_menu'          => 'vicunav',
			'show_in_rest'          => true,
			'rest_base'             => 'restaurant-menu-items',
			'rest_controller_class' => MenuItemController::class,
			'has_archive'           => false,
			'rewrite'               => false,
			'query_var'             => false,
			'exclude_from_search'   => true,
			'show_in_nav_menus'     => false,
			'menu_icon'             => 'dashicons-food',
			'supports'              => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions' ),
			'capabilities'          => array(
				'edit_post'              => $capability,
				'read_post'              => $capability,
				'delete_post'            => $capability,
				'edit_posts'             => $capability,
				'edit_others_posts'      => $capability,
				'delete_posts'           => $capability,
				'publish_posts'          => $capability,
				'read_private_posts'     => $capability,
				'create_posts'           => $capability,
				'delete_private_posts'   => $capability,
				'delete_published_posts' => $capability,
				'delete_others_posts'    => $capability,
				'edit_private_posts'     => $capability,
				'edit_published_posts'   => $capability,
			),
			'map_meta_cap'          => false,
			'delete_with_user'      => false,
		);
	}
}
