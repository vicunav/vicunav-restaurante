<?php
/**
 * Proyección administrativa privada de reservas.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Reservation;

use Vicu\Core\PostType;

/**
 * Expone el listado operativo sin convertir WordPress posts en autoridad.
 */
final class ReservationPostType extends PostType {
	public const POST_TYPE      = 'vicu_reservation';
	public const META_PUBLIC_ID = '_vicu_rest_reservation_public_id';

	/** {@inheritDoc} */
	protected function get_slug(): string {
		return self::POST_TYPE;
	}

	/** {@inheritDoc} */
	protected function get_args(): array {
		return array(
			'labels'              => array(
				'name'          => __( 'Reservas', 'vicunav-restaurante' ),
				'singular_name' => __( 'Reserva', 'vicunav-restaurante' ),
				'edit_item'     => __( 'Detalle de la reserva', 'vicunav-restaurante' ),
				'search_items'  => __( 'Buscar reservas', 'vicunav-restaurante' ),
				'not_found'     => __( 'No se encontraron reservas.', 'vicunav-restaurante' ),
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
				'edit_post'              => 'manage_vicu_restaurant_reservations',
				'read_post'              => 'manage_vicu_restaurant_reservations',
				'delete_post'            => 'do_not_allow',
				'edit_posts'             => 'manage_vicu_restaurant_reservations',
				'edit_others_posts'      => 'manage_vicu_restaurant_reservations',
				'delete_posts'           => 'do_not_allow',
				'publish_posts'          => 'do_not_allow',
				'read_private_posts'     => 'manage_vicu_restaurant_reservations',
				'create_posts'           => 'do_not_allow',
				'delete_private_posts'   => 'do_not_allow',
				'delete_published_posts' => 'do_not_allow',
				'delete_others_posts'    => 'do_not_allow',
				'edit_private_posts'     => 'manage_vicu_restaurant_reservations',
				'edit_published_posts'   => 'manage_vicu_restaurant_reservations',
			),
			'map_meta_cap'        => false,
			'delete_with_user'    => false,
		);
	}
}
