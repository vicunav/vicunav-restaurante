<?php
/**
 * Revisión compartida de ingredientes y opciones.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Catalog;

/**
 * Mantiene un validador monotónico dentro de las transacciones del catálogo.
 */
final class AvailabilityRevision {
	public const OPTION_NAME = 'vicu_restaurante_availability_revision';

	/**
	 * Devuelve la revisión confirmada.
	 *
	 * @return int
	 */
	public static function current(): int {
		return max( 1, (int) get_option( self::OPTION_NAME, 1 ) );
	}

	/**
	 * Incrementa el option dentro de la transacción actual.
	 *
	 * @return bool
	 */
	public static function bump_in_transaction(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
				self::OPTION_NAME
			)
		);

		return 1 === $updated;
	}

	/**
	 * Limpia caches después de confirmar la transacción.
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		wp_cache_delete( self::OPTION_NAME, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
