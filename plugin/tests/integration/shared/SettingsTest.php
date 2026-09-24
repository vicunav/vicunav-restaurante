<?php
/**
 * Pruebas del servicio de ajustes compartidos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Shared\Tests;

use Vicu\Restaurante\Shared\Settings;
use WP_UnitTestCase;

/**
 * Verifica las lecturas contractuales sobre la Options API.
 */
final class SettingsTest extends WP_UnitTestCase {
	/**
	 * Limpia el option compartido después de cada prueba.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'vicu_core_settings' );
		parent::tear_down();
	}

	/**
	 * Comprueba que una clave persistida se devuelva sin alterar su tipo.
	 *
	 * @return void
	 */
	public function test_returns_existing_value(): void {
		update_option(
			'vicu_core_settings',
			array(
				'phone' => '+58 212 555 0101',
				'count' => 3,
			)
		);

		$this->assertSame( '+58 212 555 0101', Settings::get( 'phone' ) );
		$this->assertSame( 3, Settings::get( 'count' ) );
	}

	/**
	 * Comprueba el default explícito para una clave ausente.
	 *
	 * @return void
	 */
	public function test_returns_exact_default_for_missing_key(): void {
		update_option( 'vicu_core_settings', array( 'phone' => '123' ) );
		$default = new \stdClass();

		$this->assertSame( $default, Settings::get( 'address', $default ) );
	}

	/**
	 * Comprueba que el default implícito sea null.
	 *
	 * @return void
	 */
	public function test_returns_null_when_default_is_omitted(): void {
		$this->assertNull( Settings::get( 'missing' ) );
	}

	/**
	 * Comprueba que una cadena vacía no se confunda con ausencia.
	 *
	 * @return void
	 */
	public function test_preserves_existing_empty_value(): void {
		update_option( 'vicu_core_settings', array( 'business_hours' => '' ) );

		$this->assertSame( '', Settings::get( 'business_hours', 'fallback' ) );
	}

	/**
	 * Comprueba una forma persistida que no cumpla el contrato interno.
	 *
	 * @return void
	 */
	public function test_treats_non_array_option_as_missing(): void {
		update_option( 'vicu_core_settings', 'invalid' );

		$this->assertSame( 'fallback', Settings::get( 'phone', 'fallback' ) );
	}
}
