<?php
/**
 * Proyección reconstruible de reservas para wp-admin.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Reservation;

use WP_Query;

/**
 * Copia solo campos de listado; capacidad y estados permanecen en tablas propias.
 */
final class ReservationProjection {
	/**
	 * Sincroniza una reserva desde la autoridad.
	 *
	 * @param string $public_id UUID de reserva.
	 * @return bool
	 */
	public static function sync( string $public_id ): bool {
		$reservation = ReservationService::admin_detail( $public_id );

		if ( null === $reservation ) {
			return false;
		}

		$post_id = self::post_id( $public_id );
		$result  = 0 < $post_id
			? wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $reservation['confirmation_code'],
				),
				true
			)
			: wp_insert_post(
				array(
					'post_type'   => ReservationPostType::POST_TYPE,
					'post_status' => 'private',
					'post_title'  => $reservation['confirmation_code'],
				),
				true
			);

		if ( is_wp_error( $result ) || 1 > (int) $result ) {
			return false;
		}

		$post_id = (int) $result;
		update_post_meta( $post_id, ReservationPostType::META_PUBLIC_ID, $public_id );
		update_post_meta( $post_id, '_vicu_rest_reservation_status', $reservation['status'] );
		update_post_meta( $post_id, '_vicu_rest_reservation_revision', $reservation['revision'] );
		update_post_meta( $post_id, '_vicu_rest_reservation_start', $reservation['starts_at'] );

		return true;
	}

	/**
	 * Reconstruye el lote operativo visible.
	 *
	 * @return array{synced: int, failed: int}
	 */
	public static function rebuild(): array {
		$result = array(
			'synced' => 0,
			'failed' => 0,
		);

		foreach ( ReservationService::admin_list() as $reservation ) {
			$key = self::sync( $reservation['public_id'] ) ? 'synced' : 'failed';
			++$result[ $key ];
		}

		return $result;
	}

	/**
	 * Busca una proyección por UUID fuera del camino público.
	 *
	 * @param string $public_id UUID de reserva.
	 * @return int
	 */
	private static function post_id( string $public_id ): int {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$query = new WP_Query(
			array(
				'post_type'      => ReservationPostType::POST_TYPE,
				'post_status'    => array( 'private', 'draft', 'publish' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_key'       => ReservationPostType::META_PUBLIC_ID,
				'meta_value'     => $public_id,
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		return isset( $query->posts[0] ) ? (int) $query->posts[0] : 0;
	}
}
