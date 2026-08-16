<?php
/**
 * Sincronización reconstruible de la proyección `vicu_order`.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Order;

use WP_Query;

/**
 * Nunca participa en autorización, pricing o transiciones.
 */
final class OrderProjection {
	/**
	 * Crea o actualiza una proyección desde la tabla autoritativa.
	 *
	 * @param string $public_id UUID del pedido.
	 * @return bool
	 */
	public static function sync( string $public_id ): bool {
		$order = OrderService::admin_detail( $public_id );

		if ( null === $order ) {
			return false;
		}

		$post_id = self::post_id( $public_id );
		$postarr = array(
			'ID'           => $post_id,
			'post_type'    => OrderPostType::POST_TYPE,
			'post_status'  => 'private',
			'post_title'   => $order['order_number'],
			'post_content' => '',
			'post_excerpt' => '',
		);
		$result  = 0 < $post_id ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );

		if ( is_wp_error( $result ) || 1 > (int) $result ) {
			OrderService::set_projection_status( $public_id, 'pending' );
			return false;
		}

		$post_id = (int) $result;
		update_post_meta( $post_id, OrderPostType::META_PUBLIC_ID, $public_id );
		update_post_meta( $post_id, '_vicu_rest_order_status', $order['status'] );
		update_post_meta( $post_id, '_vicu_rest_order_revision', $order['revision'] );
		update_post_meta( $post_id, '_vicu_rest_order_total_minor', $order['totals']['total'] );
		update_post_meta( $post_id, '_vicu_rest_order_currency', $order['currency'] );

		return OrderService::set_projection_status( $public_id, 'synced' );
	}

	/**
	 * Reconstruye el lote acotado visible en administración.
	 *
	 * @return array{synced: int, failed: int}
	 */
	public static function rebuild(): array {
		$result = array(
			'synced' => 0,
			'failed' => 0,
		);

		foreach ( OrderService::admin_list( 100 ) as $order ) {
			$key = self::sync( $order['public_id'] ) ? 'synced' : 'failed';
			++$result[ $key ];
		}

		return $result;
	}

	/**
	 * Busca la proyección exclusivamente por el UUID propietario.
	 *
	 * @param string $public_id UUID.
	 * @return int
	 */
	private static function post_id( string $public_id ): int {
		// La búsqueda exacta de una proyección pequeña se usa solo fuera del camino público.
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$query = new WP_Query(
			array(
				'post_type'      => OrderPostType::POST_TYPE,
				'post_status'    => array( 'private', 'draft', 'publish' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_key'       => OrderPostType::META_PUBLIC_ID,
				'meta_value'     => $public_id,
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		return isset( $query->posts[0] ) ? (int) $query->posts[0] : 0;
	}
}
