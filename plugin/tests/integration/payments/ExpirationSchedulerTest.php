<?php
/**
 * Pruebas de integración de la recurrencia de expiración.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Payments\ExpirationScheduler;

/**
 * Verifica agenda única y desactivación limpia.
 */
final class ExpirationSchedulerTest extends WP_UnitTestCase {
	/**
	 * La programación repetida conserva un único evento.
	 *
	 * @return void
	 */
	public function test_schedule_is_unique_and_unschedule_removes_it(): void {
		ExpirationScheduler::unschedule();
		$this->assertFalse( wp_next_scheduled( ExpirationScheduler::HOOK ) );

		ExpirationScheduler::schedule();
		$first = wp_next_scheduled( ExpirationScheduler::HOOK );
		ExpirationScheduler::schedule();

		$this->assertIsInt( $first );
		$this->assertSame( $first, wp_next_scheduled( ExpirationScheduler::HOOK ) );

		ExpirationScheduler::unschedule();
		$this->assertFalse( wp_next_scheduled( ExpirationScheduler::HOOK ) );
	}
}
