<?php
/**
 * Schema canónico de ingredientes y opciones de pizza.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Migrations;

use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Schema;

/**
 * Crea tablas vacías y la revisión inicial sin contenido de demostración.
 */
final class CreateIngredientCatalog extends Migration {
	/**
	 * Tablas creadas exclusivamente por este intento.
	 *
	 * @var string[]
	 */
	private array $created_tables = array();

	/**
	 * Indica si este intento creó el option.
	 *
	 * @var bool
	 */
	private bool $created_option = false;

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
		$revision = get_option( AvailabilityRevision::OPTION_NAME, false );

		return false !== $revision &&
			1 <= (int) $revision &&
			Schema::table_exists( Schema::ingredients_table_name() ) &&
			Schema::table_exists( Schema::menu_ingredients_table_name() ) &&
			Schema::table_exists( Schema::pizza_options_table_name() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tables = array(
			Schema::ingredients_table_name(),
			Schema::menu_ingredients_table_name(),
			Schema::pizza_options_table_name(),
		);

		foreach ( $tables as $table_name ) {
			if ( ! Schema::table_exists( $table_name ) ) {
				$this->created_tables[] = $table_name;
			}
		}

		$charset_collate = $wpdb->get_charset_collate();
		$ingredients     = Schema::ingredients_table_name();
		$relations       = Schema::menu_ingredients_table_name();
		$options         = Schema::pizza_options_table_name();

		dbDelta(
			"CREATE TABLE {$ingredients} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				name varchar(191) NOT NULL,
				category varchar(32) NOT NULL,
				price_modifier_minor bigint(20) NOT NULL DEFAULT 0,
				available tinyint(1) NOT NULL DEFAULT 0,
				allergens longtext NOT NULL,
				dietary_tags longtext NOT NULL,
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY category_available (category,available)
			) ENGINE=InnoDB {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$relations} (
				menu_item_id bigint(20) unsigned NOT NULL,
				ingredient_id bigint(20) unsigned NOT NULL,
				role varchar(16) NOT NULL,
				display_order int(10) unsigned NOT NULL DEFAULT 0,
				substitution_ingredient_id bigint(20) unsigned DEFAULT NULL,
				PRIMARY KEY  (menu_item_id,ingredient_id),
				KEY ingredient_id (ingredient_id),
				KEY substitution_ingredient_id (substitution_ingredient_id)
			) ENGINE=InnoDB {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$options} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				type varchar(16) NOT NULL,
				name varchar(191) NOT NULL,
				price_modifier_minor bigint(20) NOT NULL DEFAULT 0,
				available tinyint(1) NOT NULL DEFAULT 0,
				display_order int(10) unsigned NOT NULL DEFAULT 0,
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY type_available_order (type,available,display_order)
			) ENGINE=InnoDB {$charset_collate};"
		);

		if ( false === get_option( AvailabilityRevision::OPTION_NAME, false ) ) {
			$this->created_option = add_option( AvailabilityRevision::OPTION_NAME, '1', '', false );
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
			delete_option( AvailabilityRevision::OPTION_NAME );
		}
	}
}
