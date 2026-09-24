<?php
/**
 * Pruebas puras de estados de reserva.
 *
 * @package Vicunav_Restaurante
 */

use PHPUnit\Framework\TestCase;
use Vicu\Restaurante\Reservation\ReservationStateMachine;

/**
 * Congela arcos, terminales y estados que consumen capacidad.
 */
final class ReservationStateMachineTest extends TestCase {
	/** Verifica todos los arcos v1 permitidos. */
	public function test_allows_only_contractual_transitions(): void {
		$this->assertTrue( ReservationStateMachine::allows( 'pendiente', 'confirmada' ) );
		$this->assertTrue( ReservationStateMachine::allows( 'pendiente', 'cancelada' ) );
		$this->assertTrue( ReservationStateMachine::allows( 'confirmada', 'completada' ) );
		$this->assertTrue( ReservationStateMachine::allows( 'confirmada', 'cancelada' ) );
		$this->assertTrue( ReservationStateMachine::allows( 'confirmada', 'no_asistio' ) );
		$this->assertFalse( ReservationStateMachine::allows( 'pendiente', 'completada' ) );
		$this->assertFalse( ReservationStateMachine::allows( 'cancelada', 'confirmada' ) );
	}

	/** Separa capacidad activa de terminales irreversibles. */
	public function test_classifies_capacity_and_terminal_states(): void {
		$this->assertTrue( ReservationStateMachine::consumes_capacity( 'pendiente' ) );
		$this->assertTrue( ReservationStateMachine::consumes_capacity( 'confirmada' ) );
		$this->assertFalse( ReservationStateMachine::consumes_capacity( 'cancelada' ) );
		$this->assertTrue( ReservationStateMachine::is_terminal( 'completada' ) );
		$this->assertTrue( ReservationStateMachine::is_terminal( 'cancelada' ) );
		$this->assertTrue( ReservationStateMachine::is_terminal( 'no_asistio' ) );
	}
}
