<?php
/**
 * Proyección administrativa privada de pedidos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Order;

use Vicu\Core\PostType;

/**
 * Hace visible la proyección sin convertirla en autoridad editable.
 */
final class OrderPostType extends PostType {
	public const POST_TYPE      = 'vicu_order';
	public const META_PUBLIC_ID = '_vicu_rest_order_public_id';

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
		return array(
			'labels'              => array(
				'name'          => __( 'Pedidos', 'vicunav-restaurante' ),
				'singular_name' => __( 'Pedido', 'vicunav-restaurante' ),
				'edit_item'     => __( 'Detalle del pedido', 'vicunav-restaurante' ),
				'search_items'  => __( 'Buscar pedidos', 'vicunav-restaurante' ),
				'not_found'     => __( 'No se encontraron pedidos.', 'vicunav-restaurante' ),
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'vicunav',
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array(),
			'capabilities'        => array(
				'edit_post'              => 'view_vicu_restaurant_orders',
				'read_post'              => 'view_vicu_restaurant_orders',
				'delete_post'            => 'do_not_allow',
				'edit_posts'             => 'view_vicu_restaurant_orders',
				'edit_others_posts'      => 'view_vicu_restaurant_orders',
				'delete_posts'           => 'do_not_allow',
				'publish_posts'          => 'do_not_allow',
				'read_private_posts'     => 'view_vicu_restaurant_orders',
				'create_posts'           => 'do_not_allow',
				'delete_private_posts'   => 'do_not_allow',
				'delete_published_posts' => 'do_not_allow',
				'delete_others_posts'    => 'do_not_allow',
				'edit_private_posts'     => 'view_vicu_restaurant_orders',
				'edit_published_posts'   => 'view_vicu_restaurant_orders',
			),
			'map_meta_cap'        => false,
			'delete_with_user'    => false,
		);
	}
}
