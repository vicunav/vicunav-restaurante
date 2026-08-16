<?php
/**
 * Schema de zonas y descuentos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Migrations;

use Vicu\Restaurante\Commerce\PricingRevision;
use Vicu\Restaurante\Schema;

/**
 * Crea reglas vacías sin importar zonas ni códigos del demo.
 */
final class CreateCommerceRules extends Migration {
	/**
	 * Tablas creadas por este intento.
	 *
	 * @var string[]
	 */
	private array $created_tables = array();

	/**
	 * Indica si este intento creó la revisión.
	 *
	 * @var bool
	 */
	private bool $created_option = false;

	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 4;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_applied(): bool {
		return false !== get_option( PricingRevision::OPTION_NAME, false ) &&
			1 <= PricingRevision::current() &&
			Schema::table_exists( Schema::delivery_zones_table_name() ) &&
			Schema::table_exists( Schema::discount_codes_table_name() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( array( Schema::delivery_zones_table_name(), Schema::discount_codes_table_name() ) as $table_name ) {
			if ( ! Schema::table_exists( $table_name ) ) {
				$this->created_tables[] = $table_name;
			}
		}

		$charset_collate = $wpdb->get_charset_collate();
		$zones           = Schema::delivery_zones_table_name();
		$discounts       = Schema::discount_codes_table_name();

		dbDelta(
			"CREATE TABLE {$zones} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				name varchar(191) NOT NULL,
				active tinyint(1) NOT NULL DEFAULT 0,
				fee_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				eta_min_minutes int(10) unsigned NOT NULL DEFAULT 0,
				eta_max_minutes int(10) unsigned NOT NULL DEFAULT 0,
				display_order int(10) unsigned NOT NULL DEFAULT 0,
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY active_order (active,display_order)
			) ENGINE=InnoDB {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$discounts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				code varchar(64) NOT NULL,
				type varchar(16) NOT NULL,
				value bigint(20) unsigned NOT NULL,
				active tinyint(1) NOT NULL DEFAULT 0,
				valid_from datetime DEFAULT NULL,
				valid_until datetime DEFAULT NULL,
				minimum_subtotal_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				max_uses bigint(20) unsigned DEFAULT NULL,
				uses_count bigint(20) unsigned NOT NULL DEFAULT 0,
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY code (code),
				KEY active_validity (active,valid_from,valid_until)
			) ENGINE=InnoDB {$charset_collate};"
		);

		if ( false === get_option( PricingRevision::OPTION_NAME, false ) ) {
			$this->created_option = add_option( PricingRevision::OPTION_NAME, '1', '', false );
		}

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

		if ( $this->created_option ) {
			delete_option( PricingRevision::OPTION_NAME );
		}
	}
}
