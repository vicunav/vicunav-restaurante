<?php
/**
 * Migración de prueba que crea una tabla compensable.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Tests;

use Vicu\Restaurante\Migrations\Migration;

/**
 * Permite comprobar rollback de una migración exitosa anterior.
 */
final class CreateProbeTable extends Migration {
	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 2;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_applied(): bool {
		global $wpdb;

		return \Vicu\Restaurante\Schema::table_exists( $wpdb->prefix . 'vicu_rest_probe' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . 'vicu_rest_probe';

		// El identificador se compone con el prefijo efectivo y un sufijo fijo de prueba.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE {$table_name} (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB" );

		return $this->is_applied();
	}

	/**
	 * {@inheritDoc}
	 */
	public function down(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'vicu_rest_probe';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
	}
}
