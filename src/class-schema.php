<?php
/**
 * Nombres y comprobaciones del schema interno.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante;

/**
 * Resuelve tablas con el prefijo efectivo del sitio.
 *
 * @internal
 */
final class Schema {
	/**
	 * Devuelve el nombre del ledger de migraciones.
	 *
	 * @return string
	 */
	public static function migration_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_migrations';
	}

	/**
	 * Devuelve la tabla canónica de ingredientes.
	 *
	 * @return string
	 */
	public static function ingredients_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_ingredients';
	}

	/**
	 * Devuelve la tabla de relaciones entre menú e ingredientes.
	 *
	 * @return string
	 */
	public static function menu_ingredients_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_menu_ingredients';
	}

	/**
	 * Devuelve la tabla canónica de opciones de pizza.
	 *
	 * @return string
	 */
	public static function pizza_options_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_pizza_options';
	}

	/**
	 * Comprueba la existencia de una tabla interna conocida.
	 *
	 * @param string $table_name Nombre completo y confiable de la tabla.
	 * @return bool
	 */
	public static function table_exists( string $table_name ): bool {
		global $wpdb;

		$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return $table_name === $wpdb->get_var( $query );
	}
}
