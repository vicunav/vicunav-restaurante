<?php
/**
 * Pruebas de instalación con WordPress y MySQL reales.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Capabilities;
use Vicu\Restaurante\Installer;
use Vicu\Restaurante\Menu\CatalogRevision;
use Vicu\Restaurante\Migrations\CreateMigrationLedger;
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
		$this->assertSame( '2', get_option( 'vicu_restaurante_db_version' ) );
		$this->assertSame( 2, Installer::current_version() );
		$this->assertSame( '1', get_option( CatalogRevision::OPTION_NAME ) );

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

		$this->assertSame( 2, $count );
		$this->assertSame( 2, Installer::current_version() );
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
		$this->assertSame( '2', get_option( 'vicu_restaurante_db_version' ) );
		$this->assertSame( 2, Installer::current_version() );
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
		$this->assertSame( 2, Installer::current_version() );
		$this->assertSame( '1', get_option( CatalogRevision::OPTION_NAME ) );
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
