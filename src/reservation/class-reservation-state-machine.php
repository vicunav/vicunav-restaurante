<?php
/**
 * Máquina de estados de reservas v1.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Reservation;

/**
 * Congela los estados, arcos y consumo de capacidad de reservas v1.
 */
final class ReservationStateMachine {
	public const STATES = array( 'pendiente', 'confirmada', 'completada', 'cancelada', 'no_asistio' );

	/**
	 * Comprueba un arco contractual.
	 *
	 * @param string $from Estado de origen.
	 * @param string $to   Estado de destino.
	 * @return bool
	 */
	public static function allows( string $from, string $to ): bool {
		$map = array(
			'pendiente'  => array( 'confirmada', 'cancelada' ),
			'confirmada' => array( 'completada', 'cancelada', 'no_asistio' ),
		);

		return in_array( $to, $map[ $from ] ?? array(), true );
	}

	/**
	 * Indica si el estado ocupa capacidad.
	 *
	 * @param string $status Estado.
	 * @return bool
	 */
	public static function consumes_capacity( string $status ): bool {
		return in_array( $status, array( 'pendiente', 'confirmada' ), true );
	}

	/**
	 * Indica si un estado impide transiciones futuras.
	 *
	 * @param string $status Estado.
	 * @return bool
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( $status, array( 'completada', 'cancelada', 'no_asistio' ), true );
	}
}
