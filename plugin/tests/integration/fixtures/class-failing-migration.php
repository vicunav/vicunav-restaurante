<?php
/**
 * Migración fallida para verificar compensación.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Tests;

use Vicu\Restaurante\Migrations\Migration;

/**
 * Crea un recurso parcial y comunica fallo deliberadamente.
 */
final class FailingMigration extends Migration {
	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 3;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_applied(): bool {
		global $wpdb;

		return \Vicu\Restaurante\Schema::table_exists( $wpdb->prefix . 'vicu_rest_failed' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . 'vicu_rest_failed';

		// El identificador se compone con el prefijo efectivo y un sufijo fijo de prueba.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE {$table_name} (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB" );

		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function down(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'vicu_rest_failed';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
	}
}
