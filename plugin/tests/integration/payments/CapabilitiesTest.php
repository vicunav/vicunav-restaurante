<?php
/**
 * Pruebas de capacidades dedicadas.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Payments\Capabilities;

/**
 * Verifica concesión explícita y aislamiento entre roles.
 */
final class CapabilitiesTest extends WP_UnitTestCase {
	/**
	 * Capacidades primitivas contractuales.
	 *
	 * @var string[]
	 */
	private const EXPECTED_CAPABILITIES = array(
		'create_vicu_payment_requests',
		'delete_others_vicu_payment_requests',
		'delete_private_vicu_payment_requests',
		'delete_published_vicu_payment_requests',
		'delete_vicu_payment_requests',
		'edit_others_vicu_payment_requests',
		'edit_private_vicu_payment_requests',
		'edit_published_vicu_payment_requests',
		'edit_vicu_payment_requests',
		'publish_vicu_payment_requests',
		'read_private_vicu_payment_requests',
	);

	/**
	 * Verifica que activación conceda todas las primitivas al administrador.
	 *
	 * @return void
	 */
	public function test_grants_capabilities_to_administrator(): void {
		Capabilities::grant_to_administrator();
		$role = get_role( 'administrator' );

		$this->assertNotNull( $role );

		foreach ( self::EXPECTED_CAPABILITIES as $capability ) {
			$this->assertTrue( $role->has_cap( $capability ), $capability );
		}
	}

	/**
	 * Verifica que el rol editor no reciba permisos por defecto.
	 *
	 * @return void
	 */
	public function test_does_not_grant_capabilities_to_editor(): void {
		Capabilities::grant_to_administrator();
		$role = get_role( 'editor' );

		$this->assertNotNull( $role );
		$this->assertFalse( $role->has_cap( 'edit_vicu_payment_requests' ) );
	}
}
