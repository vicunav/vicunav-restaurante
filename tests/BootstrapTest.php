<?php
/**
 * Pruebas del scaffold instalable.
 *
 * @package Vicunav_Restaurante
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifica el entry point antes de introducir lógica de dominio.
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
	 * El scaffold publica una versión y rutas consistentes.
	 *
	 * @return void
	 */
	public function test_defines_foundational_constants(): void {
		$this->assertSame( '0.1.0', VICU_RESTAURANTE_VERSION );
		$this->assertSame(
			realpath( dirname( __DIR__ ) . '/vicunav-restaurante.php' ),
			realpath( VICU_RESTAURANTE_PLUGIN_FILE )
		);
		$this->assertSame( dirname( __DIR__ ) . '/', VICU_RESTAURANTE_PATH );
	}

	/**
	 * El header conserva compatibilidad y dependencias acordadas.
	 *
	 * @return void
	 */
	public function test_plugin_header_declares_requirements(): void {
		$file     = new SplFileObject( dirname( __DIR__ ) . '/vicunav-restaurante.php' );
		$contents = '';

		while ( ! $file->eof() ) {
			$contents .= $file->fgets();
		}

		$this->assertStringContainsString( 'Requires at least: 6.6', $contents );
		$this->assertStringContainsString( 'Requires PHP:      8.1', $contents );
		$this->assertStringContainsString(
			'Requires Plugins:  vicunav-plugin-core, vicunav-pagos',
			$contents
		);
		$this->assertStringContainsString( 'Text Domain:       vicunav-restaurante', $contents );
	}
}
