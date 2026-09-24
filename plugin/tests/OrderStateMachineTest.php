<?php
/**
 * Pruebas puras de estados de pedido.
 *
 * @package Vicunav_Restaurante
 */

use PHPUnit\Framework\TestCase;
use Vicu\Restaurante\Order\OrderStateMachine;

/**
 * Congela cada arco contractual sin depender de WordPress o MySQL.
 */
final class OrderStateMachineTest extends TestCase {
	/**
	 * Verifica todos los arcos permitidos.
	 *
	 * @dataProvider allowed_transitions
	 *
	 * @param string $from        Origen.
	 * @param string $to          Destino.
	 * @param string $fulfillment Tipo.
	 * @return void
	 */
	public function test_allows_contractual_transition( string $from, string $to, string $fulfillment ): void {
		$this->assertTrue( OrderStateMachine::allows( $from, $to, $fulfillment ) );
	}

	/**
	 * Rechaza saltos, terminales y destinos incompatibles con fulfillment.
	 *
	 * @dataProvider forbidden_transitions
	 *
	 * @param string $from        Origen.
	 * @param string $to          Destino.
	 * @param string $fulfillment Tipo.
	 * @return void
	 */
	public function test_rejects_non_contractual_transition( string $from, string $to, string $fulfillment ): void {
		$this->assertFalse( OrderStateMachine::allows( $from, $to, $fulfillment ) );
	}

	/**
	 * Arcos normativos.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function allowed_transitions(): array {
		$cases = array();
		$map   = array(
			'pendiente_pago'   => array( 'pago_en_revision', 'cancelado', 'expirado' ),
			'pago_en_revision' => array( 'pendiente_pago', 'confirmado', 'cancelado', 'expirado' ),
			'confirmado'       => array( 'en_preparacion', 'cancelado' ),
			'en_preparacion'   => array( 'listo', 'cancelado' ),
		);

		foreach ( $map as $from => $targets ) {
			foreach ( $targets as $to ) {
				$cases[ $from . '-' . $to ] = array( $from, $to, 'pickup' );
			}
		}

		$cases['pickup-listo-completado']   = array( 'listo', 'completado', 'pickup' );
		$cases['delivery-listo-reparto']    = array( 'listo', 'en_reparto', 'delivery' );
		$cases['delivery-reparto-completo'] = array( 'en_reparto', 'completado', 'delivery' );

		return $cases;
	}

	/**
	 * Saltos representativos prohibidos.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function forbidden_transitions(): array {
		return array(
			'salto sin pago'             => array( 'pendiente_pago', 'en_preparacion', 'pickup' ),
			'pickup no reparte'          => array( 'listo', 'en_reparto', 'pickup' ),
			'delivery no completa listo' => array( 'listo', 'completado', 'delivery' ),
			'completado terminal'        => array( 'completado', 'confirmado', 'pickup' ),
			'cancelado terminal'         => array( 'cancelado', 'pendiente_pago', 'pickup' ),
			'expirado terminal'          => array( 'expirado', 'pendiente_pago', 'pickup' ),
		);
	}
}
