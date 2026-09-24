<?php
/**
 * Programación de expiraciones repetibles.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Conecta el lote de expiración con WP-Cron.
 *
 * @internal
 */
final class ExpirationScheduler {
	public const HOOK = 'vicu_pagos_expire_requests';

	/**
	 * Registra los callbacks de cron.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ), 30 );
	}

	/**
	 * Agenda una sola recurrencia horaria.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Retira todos los eventos al desactivar.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Expira un lote con la hora UTC de WordPress.
	 *
	 * @return void
	 */
	public static function run(): void {
		PaymentRequests::expire_due();
	}
}
