<?php
/**
 * Estados contractuales de una solicitud de pago.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Publica el vocabulario y las reglas de la máquina de estados.
 */
final class PaymentRequestState {
	public const PENDING = 'pendiente';

	public const PROOF_UPLOADED = 'comprobante_subido';

	public const CONFIRMED = 'confirmado';

	public const REJECTED = 'rechazado';

	public const EXPIRED = 'expirado';

	/**
	 * Devuelve todos los estados contractuales.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::PENDING,
			self::PROOF_UPLOADED,
			self::CONFIRMED,
			self::REJECTED,
			self::EXPIRED,
		);
	}

	/**
	 * Devuelve los destinos permitidos desde un estado.
	 *
	 * @param string $state Estado actual.
	 * @return string[]
	 */
	public static function allowed_targets( string $state ): array {
		$transitions = array(
			self::PENDING        => array( self::PROOF_UPLOADED, self::EXPIRED ),
			self::PROOF_UPLOADED => array( self::CONFIRMED, self::REJECTED ),
			self::REJECTED       => array( self::PROOF_UPLOADED, self::EXPIRED ),
			self::CONFIRMED      => array(),
			self::EXPIRED        => array(),
		);

		return $transitions[ $state ] ?? array();
	}

	/**
	 * Indica si una transición pertenece a la máquina.
	 *
	 * @param string $from Estado de origen.
	 * @param string $to   Estado de destino.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to ): bool {
		return in_array( $to, self::allowed_targets( $from ), true );
	}

	/**
	 * Indica si un estado no permite más transiciones.
	 *
	 * @param string $state Estado consultado.
	 * @return bool
	 */
	public static function is_terminal( string $state ): bool {
		return in_array( $state, array( self::CONFIRMED, self::EXPIRED ), true );
	}
}
