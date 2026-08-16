<?php
/**
 * Schema transaccional de sesiones y carritos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Migrations;

use Vicu\Restaurante\Schema;

/**
 * Crea almacenamiento vacío, sin sesiones ni carritos de demostración.
 */
final class CreateCartStorage extends Migration {
	/**
	 * Tablas creadas durante este intento.
	 *
	 * @var string[]
	 */
	private array $created_tables = array();

	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 5;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_applied(): bool {
		foreach ( self::tables() as $table_name ) {
			if ( ! Schema::table_exists( $table_name ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::tables() as $table_name ) {
			if ( ! Schema::table_exists( $table_name ) ) {
				$this->created_tables[] = $table_name;
			}
		}

		$charset     = $wpdb->get_charset_collate();
		$sessions    = Schema::cart_sessions_table_name();
		$carts       = Schema::carts_table_name();
		$items       = Schema::cart_items_table_name();
		$idempotency = Schema::idempotency_table_name();

		dbDelta(
			"CREATE TABLE {$sessions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				secret_hash char(64) NOT NULL,
				csrf_hash char(64) NOT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY user_expires (user_id,expires_at),
				KEY expires_at (expires_at)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$carts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				owner_key varchar(191) DEFAULT NULL,
				session_id bigint(20) unsigned DEFAULT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				status varchar(16) NOT NULL DEFAULT 'active',
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				discount_code varchar(64) DEFAULT NULL,
				fulfillment varchar(16) NOT NULL DEFAULT 'pickup',
				delivery_zone_public_id char(36) DEFAULT NULL,
				tip_rate_bps int(10) unsigned NOT NULL DEFAULT 0,
				subtotal_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				totals_json longtext NOT NULL,
				catalog_revision bigint(20) unsigned NOT NULL DEFAULT 1,
				availability_revision bigint(20) unsigned NOT NULL DEFAULT 1,
				pricing_revision bigint(20) unsigned NOT NULL DEFAULT 1,
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY owner_key (owner_key),
				KEY session_status (session_id,status),
				KEY user_status (user_id,status),
				KEY status_expires (status,expires_at)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				cart_id bigint(20) unsigned NOT NULL,
				type varchar(16) NOT NULL,
				source_public_id char(36) DEFAULT NULL,
				quantity int(10) unsigned NOT NULL,
				selection_json longtext NOT NULL,
				snapshot_json longtext NOT NULL,
				unit_price_minor bigint(20) unsigned NOT NULL,
				line_total_minor bigint(20) unsigned NOT NULL,
				merge_hash char(64) DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY cart_order (cart_id,id),
				KEY cart_merge (cart_id,merge_hash)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$idempotency} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scope varchar(191) NOT NULL,
				key_hash char(64) NOT NULL,
				request_hash char(64) NOT NULL,
				status varchar(16) NOT NULL,
				response_code smallint(5) unsigned DEFAULT NULL,
				response_json longtext DEFAULT NULL,
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY scope_key (scope,key_hash),
				KEY expires_at (expires_at)
			) ENGINE=InnoDB {$charset};"
		);

		return $this->is_applied();
	}

	/**
	 * {@inheritDoc}
	 */
	public function down(): void {
		global $wpdb;

		foreach ( array_reverse( $this->created_tables ) as $table_name ) {
			// El identificador pertenece a la lista fija capturada durante up().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		}
	}

	/**
	 * Lista física del schema de esta migración.
	 *
	 * @return string[]
	 */
	private static function tables(): array {
		return array(
			Schema::cart_sessions_table_name(),
			Schema::carts_table_name(),
			Schema::cart_items_table_name(),
			Schema::idempotency_table_name(),
		);
	}
}
