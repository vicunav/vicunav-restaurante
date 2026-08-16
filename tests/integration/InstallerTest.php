<?php
/**
 * Pruebas de instalación con WordPress y MySQL reales.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Capabilities;
use Vicu\Restaurante\Installer;
use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Commerce\PricingRevision;
use Vicu\Restaurante\Menu\CatalogRevision;
use Vicu\Restaurante\Migrations\CreateMigrationLedger;
use Vicu\Restaurante\Migrations\InitializeMenuCatalog;
use Vicu\Restaurante\Migrations\CreateIngredientCatalog;
use Vicu\Restaurante\Migrations\CreateCommerceRules;
use Vicu\Restaurante\Migrations\CreateCartStorage;
use Vicu\Restaurante\Schema;
use Vicu\Restaurante\Tests\CreateProbeTable;
use Vicu\Restaurante\Tests\FailingMigration;

require_once __DIR__ . '/fixtures/class-create-probe-table.php';
require_once __DIR__ . '/fixtures/class-failing-migration.php';

/**
 * Verifica activación, upgrade, rollback y prefijos.
 */
final class InstallerTest extends WP_UnitTestCase {
	/**
	 * Limpia exclusivamente el schema fundacional antes de cada caso.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->drop_test_tables();
		delete_option( 'vicu_restaurante_db_version' );
		delete_option( CatalogRevision::OPTION_NAME );
		delete_option( AvailabilityRevision::OPTION_NAME );
		delete_option( PricingRevision::OPTION_NAME );
		CatalogRevision::reset_request();
		$this->remove_restaurant_capabilities();
	}

	/**
	 * Retira los artefactos propios después de cada caso.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->drop_test_tables();
		delete_option( 'vicu_restaurante_db_version' );
		delete_option( CatalogRevision::OPTION_NAME );
		delete_option( AvailabilityRevision::OPTION_NAME );
		delete_option( PricingRevision::OPTION_NAME );
		CatalogRevision::reset_request();
		$this->remove_restaurant_capabilities();
		parent::tearDown();
	}

	/**
	 * La activación crea un ledger InnoDB y concede capabilities solo al administrador.
	 *
	 * @return void
	 */
	public function test_activation_installs_schema_and_capabilities(): void {
		global $wpdb;
		\Vicu\Restaurante\activate();

		$table_name = Schema::migration_table_name();

		$this->assertTrue( Schema::table_exists( $table_name ) );
		$this->assertSame( '6', get_option( 'vicu_restaurante_db_version' ) );
		$this->assertSame( 6, Installer::current_version() );
		$this->assertSame( '1', get_option( CatalogRevision::OPTION_NAME ) );
		$this->assertSame( '1', get_option( AvailabilityRevision::OPTION_NAME ) );
		$this->assertTrue( Schema::table_exists( Schema::ingredients_table_name() ) );
		$this->assertTrue( Schema::table_exists( Schema::menu_ingredients_table_name() ) );
		$this->assertTrue( Schema::table_exists( Schema::pizza_options_table_name() ) );
		$this->assertSame( '1', get_option( PricingRevision::OPTION_NAME ) );
		$this->assertTrue( Schema::table_exists( Schema::delivery_zones_table_name() ) );
		$this->assertTrue( Schema::table_exists( Schema::discount_codes_table_name() ) );
		$this->assertTrue( Schema::table_exists( Schema::cart_sessions_table_name() ) );
		$this->assertTrue( Schema::table_exists( Schema::carts_table_name() ) );
		$this->assertTrue( Schema::table_exists( Schema::cart_items_table_name() ) );
		$this->assertTrue( Schema::table_exists( Schema::idempotency_table_name() ) );
		$this->assertTrue( Schema::table_exists( Schema::orders_table_name() ) );
		$this->assertTrue( Schema::table_exists( Schema::order_items_table_name() ) );
		$this->assertTrue( Schema::table_exists( Schema::order_events_table_name() ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table_name
			)
		);

		$this->assertSame( 'InnoDB', $engine );

		$administrator = get_role( 'administrator' );
		$editor        = get_role( 'editor' );

		foreach ( Capabilities::all() as $capability ) {
			$this->assertTrue( $administrator->has_cap( $capability ) );
			$this->assertFalse( $editor->has_cap( $capability ) );
		}
	}

	/**
	 * Repetir instalación o activación no duplica la versión aplicada.
	 *
	 * @return void
	 */
	public function test_reactivation_is_idempotent(): void {
		global $wpdb;

		\Vicu\Restaurante\activate();
		\Vicu\Restaurante\activate();
		$this->assertTrue( Installer::maybe_upgrade() );

		$table_name = Schema::migration_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		$this->assertSame( 6, $count );
		$this->assertSame( 6, Installer::current_version() );
	}

