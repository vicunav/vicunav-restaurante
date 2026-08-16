<?php
/**
 * Revisión monotónica del catálogo público.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Menu;

/**
 * Invalida respuestas estables una sola vez por solicitud de escritura.
 */
final class CatalogRevision {
	public const OPTION_NAME = 'vicu_restaurante_menu_revision';

	/**
	 * Evita incrementos duplicados causados por los hooks de una misma escritura.
	 *
	 * @var bool
	 */
	private static bool $bumped = false;

	/**
	 * Devuelve una revisión válida incluso antes de la primera activación.
	 *
	 * @return int
	 */
	public static function current(): int {
		return max( 1, (int) get_option( self::OPTION_NAME, 1 ) );
	}

	/**
	 * Incrementa de forma atómica la revisión persistida.
	 *
	 * @return int
	 */
	public static function bump(): int {
		global $wpdb;

		if ( self::$bumped ) {
			return self::current();
		}

		self::$bumped = true;

		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, '1', '', false );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
				self::OPTION_NAME
			)
		);

		if ( false === $updated || 0 === $updated ) {
			update_option( self::OPTION_NAME, (string) ( self::current() + 1 ), false );
		}

		wp_cache_delete( self::OPTION_NAME, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		return self::current();
	}

	/**
	 * Restablece el límite por solicitud para pruebas y procesos por lotes explícitos.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public static function reset_request(): void {
		self::$bumped = false;
	}
}
