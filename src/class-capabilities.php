<?php
/**
 * Capabilities primitivas del vertical restaurante.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante;

/**
 * Concede capabilities únicamente durante la instalación controlada.
 *
 * @internal
 */
final class Capabilities {
	/**
	 * Capabilities que recibe el rol administrador.
	 *
	 * @var string[]
	 */
	private const ADMINISTRATOR_CAPABILITIES = array(
		'manage_vicu_restaurant_catalog',
		'manage_vicu_restaurant_availability',
		'manage_vicu_restaurant_discounts',
		'manage_vicu_restaurant_delivery',
		'view_vicu_restaurant_orders',
		'manage_vicu_restaurant_orders',
		'fulfill_vicu_restaurant_orders',
		'view_vicu_restaurant_payment_evidence',
		'manage_vicu_restaurant_reservations',
		'manage_vicu_restaurant_settings',
		'reconcile_vicu_restaurant_payments',
	);

	/**
	 * Devuelve la lista canónica para registro y pruebas.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return self::ADMINISTRATOR_CAPABILITIES;
	}

	/**
	 * Concede las capabilities al rol administrador.
	 *
	 * @return void
	 */
	public static function grant_to_administrator(): void {
		$role = get_role( 'administrator' );

		if ( null === $role ) {
			return;
		}

		foreach ( self::ADMINISTRATOR_CAPABILITIES as $capability ) {
			$role->add_cap( $capability );
		}
	}
}