	/**
	 * Un option obsoleto se repara desde el ledger sin recrear datos.
	 *
	 * @return void
	 */
	public function test_upgrade_repairs_stale_version_option(): void {
		$this->assertTrue( Installer::install() );
		update_option( 'vicu_restaurante_db_version', '0', false );

		$this->assertSame( 0, Installer::current_version() );
		$this->assertTrue( Installer::maybe_upgrade() );
		$this->assertSame( '6', get_option( 'vicu_restaurante_db_version' ) );
		$this->assertSame( 6, Installer::current_version() );
	}

	/**
	 * Una instalación 0.3.0 aplica solo la migración nueva del catálogo.
	 *
	 * @return void
	 */
	public function test_upgrade_from_schema_one_initializes_menu_revision(): void {
		$this->assertTrue( Installer::install( array( new CreateMigrationLedger() ) ) );
		$this->assertSame( 1, Installer::current_version() );
		$this->assertFalse( get_option( CatalogRevision::OPTION_NAME, false ) );

		$this->assertTrue( Installer::install() );
		$this->assertSame( 6, Installer::current_version() );
		$this->assertSame( '1', get_option( CatalogRevision::OPTION_NAME ) );
		$this->assertSame( '1', get_option( AvailabilityRevision::OPTION_NAME ) );
	}

	/**
	 * Una instalación 0.4.0 conserva el menú y añade solo el catálogo operativo.
	 *
	 * @return void
	 */
	public function test_upgrade_from_schema_two_creates_ingredient_catalog(): void {
		$this->assertTrue(
			Installer::install(
				array(
					new CreateMigrationLedger(),
					new InitializeMenuCatalog(),
				)
			)
		);
		$this->assertSame( 2, Installer::current_version() );
		$this->assertSame( '1', get_option( CatalogRevision::OPTION_NAME ) );
		$this->assertFalse( get_option( AvailabilityRevision::OPTION_NAME, false ) );

		$this->assertTrue( Installer::install() );
		$this->assertSame( 6, Installer::current_version() );
		$this->assertSame( '1', get_option( CatalogRevision::OPTION_NAME ) );
		$this->assertSame( '1', get_option( AvailabilityRevision::OPTION_NAME ) );
		$this->assertTrue( Schema::table_exists( Schema::ingredients_table_name() ) );
		$this->assertTrue( Schema::table_exists( Schema::menu_ingredients_table_name() ) );
		$this->assertTrue( Schema::table_exists( Schema::pizza_options_table_name() ) );
	}

	/**
	 * Una instalación 0.6.0 añade únicamente reglas de comercio vacías.
	 *
	 * @return void
	 */
	public function test_upgrade_from_schema_three_creates_commerce_rules(): void {
		$this->assertTrue(
			Installer::install(
				array(
					new CreateMigrationLedger(),
					new InitializeMenuCatalog(),
					new CreateIngredientCatalog(),
				)
			)
		);
		$this->assertSame( 3, Installer::current_version() );
		$this->assertFalse( get_option( PricingRevision::OPTION_NAME, false ) );

		$this->assertTrue( Installer::install() );
		$this->assertSame( 6, Installer::current_version() );
		$this->assertSame( '1', get_option( PricingRevision::OPTION_NAME ) );
		$this->assertTrue( Schema::table_exists( Schema::delivery_zones_table_name() ) );
		$this->assertTrue( Schema::table_exists( Schema::discount_codes_table_name() ) );
	}

