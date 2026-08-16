<?php
/**
 * Validación compartida del catálogo canónico.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Catalog;

use Vicu\Restaurante\Menu\MenuMeta;
use WP_Error;

/**
 * Rechaza vocabularios, tipos e importes fuera del contrato.
 */
final class CatalogValidator {
	public const INGREDIENT_CATEGORIES = array( 'base', 'cheese', 'extra', 'topping' );
	public const OPTION_TYPES          = array( 'crust', 'sauce', 'size' );
	public const RELATION_ROLES        = array( 'optional', 'removable', 'required' );

	/**
	 * Valida un ingrediente completo.
	 *
	 * @param array<string, mixed> $input Datos candidatos.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function ingredient( array $input ): array|WP_Error {
		$name      = self::name( $input['name'] ?? '' );
		$category  = sanitize_key( self::scalar( $input['category'] ?? '' ) );
		$price     = self::signed_amount( $input['price_modifier_minor'] ?? 0 );
		$allergens = self::controlled_list( $input['allergens'] ?? array(), MenuMeta::allergen_ids() );
		$dietary   = self::controlled_list( $input['dietary_tags'] ?? array(), MenuMeta::dietary_tag_ids() );

		if ( '' === $name || ! in_array( $category, self::INGREDIENT_CATEGORIES, true ) || null === $price || is_wp_error( $allergens ) || is_wp_error( $dietary ) ) {
			return self::invalid();
		}

		return array(
			'name'                 => $name,
			'category'             => $category,
			'price_modifier_minor' => $price,
			'available'            => rest_sanitize_boolean( $input['available'] ?? false ),
			'allergens'            => $allergens,
			'dietary_tags'         => $dietary,
		);
	}

	/**
	 * Valida una opción completa de pizza.
	 *
	 * @param array<string, mixed> $input Datos candidatos.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function option( array $input ): array|WP_Error {
		$name  = self::name( $input['name'] ?? '' );
		$type  = sanitize_key( self::scalar( $input['type'] ?? '' ) );
		$price = self::signed_amount( $input['price_modifier_minor'] ?? 0 );
		$order = self::non_negative_int( $input['display_order'] ?? 0 );

		if ( '' === $name || ! in_array( $type, self::OPTION_TYPES, true ) || null === $price || null === $order ) {
			return self::invalid();
		}

		return array(
			'name'                 => $name,
			'type'                 => $type,
			'price_modifier_minor' => $price,
			'available'            => rest_sanitize_boolean( $input['available'] ?? false ),
			'display_order'        => min( 9999, $order ),
		);
	}

	/**
	 * Valida un nombre visible acotado.
	 *
	 * @param mixed $value Valor candidato.
	 * @return string
	 */
	private static function name( mixed $value ): string {
		$name = sanitize_text_field( wp_unslash( self::scalar( $value ) ) );

		return 191 >= mb_strlen( $name ) ? $name : '';
	}

	/**
	 * Valida importes con signo dentro de un límite defensivo.
	 *
	 * @param mixed $value Valor candidato.
	 * @return int|null
	 */
	private static function signed_amount( mixed $value ): ?int {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) || trim( (string) $value ) !== (string) (int) $value ) {
			return null;
		}

		$amount = (int) $value;

		return abs( $amount ) <= 100000000 ? $amount : null;
	}

	/**
	 * Valida un entero no negativo.
	 *
	 * @param mixed $value Valor candidato.
	 * @return int|null
	 */
	private static function non_negative_int( mixed $value ): ?int {
		$number = self::signed_amount( $value );

		return null !== $number && 0 <= $number ? $number : null;
	}

	/**
	 * Rechaza listas con IDs desconocidos en lugar de corregirlas en silencio.
	 *
	 * @param mixed    $value   Lista candidata.
	 * @param string[] $allowed IDs permitidos.
	 * @return string[]|WP_Error
	 */
	private static function controlled_list( mixed $value, array $allowed ): array|WP_Error {
		if ( ! is_array( $value ) ) {
			return self::invalid();
		}

		$raw = array_values( array_unique( array_map( 'sanitize_key', wp_unslash( $value ) ) ) );

		if ( array() !== array_diff( $raw, $allowed ) ) {
			return self::invalid();
		}

		sort( $raw, SORT_STRING );

		return $raw;
	}

	/**
	 * Convierte escalares en texto sin aceptar estructuras.
	 *
	 * @param mixed $value Valor candidato.
	 * @return string
	 */
	private static function scalar( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Devuelve el error contractual común.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error(
			'vicu_restaurante_invalid_request',
			__( 'Los datos del catálogo no son válidos.', 'vicunav-restaurante' ),
			array( 'status' => 400 )
		);
	}
}
