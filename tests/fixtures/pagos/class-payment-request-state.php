<?php
/**
 * Doble de los estados públicos de pago.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Pagos;

// phpcs:disable Squiz.Commenting,Generic.Commenting.DocComment -- Doble contractual acotado a pruebas.

final class PaymentRequestState {
	public const PENDING        = 'pendiente';
	public const PROOF_UPLOADED = 'comprobante_subido';
	public const CONFIRMED      = 'confirmado';
	public const REJECTED       = 'rechazado';
	public const EXPIRED        = 'expirado';

	/** @return string[] */
	public static function all(): array {
		return array( self::PENDING, self::PROOF_UPLOADED, self::CONFIRMED, self::REJECTED, self::EXPIRED );
	}

	public static function can_transition( string $from, string $to ): bool {
		$map = array(
			self::PENDING        => array( self::PROOF_UPLOADED, self::EXPIRED ),
			self::PROOF_UPLOADED => array( self::CONFIRMED, self::REJECTED ),
			self::REJECTED       => array( self::PROOF_UPLOADED, self::EXPIRED ),
		);

		return in_array( $to, $map[ $from ] ?? array(), true );
	}
}