	/**
	 * Una instalación 0.7.0 añade solo almacenamiento vacío de carritos.
	 *
	 * @return void
	 */
	public function test_upgrade_from_schema_four_creates_cart_storage(): void {
		$this->assertTrue(
			Installer::install(
				array(
					new CreateMigrationLedger(),
					new InitializeMenuCatalog(),
					new CreateIngredientCatalog(),
					new CreateCommerceRules(),
				)
			)
		);
		$this->assertSame( 4, Installer::current_version() );
		$this->assertFalse( Schema::table_exists( Schema::carts_table_name() ) );

		$this->assertTrue( Installer::install() );
		$this->assertSame( 6, Installer::current_version() );

		foreach ( array( Schema::cart_sessions_table_name(), Schema::carts_table_name(), Schema::cart_items_table_name(), Schema::idempotency_table_name() ) as $table_name ) {
			$this->assertTrue( Schema::table_exists( $table_name ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$table_name}" ) );
		}
	}

	/**
	 * Una instalación 0.8.0 añade autoridades vacías de pedidos.
	 *
	 * @return void
	 */
	public function test_upgrade_from_schema_five_creates_order_storage(): void {
		$this->assertTrue(
			Installer::install(
				array(
					new CreateMigrationLedger(),
					new InitializeMenuCatalog(),
					new CreateIngredientCatalog(),
					new CreateCommerceRules(),
					new CreateCartStorage(),
				)
			)
		);
		$this->assertSame( 5, Installer::current_version() );
		$this->assertFalse( Schema::table_exists( Schema::orders_table_name() ) );

		$this->assertTrue( Installer::install() );
		$this->assertSame( 6, Installer::current_version() );

		foreach ( array( Schema::orders_table_name(), Schema::order_items_table_name(), Schema::order_events_table_name() ) as $table_name ) {
			$this->assertTrue( Schema::table_exists( $table_name ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$table_name}" ) );
		}
	}

	/**
	 * Un option ajeno o inválido bloquea la migración sin ser eliminado.
	 *
	 * @return void
	 */
	public function test_menu_migration_preserves_conflicting_option_on_failure(): void {
		add_option( CatalogRevision::OPTION_NAME, '0', '', false );

		$this->assertFalse( Installer::install() );
		$this->assertSame( '0', get_option( CatalogRevision::OPTION_NAME ) );
		$this->assertFalse( Schema::table_exists( Schema::migration_table_name() ) );
		$this->assertFalse( get_option( 'vicu_restaurante_db_version', false ) );
	}

	/**
	 * El fallo de schema 3 conserva recursos previos y compensa solo los nuevos.
	 *
	 * @return void
	 */
	public function test_ingredient_migration_preserves_preexisting_resources_on_failure(): void {
		global $wpdb;

		$this->assertTrue(
			Installer::install(
				array(
					new CreateMigrationLedger(),
					new InitializeMenuCatalog(),
				)
			)
		);

		$table_name = Schema::ingredients_table_name();
		// El identificador usa el prefijo efectivo y un sufijo fijo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$table_name} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB" ) );
		add_option( AvailabilityRevision::OPTION_NAME, '0', '', false );

		$this->assertFalse( Installer::install() );
		$this->assertSame( 2, Installer::current_version() );
		$this->assertTrue( Schema::table_exists( $table_name ) );
		$this->assertFalse( Schema::table_exists( Schema::menu_ingredients_table_name() ) );
		$this->assertFalse( Schema::table_exists( Schema::pizza_options_table_name() ) );
		$this->assertSame( '0', get_option( AvailabilityRevision::OPTION_NAME ) );
		$this->assertSame( '1', get_option( CatalogRevision::OPTION_NAME ) );
	}

	/**
	 * Un fallo elimina recursos nuevos y no adelanta la versión.
	 *
	 * @return void
	 */
	public function test_failed_migration_rolls_back_new_resources(): void {
		global $wpdb;
		$ledger_migration = new CreateMigrationLedger();

		$this->assertFalse( $ledger_migration->is_applied(), Schema::migration_table_name() );

		$result = Installer::install(
			array(
				$ledger_migration,
				new CreateProbeTable(),
				new FailingMigration(),
			)
		);

		$this->assertFalse( $result );
		$this->assertFalse(
			Schema::table_exists( Schema::migration_table_name() ),
			'El ledger sobrevivió a la compensación. Error SQL: ' . $wpdb->last_error
		);
		$this->assertFalse( Schema::table_exists( $wpdb->prefix . 'vicu_rest_probe' ) );
		$this->assertFalse( Schema::table_exists( $wpdb->prefix . 'vicu_rest_failed' ) );
		$this->assertFalse( get_option( 'vicu_restaurante_db_version', false ) );
	}

	/**
	 * Los nombres usan el prefijo efectivo del sitio actual.
	 *
	 * @return void
	 */
	public function test_schema_uses_effective_site_prefix(): void {
		global $wpdb;

		$original_prefix = $wpdb->prefix;
		$wpdb->prefix    = 'tenant_42_';

		try {
			$this->assertSame( 'tenant_42_vicu_rest_migrations', Schema::migration_table_name() );
		} finally {
			$wpdb->prefix = $original_prefix;
		}
	}

	/**
	 * Elimina tablas creadas exclusivamente por esta suite.
	 *
	 * @return void
	 */
	private function drop_test_tables(): void {
		global $wpdb;

		$tables = array(
			Schema::order_events_table_name(),
			Schema::order_items_table_name(),
			Schema::orders_table_name(),
			Schema::idempotency_table_name(),
			Schema::cart_items_table_name(),
			Schema::carts_table_name(),
			Schema::cart_sessions_table_name(),
			Schema::discount_codes_table_name(),
			Schema::delivery_zones_table_name(),
			Schema::menu_ingredients_table_name(),
			Schema::pizza_options_table_name(),
			Schema::ingredients_table_name(),
			$wpdb->prefix . 'vicu_rest_failed',
			$wpdb->prefix . 'vicu_rest_probe',
			Schema::migration_table_name(),
		);

		foreach ( $tables as $table_name ) {
			// Los identificadores se construyen con el prefijo efectivo y sufijos fijos.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

			$this->assertNotFalse( $result, 'No se pudo limpiar una tabla de prueba.' );
		}
	}

	/**
	 * Restaura los roles compartidos al estado anterior a la suite.
	 *
	 * @return void
	 */
	private function remove_restaurant_capabilities(): void {
		$administrator = get_role( 'administrator' );

		foreach ( Capabilities::all() as $capability ) {
			$administrator->remove_cap( $capability );
		}
	}
}
