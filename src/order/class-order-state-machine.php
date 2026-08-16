<?php
/**
 * Máquina de estados pura del pedido.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Order;

/**
 * Conserva transiciones v1 fuera de controladores y presentación.
 */
final class OrderStateMachine {
	public const STATES = array(
		'pendiente_pago',
		'pago_en_revision',
		'confirmado',
		'en_preparacion',
		'listo',
		'en_reparto',
		'completado',
		'cancelado',
		'expirado',
	);

	/**
	 * Determina si el arco existe para el tipo de entrega.
	 *
	 * @param string $from        Estado actual.
	 * @param string $to          Estado destino.
	 * @param string $fulfillment pickup o delivery.
	 * @return bool
	 */
	public static function allows( string $from, string $to, string $fulfillment ): bool {
		$transitions = array(
			'pendiente_pago'   => array( 'pago_en_revision', 'cancelado', 'expirado' ),
			'pago_en_revision' => array( 'pendiente_pago', 'confirmado', 'cancelado', 'expirado' ),
			'confirmado'       => array( 'en_preparacion', 'cancelado' ),
			'en_preparacion'   => array( 'listo', 'cancelado' ),
			'listo'            => 'delivery' === $fulfillment ? array( 'en_reparto' ) : array( 'completado' ),
			'en_reparto'       => array( 'completado' ),
		);

		return isset( $transitions[ $from ] ) && in_array( $to, $transitions[ $from ], true );
	}

	/**
	 * Comprueba estados terminales.
	 *
	 * @param string $status Estado.
	 * @return bool
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( $status, array( 'completado', 'cancelado', 'expirado' ), true );
	}
}
