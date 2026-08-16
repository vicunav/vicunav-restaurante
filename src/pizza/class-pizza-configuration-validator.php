<?php
/**
 * Validación del valor público pizza_configuration v1.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Pizza;

use WP_Error;

/**
 * Normaliza estructura sin resolver todavía el catálogo mutable.
 */
final class PizzaConfigurationValidator {
	public const VERSION      = 1;
	public const MAX_TOPPINGS = 6;
	public const ZONES        = array( 'whole', 'left', 'right' );

	/**
	 * Valida el valor completo y conserva solo campos contractuales.
	 *
	 * @param array<string, mixed> $input Configuración candidata.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function normalize( array $input ): array|WP_Error {
		$version          = self::positive_integer( $input['version'] ?? null );
		$catalog_revision = self::positive_integer( $input['catalog_revision'] ?? null );
		$quantity         = self::positive_integer( $input['quantity'] ?? null );
		$size_id          = self::uuid( $input['size_id'] ?? null );
		$crust_id         = self::uuid( $input['crust_id'] ?? null );
		$sauce_id         = self::uuid( $input['sauce_id'] ?? null );
		$cheese_id        = self::uuid( $input['cheese_ingredient_id'] ?? null );
		$toppings         = self::toppings( $input['toppings'] ?? null );

		if (
			self::VERSION !== $version ||
			null === $catalog_revision ||
			null === $quantity ||
			'' === $size_id ||
			'' === $crust_id ||
			'' === $sauce_id ||
			'' === $cheese_id ||
			is_wp_error( $toppings )
		) {
			return self::invalid();
		}

		return array(
			'version'              => self::VERSION,
			'catalog_revision'     => $catalog_revision,
			'size_id'              => $size_id,
			'crust_id'             => $crust_id,
			'sauce_id'             => $sauce_id,
			'cheese_ingredient_id' => $cheese_id,
			'toppings'             => $toppings,
			'quantity'             => $quantity,
		);
	}

	/**
	 * Valida toppings únicos representados por UUID y zona.
	 *
	 * @param mixed $value Mapa candidato.
	 * @return array<string, string>|WP_Error
	 */
	private static function toppings( mixed $value ): array|WP_Error {
		if ( ! is_array( $value ) || self::MAX_TOPPINGS < count( $value ) ) {
			return self::invalid();
		}

		$normalized = array();

		foreach ( $value as $ingredient_id => $zone ) {
			$id              = self::uuid( $ingredient_id );
			$normalized_zone = is_scalar( $zone ) ? sanitize_key( (string) $zone ) : '';

			if ( '' === $id || ! in_array( $normalized_zone, self::ZONES, true ) || isset( $normalized[ $id ] ) ) {
				return self::invalid();
			}

			$normalized[ $id ] = $normalized_zone;
		}

		ksort( $normalized, SORT_STRING );

		return $normalized;
	}

	/**
	 * Normaliza un UUID v4.
	 *
	 * @param mixed $value Valor candidato.
	 * @return string
	 */
	private static function uuid( mixed $value ): string {
		$id = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		return wp_is_uuid( $id, 4 ) ? $id : '';
	}

	/**
	 * Exige un entero positivo sin coerción decimal.
	 *
	 * @param mixed $value Valor candidato.
	 * @return int|null
	 */
	private static function positive_integer( mixed $value ): ?int {
		if ( ! is_scalar( $value ) || is_float( $value ) || ! is_numeric( $value ) || trim( (string) $value ) !== (string) (int) $value ) {
			return null;
		}

		$number = (int) $value;

		return 0 < $number ? $number : null;
	}

	/**
	 * Error de estructura estable.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error(
			'vicu_restaurante_invalid_request',
			__( 'La configuración de pizza no es válida.', 'vicunav-restaurante' ),
			array( 'status' => 400 )
		);
	}
}
