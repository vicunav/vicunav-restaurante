<?php
/**
 * Schema transaccional de pedidos y eventos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Migrations;

use Vicu\Restaurante\Schema;

/**
 * Crea autoridades vacías sin pedidos de demostración.
 */
final class CreateOrderStorage extends Migration {
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
		return 6;
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

		$charset = $wpdb->get_charset_collate();
		$orders  = Schema::orders_table_name();
		$items   = Schema::order_items_table_name();
		$events  = Schema::order_events_table_name();

		dbDelta(
			"CREATE TABLE {$orders} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				order_number varchar(32) NOT NULL,
				cart_public_id char(36) NOT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				access_token_hash char(64) NOT NULL,
				status varchar(32) NOT NULL,
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				fulfillment varchar(16) NOT NULL,
				customer_name varchar(100) NOT NULL,
				customer_email varchar(191) DEFAULT NULL,
				customer_phone varchar(32) NOT NULL,
				delivery_address text DEFAULT NULL,
				delivery_instructions text DEFAULT NULL,
				customer_note text DEFAULT NULL,
				currency char(3) NOT NULL,
				subtotal_minor bigint(20) unsigned NOT NULL,
				discount_total bigint(20) unsigned NOT NULL,
				tax_total bigint(20) unsigned NOT NULL,
				tip_total bigint(20) unsigned NOT NULL,
				delivery_total bigint(20) unsigned NOT NULL,
				total_minor bigint(20) unsigned NOT NULL,
				totals_json longtext NOT NULL,
				payment_expires_at datetime NOT NULL,
				payment_sync_status varchar(16) NOT NULL DEFAULT 'pending',
				payment_request_id varchar(191) DEFAULT NULL,
				payment_revision bigint(20) unsigned NOT NULL DEFAULT 0,
				projection_status varchar(16) NOT NULL DEFAULT 'pending',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY order_number (order_number),
				UNIQUE KEY cart_public_id (cart_public_id),
				KEY user_created (user_id,created_at),
				KEY status_created (status,created_at),
				KEY payment_sync (payment_sync_status,status)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				line_public_id char(36) NOT NULL,
				type varchar(16) NOT NULL,
				quantity int(10) unsigned NOT NULL,
				selection_json longtext NOT NULL,
				snapshot_json longtext NOT NULL,
				unit_price_minor bigint(20) unsigned NOT NULL,
				line_total_minor bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY order_line (order_id,line_public_id),
				KEY order_sequence (order_id,id)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				from_status varchar(32) DEFAULT NULL,
				to_status varchar(32) NOT NULL,
				actor_type varchar(16) NOT NULL,
				actor_id bigint(20) unsigned DEFAULT NULL,
				reason varchar(500) DEFAULT NULL,
				metadata_json longtext NOT NULL,
				revision bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY order_revision (order_id,revision),
				KEY order_sequence (order_id,id)
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
	 * Lista física de REST-02I.
	 *
	 * @return string[]
	 */
	private static function tables(): array {
		return array(
			Schema::orders_table_name(),
			Schema::order_items_table_name(),
			Schema::order_events_table_name(),
		);
	}
}
