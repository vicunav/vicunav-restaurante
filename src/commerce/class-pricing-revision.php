<?php
/**
 * Revisión compartida de zonas, descuentos y ajustes de totales.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Commerce;

/**
 * Invalida consumidores cuando cambia una regla autoritativa.
 */
final class PricingRevision {
	public const OPTION_NAME = 'vicu_restaurante_pricing_revision';

	/**
	 * Devuelve la revisión confirmada.
	 *
	 * @return int
	 */
	public static function current(): int {
		return max( 1, (int) get_option( self::OPTION_NAME, 1 ) );
	}

	/**
	 * Incrementa dentro de la transacción SQL actual.
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
	 * Incrementa una regla actualizada mediante Options API.
	 *
	 * @return bool
	 */
	public static function bump(): bool {
		global $wpdb;

		$result = self::bump_in_transaction();

		if ( $result ) {
			self::clear_cache();
		}

		return $result;
	}

	/**
	 * Limpia caches después de confirmar.
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		wp_cache_delete( self::OPTION_NAME, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
