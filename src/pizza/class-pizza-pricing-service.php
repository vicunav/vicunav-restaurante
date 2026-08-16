<?php
/**
 * Cotización autoritativa de pizzas.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Pizza;

use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Catalog\CatalogDatabase;
use Vicu\Restaurante\Catalog\IngredientService;
use Vicu\Restaurante\Catalog\PizzaOptionService;
use Vicu\Restaurante\Settings\RestaurantSettings;
use WP_Error;

/**
 * Resuelve referencias vivas y calcula únicamente con enteros del servidor.
 */
final class PizzaPricingService {
	/**
	 * Valida y cotiza una configuración completa.
	 *
	 * @param array<string, mixed> $input Configuración candidata.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function quote( array $input ): array|WP_Error {
		$configuration = PizzaConfigurationValidator::normalize( $input );

		if ( is_wp_error( $configuration ) ) {
			return $configuration;
		}

		$current_revision = AvailabilityRevision::current();

		if ( $configuration['catalog_revision'] !== $current_revision ) {
			return CatalogDatabase::stale_error( $current_revision );
		}

		$size   = self::option( $configuration['size_id'], 'size' );
		$crust  = self::option( $configuration['crust_id'], 'crust' );
		$sauce  = self::option( $configuration['sauce_id'], 'sauce' );
		$cheese = self::ingredient( $configuration['cheese_ingredient_id'], 'cheese' );

		foreach ( array( $size, $crust, $sauce, $cheese ) as $selection ) {
			if ( is_wp_error( $selection ) ) {
				return $selection;
			}
		}

		$topping_components = array();
		$toppings_minor     = 0;

		foreach ( $configuration['toppings'] as $ingredient_id => $zone ) {
			$topping = self::ingredient( $ingredient_id, 'topping' );

			if ( is_wp_error( $topping ) ) {
				return $topping;
			}

			$toppings_minor = self::safe_add( $toppings_minor, $topping['price_modifier_minor'] );

			if ( null === $toppings_minor ) {
				return self::invalid_total();
			}

			$topping_components[] = array(
				'ingredient_id' => $ingredient_id,
				'name'          => $topping['name'],
				'zone'          => $zone,
				'amount_minor'  => $topping['price_modifier_minor'],
			);
		}

		$unit_total = self::sum(
			array(
				$size['price_modifier_minor'],
				$crust['price_modifier_minor'],
				$cheese['price_modifier_minor'],
				$toppings_minor,
			)
		);

		if ( null === $unit_total || 0 >= $unit_total || $unit_total > intdiv( PHP_INT_MAX, $configuration['quantity'] ) ) {
			return self::invalid_total();
		}

		return array(
			'configuration'    => $configuration,
			'catalog_revision' => $current_revision,
			'currency'         => RestaurantSettings::currency(),
			'components'       => array(
				'size'                    => self::option_component( $size, $size['price_modifier_minor'] ),
				'crust'                   => self::option_component( $crust, $crust['price_modifier_minor'] ),
				'sauce'                   => self::option_component( $sauce, 0 ),
				'cheese'                  => self::ingredient_component( $cheese, $cheese['price_modifier_minor'] ),
				'toppings'                => $topping_components,
				'toppings_modifier_minor' => $toppings_minor,
			),
			'unit_total_minor' => $unit_total,
			'quantity'         => $configuration['quantity'],
			'total_minor'      => $unit_total * $configuration['quantity'],
		);
	}

	/**
	 * Resuelve una opción disponible del tipo exacto.
	 *
	 * @param string $public_id UUID.
	 * @param string $type      Tipo requerido.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function option( string $public_id, string $type ): array|WP_Error {
		$option = PizzaOptionService::find( $public_id );

		return null !== $option && $type === $option['type'] && $option['available'] ? $option : self::unavailable();
	}

	/**
	 * Resuelve un ingrediente disponible de la categoría exacta.
	 *
	 * @param string $public_id UUID.
	 * @param string $category  Categoría requerida.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function ingredient( string $public_id, string $category ): array|WP_Error {
		$ingredient = IngredientService::find( $public_id );

		return null !== $ingredient && $category === $ingredient['category'] && $ingredient['available'] ? $ingredient : self::unavailable();
	}

	/**
	 * Suma importes sin desbordar enteros.
	 *
	 * @param int[] $amounts Importes.
	 * @return int|null
	 */
	private static function sum( array $amounts ): ?int {
		$total = 0;

		foreach ( $amounts as $amount ) {
			$total = self::safe_add( $total, $amount );

			if ( null === $total ) {
				return null;
			}
		}

		return $total;
	}

	/**
	 * Suma dos enteros con comprobación explícita.
	 *
	 * @param int $left  Acumulado.
	 * @param int $right Sumando.
	 * @return int|null
	 */
	private static function safe_add( int $left, int $right ): ?int {
		if ( ( 0 < $right && $left > PHP_INT_MAX - $right ) || ( 0 > $right && $left < PHP_INT_MIN - $right ) ) {
			return null;
		}

		return $left + $right;
	}

	/**
	 * Proyecta una opción con el importe realmente cobrado.
	 *
	 * @param array<string, mixed> $option       Opción.
	 * @param int                  $amount_minor Importe aplicado.
	 * @return array<string, mixed>
	 */
	private static function option_component( array $option, int $amount_minor ): array {
		return array(
			'public_id'    => $option['public_id'],
			'name'         => $option['name'],
			'amount_minor' => $amount_minor,
		);
	}

	/**
	 * Proyecta el queso con el importe realmente cobrado.
	 *
	 * @param array<string, mixed> $ingredient   Ingrediente.
	 * @param int                  $amount_minor Importe aplicado.
	 * @return array<string, mixed>
	 */
	private static function ingredient_component( array $ingredient, int $amount_minor ): array {
		return array(
			'public_id'    => $ingredient['public_id'],
			'name'         => $ingredient['name'],
			'amount_minor' => $amount_minor,
		);
	}

	/**
	 * Error indistinguible para selección ausente, agotada o de tipo incorrecto.
	 *
	 * @return WP_Error
	 */
	private static function unavailable(): WP_Error {
		return new WP_Error(
			'vicu_restaurante_unavailable',
			__( 'Una selección de la pizza ya no está disponible.', 'vicunav-restaurante' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Falla cerrado ante una configuración de importes no cotizable.
	 *
	 * @return WP_Error
	 */
	private static function invalid_total(): WP_Error {
		return new WP_Error(
			'vicu_restaurante_unavailable',
			__( 'La pizza no se puede cotizar con la configuración vigente.', 'vicunav-restaurante' ),
			array( 'status' => 409 )
		);
	}
}
