<?php
/**
 * Pruebas de administración y pestañas de ajustes.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Shared\Tests;

use Vicu\Restaurante\Shared\Settings;
use WPDieException;
use WP_UnitTestCase;

/**
 * Verifica menú, extensión, autorización, formulario y sanitización.
 */
final class SettingsAdminTest extends WP_UnitTestCase {
	/**
	 * Limpia estado global modificable después de cada prueba.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_GET['tab'] );
		delete_option( 'vicu_core_settings' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Comprueba el menú superior contractual.
	 *
	 * @return void
	 */
	public function test_registers_vicunav_top_level_menu(): void {
		global $menu;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- La prueba aísla el menú antes de registrar la entrada.
		$menu = array();
		Settings::register_menu();
		$entry = wp_list_filter( $menu, array( 2 => 'vicunav' ) );

		$this->assertCount( 1, $entry );
		$this->assertSame( 'Vicunav', array_values( $entry )[0][0] );
		$this->assertSame( 'manage_options', array_values( $entry )[0][1] );

		remove_menu_page( 'vicunav' );
	}

	/**
	 * Comprueba normalización y reemplazo del mismo slug.
	 *
	 * @return void
	 */
	public function test_register_tab_normalizes_and_replaces_duplicate_slug(): void {
		$this->set_administrator();
		$old_callback_called = false;

		Settings::register_tab(
			'Custom Tab',
			'Primera',
			static function () use ( &$old_callback_called ): void {
				$old_callback_called = true;
			}
		);
		Settings::register_tab(
			'customtab',
			'Segunda',
			static function (): void {
				echo '<p>Contenido nuevo</p>';
			}
		);

		$_GET['tab'] = 'customtab';
		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertFalse( $old_callback_called );
		$this->assertStringContainsString( 'Segunda', $output );
		$this->assertStringContainsString( 'Contenido nuevo', $output );
		$this->assertStringNotContainsString( 'Primera', $output );
	}

	/**
	 * Comprueba que una callback no se ejecute sin capability.
	 *
	 * @return void
	 */
	public function test_render_page_rejects_unauthorized_tab_callback(): void {
		$callback_called = false;
		$user_id         = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		Settings::register_tab(
			'restricted',
			'Restringida',
			static function () use ( &$callback_called ): void {
				$callback_called = true;
			},
			'manage_options'
		);

		$_GET['tab'] = 'restricted';

		try {
			Settings::render_page();
			$this->fail( 'La pestaña restringida debió rechazar el acceso.' );
		} catch ( WPDieException $exception ) {
			$this->assertStringContainsString( 'No tienes permisos', $exception->getMessage() );
		}

		$this->assertFalse( $callback_called );
	}

	/**
	 * Comprueba que un slug vacío se descarte.
	 *
	 * @return void
	 */
	public function test_register_tab_ignores_empty_slug(): void {
		$this->set_administrator();
		Settings::register_settings();
		$callback_called = false;

		Settings::register_tab(
			'***',
			'Inválida',
			static function () use ( &$callback_called ): void {
				$callback_called = true;
			}
		);

		$_GET['tab'] = '***';
		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertFalse( $callback_called );
		$this->assertStringNotContainsString( 'Inválida', $output );
		$this->assertStringContainsString( 'vicu_core_settings[phone]', $output );
	}

	/**
	 * Comprueba campos, acción y nonce del formulario general.
	 *
	 * @return void
	 */
	public function test_general_tab_uses_wordpress_settings_api_form(): void {
		$this->set_administrator();
		Settings::register_settings();

		ob_start();
		Settings::render_general_tab();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'options.php', $output );
		$this->assertStringContainsString( "name='option_page' value='vicu_core_settings'", $output );
		$this->assertStringContainsString( 'name="_wpnonce"', $output );
		$this->assertStringContainsString( '<label for="phone">Teléfono</label>', $output );
		$this->assertStringContainsString( '<label for="address">Dirección</label>', $output );
		$this->assertStringContainsString( '<label for="business_hours">Horario de atención</label>', $output );
		$this->assertStringContainsString( 'vicu_core_settings[phone]', $output );
		$this->assertStringContainsString( 'vicu_core_settings[address]', $output );
		$this->assertStringContainsString( 'vicu_core_settings[business_hours]', $output );
	}

	/**
	 * Comprueba sanitización específica y descarte de claves ajenas.
	 *
	 * @return void
	 */
	public function test_sanitize_settings_applies_field_rules_and_allowlist(): void {
		$sanitized = Settings::sanitize_settings(
			array(
				'phone'          => ' +58<script>212</script> (555)-0101 ext',
				'address'        => " Calle <b>Uno</b>\nPiso 2 ",
				'business_hours' => "Lun-Vie\n<script>alert(1)</script>08:00-17:00",
				'unknown'        => 'no debe persistirse',
			)
		);

		$this->assertSame( '+58 (555)-0101 ', $sanitized['phone'] );
		$this->assertSame( "Calle Uno\nPiso 2", $sanitized['address'] );
		$this->assertSame( "Lun-Vie\n08:00-17:00", $sanitized['business_hours'] );
		$this->assertArrayNotHasKey( 'unknown', $sanitized );
	}

	/**
	 * Comprueba que una forma no-array se rechace por completo.
	 *
	 * @return void
	 */
	public function test_sanitize_settings_rejects_non_array_input(): void {
		$this->assertSame( array(), Settings::sanitize_settings( 'invalid' ) );
	}

	/**
	 * Crea y activa un administrador para pruebas de UI.
	 *
	 * @return void
	 */
	private function set_administrator(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}
}
