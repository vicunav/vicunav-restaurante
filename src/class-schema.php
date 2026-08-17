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
	 * Devuelve la tabla canónica de zonas de entrega.
	 *
	 * @return string
	 */
	public static function delivery_zones_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_delivery_zones';
	}

	/**
	 * Devuelve la tabla canónica de códigos de descuento.
	 *
	 * @return string
	 */
	public static function discount_codes_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_discount_codes';
	}

	/**
	 * Devuelve la tabla de sesiones anónimas de carrito.
	 *
	 * @return string
	 */
	public static function cart_sessions_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_cart_sessions';
	}

	/**
	 * Devuelve la tabla autoritativa de carritos.
	 *
	 * @return string
	 */
	public static function carts_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_carts';
	}

	/**
	 * Devuelve la tabla de líneas de carrito.
	 *
	 * @return string
	 */
	public static function cart_items_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_cart_items';
	}

	/**
	 * Devuelve la tabla de resultados idempotentes del vertical.
	 *
	 * @return string
	 */
	public static function idempotency_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_idempotency';
	}

	/**
	 * Devuelve la tabla autoritativa de pedidos.
	 *
	 * @return string
	 */
	public static function orders_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_orders';
	}

	/**
	 * Devuelve la tabla de snapshots de líneas de pedido.
	 *
	 * @return string
	 */
	public static function order_items_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_order_items';
	}

	/**
	 * Devuelve la tabla append-only de eventos de pedido.
	 *
	 * @return string
	 */
	public static function order_events_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_order_events';
	}

	/**
	 * Devuelve la tabla privada de evidencias manuales.
	 *
	 * @return string
	 */
	public static function payment_evidence_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_payment_evidence';
	}

	/**
	 * Devuelve la autoridad de reservas.
	 *
	 * @return string
	 */
	public static function reservations_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_reservations';
	}

	/**
	 * Devuelve la ocupación agregada por intervalo UTC.
	 *
	 * @return string
	 */
	public static function reservation_occupancy_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_reservation_occupancy';
	}

	/**
	 * Devuelve los eventos append-only de reservas.
	 *
	 * @return string
	 */
	public static function reservation_events_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_reservation_events';
	}

	/**
	 * Devuelve la autoridad de pizzas guardadas por cuenta.
	 *
	 * @return string
	 */
	public static function saved_pizzas_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_rest_saved_pizzas';
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

	/**
	 * Comprueba una columna de una tabla interna conocida.
	 *
	 * @param string $table_name  Tabla completa y confiable.
	 * @param string $column_name Columna fija del schema.
	 * @return bool
	 */
	public static function column_exists( string $table_name, string $column_name ): bool {
		global $wpdb;

		$query = $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, $column_name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return null !== $wpdb->get_row( $query );
	}
}
