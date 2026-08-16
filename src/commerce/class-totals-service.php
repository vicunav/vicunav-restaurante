<?php
/**
 * Cálculo puro y autoritativo de totales.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Commerce;

use Vicu\Restaurante\Settings\RestaurantSettings;
use WP_Error;

/**
 * Resuelve reglas de servidor sin persistir carrito ni pedido.
 */
final class TotalsService {
	/**
	 * Calcula componentes en el orden normativo.
	 *
	 * @param int         $subtotal_minor Subtotal autoritativo.
	 * @param string|null $discount_code  Código opcional.
	 * @param int         $tip_rate_bps   Propina elegida.
	 * @param string      $fulfillment    pickup o delivery.
	 * @param string|null $zone_public_id Zona explícita para delivery.
	 * @param string      $now_utc        Fecha UTC inyectable.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function calculate(
		int $subtotal_minor,
		?string $discount_code,
		int $tip_rate_bps,
		string $fulfillment,
		?string $zone_public_id,
		string $now_utc = ''
	): array|WP_Error {
		if ( 0 > $subtotal_minor || ! in_array( $tip_rate_bps, RestaurantSettings::tip_rates_bps(), true ) || ! in_array( $fulfillment, array( 'pickup', 'delivery' ), true ) ) {
			return self::invalid();
		}

		$zone = null;

		if ( 'pickup' === $fulfillment ) {
			if ( null !== $zone_public_id && '' !== $zone_public_id ) {
				return self::invalid();
			}
		} else {
			$zone = is_string( $zone_public_id ) ? DeliveryZoneService::find( $zone_public_id ) : null;

			if ( null === $zone || ! $zone['active'] ) {
				return self::unavailable();
			}
		}

		$discount = null;

		if ( null !== $discount_code && '' !== trim( $discount_code ) ) {
			$discount = DiscountService::resolve( $discount_code, $subtotal_minor, $now_utc );

			if ( is_wp_error( $discount ) ) {
				return $discount;
			}
		}

		$discount_total  = null === $discount ? 0 : $discount['amount_minor'];
		$net_merchandise = $subtotal_minor - $discount_total;
		$tax_rate_bps    = RestaurantSettings::tax_rate_bps();
		$tax_total       = self::percentage( $net_merchandise, $tax_rate_bps );
		$tip_total       = self::percentage( $net_merchandise, $tip_rate_bps );
		$delivery_total  = null === $zone ? 0 : $zone['fee_minor'];
		$total           = self::safe_sum( array( $net_merchandise, $tax_total, $tip_total, $delivery_total ) );

		if ( null === $total ) {
			return self::invalid();
		}

		return array(
			'pricing_revision' => PricingRevision::current(),
			'currency'         => RestaurantSettings::currency(),
			'subtotal_minor'   => $subtotal_minor,
			'discount'         => null === $discount ? null : self::discount_snapshot( $discount ),
			'discount_total'   => $discount_total,
			'net_merchandise'  => $net_merchandise,
			'tax_rate_bps'     => $tax_rate_bps,
			'tax_total'        => $tax_total,
			'tip_rate_bps'     => $tip_rate_bps,
			'tip_total'        => $tip_total,
			'fulfillment'      => $fulfillment,
			'delivery_zone'    => null === $zone ? null : self::zone_snapshot( $zone ),
			'delivery_total'   => $delivery_total,
			'total'            => $total,
		);
	}

	/**
	 * Redondea half-up una tasa sobre el agregado.
	 *
	 * @param int $amount   Base no negativa.
	 * @param int $rate_bps Puntos base.
	 * @return int
	 */
	private static function percentage( int $amount, int $rate_bps ): int {
		return intdiv( $amount, 10000 ) * $rate_bps +
			intdiv( ( $amount % 10000 ) * $rate_bps + 5000, 10000 );
	}

	/**
	 * Suma componentes sin desbordar.
	 *
	 * @param int[] $amounts Componentes.
	 * @return int|null
	 */
	private static function safe_sum( array $amounts ): ?int {
		$total = 0;

		foreach ( $amounts as $amount ) {
			if ( 0 > $amount || $total > PHP_INT_MAX - $amount ) {
				return null;
			}

			$total += $amount;
		}

		return $total;
	}

	/**
	 * Snapshot seguro del descuento aplicado.
	 *
	 * @param array<string, mixed> $discount Descuento resuelto.
	 * @return array<string, mixed>
	 */
	private static function discount_snapshot( array $discount ): array {
		return array(
			'public_id'    => $discount['public_id'],
			'code'         => $discount['code'],
			'type'         => $discount['type'],
			'value'        => $discount['value'],
			'amount_minor' => $discount['amount_minor'],
			'revision'     => $discount['revision'],
		);
	}

	/**
	 * Snapshot seguro de zona y tarifa.
	 *
	 * @param array<string, mixed> $zone Zona.
	 * @return array<string, mixed>
	 */
	private static function zone_snapshot( array $zone ): array {
		return array(
			'public_id'       => $zone['public_id'],
			'name'            => $zone['name'],
			'fee_minor'       => $zone['fee_minor'],
			'eta_min_minutes' => $zone['eta_min_minutes'],
			'eta_max_minutes' => $zone['eta_max_minutes'],
			'revision'        => $zone['revision'],
		);
	}

	/**
	 * Error de entrada estable.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_request', __( 'No se pudieron calcular los totales.', 'vicunav-restaurante' ), array( 'status' => 400 ) );
	}

	/**
	 * Error de zona no aplicable.
	 *
	 * @return WP_Error
	 */
	private static function unavailable(): WP_Error {
		return new WP_Error( 'vicu_restaurante_unavailable', __( 'La zona de entrega ya no está disponible.', 'vicunav-restaurante' ), array( 'status' => 409 ) );
	}
}
