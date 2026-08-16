<?php
/**
 * Migración inicial del ledger interno.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Migrations;

use Vicu\Restaurante\Schema;

/**
 * Crea la única tabla fundacional de REST-02C.
 *
 * @internal
 */
final class CreateMigrationLedger extends Migration {
	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 1;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_applied(): bool {
		return Schema::table_exists( Schema::migration_table_name() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = Schema::migration_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			version bigint(20) unsigned NOT NULL,
			applied_at datetime NOT NULL,
			PRIMARY KEY  (version),
			KEY applied_at (applied_at)
		) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql );

		return $this->is_applied();
	}

	/**
	 * {@inheritDoc}
	 */
	public function down(): void {
		global $wpdb;

		$table_name = Schema::migration_table_name();

		// El identificador proviene exclusivamente del prefijo de WordPress y un sufijo fijo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
	}
}
