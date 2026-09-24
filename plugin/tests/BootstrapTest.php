<?php
/**
 * Pruebas del bootstrap del plugin.
 *
 * @package Vicunav_Restaurante
 */

use PHPUnit\Framework\TestCase;
use Vicu\Restaurante\Installer;

/**
 * Verifica versiones, hooks del entry point y autoload.
 */
final class BootstrapTest extends TestCase {
	/**
	 * Carga el plugin una sola vez.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__ ) . '/vicunav-restaurante.php';
	}

	/**
	 * El entry point publica versiones y rutas consistentes.
	 *
	 * @return void
	 */
	public function test_defines_foundational_constants(): void {
		$this->assertSame( '1.0.0', VICU_RESTAURANTE_VERSION );
		$this->assertSame( '9', VICU_RESTAURANTE_DB_VERSION );
		$this->assertSame(
			realpath( dirname( __DIR__ ) . '/vicunav-restaurante.php' ),
			realpath( VICU_RESTAURANTE_PLUGIN_FILE )
		);
		$this->assertSame( dirname( __DIR__ ) . '/', VICU_RESTAURANTE_PATH );
	}

	/**
	 * El entry point registra la instalación desde el archivo principal.
	 *
	 * @return void
	 */
	public function test_registers_activation_hook_from_entry_point(): void {
		global $vicu_restaurante_test_activation_hooks;

		$this->assertCount( 1, $vicu_restaurante_test_activation_hooks );
		$this->assertSame( VICU_RESTAURANTE_PLUGIN_FILE, $vicu_restaurante_test_activation_hooks[0]['file'] );
		$this->assertSame( 'Vicu\\Restaurante\\activate', $vicu_restaurante_test_activation_hooks[0]['callback'] );
	}

	/**
	 * El entry point registra limpieza de cron sin borrar datos.
	 *
	 * @return void
	 */
	public function test_registers_deactivation_hook_from_entry_point(): void {
		global $vicu_restaurante_test_deactivation_hooks;

		$this->assertCount( 1, $vicu_restaurante_test_deactivation_hooks );
		$this->assertSame( VICU_RESTAURANTE_PLUGIN_FILE, $vicu_restaurante_test_deactivation_hooks[0]['file'] );
		$this->assertSame( 'Vicu\\Restaurante\\deactivate', $vicu_restaurante_test_deactivation_hooks[0]['callback'] );
	}

	/**
	 * El autoloader resuelve las clases propias desde src/.
	 *
	 * @return void
	 */
	public function test_autoloads_plugin_classes(): void {
		$this->assertTrue( class_exists( Installer::class ) );
		$this->assertTrue( class_exists( \Vicu\Restaurante\Payments\PaymentRequests::class ) );
		$this->assertTrue( class_exists( \Vicu\Restaurante\Shared\Settings::class ) );
	}

	/**
	 * El bootstrap se registra en plugins_loaded con prioridad veinte.
	 *
	 * @return void
	 */
	public function test_registers_bootstrap_at_priority_twenty(): void {
		global $vicu_restaurante_test_actions;

		$callbacks = $vicu_restaurante_test_actions['plugins_loaded'] ?? array();

		$this->assertCount( 1, $callbacks );
		$this->assertSame( 20, $callbacks[0]['priority'] );
		$this->assertSame( 'Vicu\\Restaurante\\bootstrap', $callbacks[0]['callback'] );
	}

	/**
	 * El header declara compatibilidad y no depende de otros plugins.
	 *
	 * @return void
	 */
	public function test_plugin_header_declares_requirements(): void {
		$file     = new SplFileObject( dirname( __DIR__ ) . '/vicunav-restaurante.php' );
		$contents = '';

		while ( ! $file->eof() ) {
			$contents .= $file->fgets();
		}

		$this->assertStringContainsString( 'Version:           1.0.0', $contents );
		$this->assertStringContainsString( 'Requires at least: 6.6', $contents );
		$this->assertStringContainsString( 'Requires PHP:      8.1', $contents );
		$this->assertStringNotContainsString( 'Requires Plugins', $contents );
		$this->assertStringContainsString( 'Text Domain:       vicunav-restaurante', $contents );
	}
}
