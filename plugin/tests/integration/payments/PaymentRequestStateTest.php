<?php
/**
 * Pruebas unitarias de la máquina de estados.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Payments\PaymentRequestState;

/**
 * Comprueba la matriz sin depender de persistencia.
 */
final class PaymentRequestStateTest extends WP_UnitTestCase {
	/**
	 * Verifica cada transición permitida y cada estado terminal.
	 *
	 * @return void
	 */
	public function test_contractual_transition_matrix(): void {
		$this->assertSame(
			array( PaymentRequestState::PROOF_UPLOADED, PaymentRequestState::EXPIRED ),
			PaymentRequestState::allowed_targets( PaymentRequestState::PENDING )
		);
		$this->assertSame(
			array( PaymentRequestState::CONFIRMED, PaymentRequestState::REJECTED ),
			PaymentRequestState::allowed_targets( PaymentRequestState::PROOF_UPLOADED )
		);
		$this->assertSame(
			array( PaymentRequestState::PROOF_UPLOADED, PaymentRequestState::EXPIRED ),
			PaymentRequestState::allowed_targets( PaymentRequestState::REJECTED )
		);
		$this->assertSame( array(), PaymentRequestState::allowed_targets( PaymentRequestState::CONFIRMED ) );
		$this->assertSame( array(), PaymentRequestState::allowed_targets( PaymentRequestState::EXPIRED ) );
		$this->assertTrue( PaymentRequestState::is_terminal( PaymentRequestState::CONFIRMED ) );
		$this->assertTrue( PaymentRequestState::is_terminal( PaymentRequestState::EXPIRED ) );
		$this->assertFalse( PaymentRequestState::is_terminal( PaymentRequestState::REJECTED ) );
	}

	/**
	 * Verifica que estados desconocidos nunca creen una transición implícita.
	 *
	 * @return void
	 */
	public function test_unknown_state_has_no_targets(): void {
		$this->assertSame( array(), PaymentRequestState::allowed_targets( 'desconocido' ) );
		$this->assertFalse( PaymentRequestState::can_transition( 'desconocido', PaymentRequestState::PENDING ) );
	}
}
