<?php
/**
 * Capacidades del dominio de pagos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Administra únicamente las capacidades primitivas del CPT.
 *
 * @internal
 */
final class Capabilities {
	/**
	 * Capacidades primitivas que recibe el rol administrador.
	 *
	 * @var string[]
	 */
	private const ADMINISTRATOR_CAPABILITIES = array(
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
	 * Concede la administración del CPT al rol administrador.
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
