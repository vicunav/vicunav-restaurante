<?php
/**
 * Schema propietario de pizzas guardadas.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Migrations;

use Vicu\Restaurante\Schema;

/**
 * Crea una autoridad vacía sin configuraciones ni identidad Bonasera.
 */
final class CreateSavedPizzaStorage extends Migration {
	/**
	 * Indica si la tabla se puede compensar.
	 *
	 * @var bool
	 */
	private bool $created = false;

	/** {@inheritDoc} */
	public function version(): int {
		return 9;
	}

	/** {@inheritDoc} */
	public function is_applied(): bool {
		return Schema::table_exists( Schema::saved_pizzas_table_name() );
	}

	/** {@inheritDoc} */
	public function up(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table         = Schema::saved_pizzas_table_name();
		$this->created = ! Schema::table_exists( $table );
		$charset       = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				name varchar(100) NOT NULL,
				configuration_version int(10) unsigned NOT NULL,
				configuration_json longtext NOT NULL,
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				share_token_hash char(64) DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY share_token_hash (share_token_hash),
				KEY user_updated (user_id,updated_at)
			) ENGINE=InnoDB {$charset};"
		);

		return $this->is_applied();
	}

	/** {@inheritDoc} */
	public function down(): void {
		global $wpdb;

		if ( ! $this->created ) {
			return;
		}

		$table = Schema::saved_pizzas_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
