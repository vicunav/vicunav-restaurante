<?php
/**
 * Endpoint público de cotización de pizzas.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Rest;

use Vicu\Core\Rest;
use Vicu\Restaurante\Pizza\PizzaConfigurationValidator;
use Vicu\Restaurante\Pizza\PizzaPricingService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Expone un quote sin aceptar precios del cliente ni crear estado persistente.
 */
final class PizzaQuoteRoute {
	/**
	 * Evita hooks duplicados.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Enlaza el registro REST.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		self::$hooks_registered = true;
	}

	/**
	 * Registra la ruta mediante el namespace público de core.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		Rest::register_route(
			'/restaurante/pizza/quote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'quote' ),
				'permission_callback' => array( self::class, 'allow_public_quote' ),
				'schema'              => array( self::class, 'response_schema' ),
			)
		);
	}

	/**
	 * Permite conectar una política de rate limit sin almacenar IPs en el dominio.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return bool|WP_Error
	 */
	public static function allow_public_quote( WP_REST_Request $request ): bool|WP_Error {
		/**
		 * Filtra si una cotización pública puede continuar.
		 *
		 * @since 0.6.0
		 *
		 * @param bool            $allowed Estado predeterminado.
		 * @param WP_REST_Request $request Solicitud actual.
		 */
		$allowed = (bool) apply_filters( 'vicu_restaurante_allow_public_quote', true, $request );

		if ( $allowed ) {
			return true;
		}

		return new WP_Error(
			'vicu_restaurante_rate_limited',
			__( 'Intenta cotizar nuevamente más tarde.', 'vicunav-restaurante' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Calcula el desglose vigente.
	 *
	 * @param WP_REST_Request $request Solicitud validada por REST.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function quote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$configuration = $request->get_param( 'configuration' );

		if ( ! is_array( $configuration ) ) {
			return new WP_Error(
				'vicu_restaurante_invalid_request',
				__( 'La configuración de pizza no es válida.', 'vicunav-restaurante' ),
				array( 'status' => 400 )
			);
		}

		$result = PizzaPricingService::quote( $configuration );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = new WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}

	/**
	 * Schema de respuesta estable.
	 *
	 * @return array<string, mixed>
	 */
	public static function response_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'vicu_restaurante_pizza_quote',
			'type'       => 'object',
			'required'   => array( 'configuration', 'catalog_revision', 'currency', 'components', 'unit_total_minor', 'quantity', 'total_minor' ),
			'properties' => array(
				'configuration'    => self::configuration_schema(),
				'catalog_revision' => self::positive_integer_schema(),
				'currency'         => array(
					'type'    => 'string',
					'pattern' => '^[A-Z]{3}$',
				),
				'components'       => array(
					'type'       => 'object',
					'required'   => array( 'size', 'crust', 'sauce', 'cheese', 'toppings', 'toppings_modifier_minor' ),
					'properties' => array(
						'size'                    => self::component_schema(),
						'crust'                   => self::component_schema(),
						'sauce'                   => self::component_schema(),
						'cheese'                  => self::component_schema(),
						'toppings'                => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'required'   => array( 'ingredient_id', 'name', 'zone', 'amount_minor' ),
								'properties' => array(
									'ingredient_id' => self::uuid_schema(),
									'name'          => array( 'type' => 'string' ),
									'zone'          => self::zone_schema(),
									'amount_minor'  => array( 'type' => 'integer' ),
								),
							),
						),
						'toppings_modifier_minor' => array( 'type' => 'integer' ),
					),
				),
				'unit_total_minor' => self::positive_integer_schema(),
				'quantity'         => self::positive_integer_schema(),
				'total_minor'      => self::positive_integer_schema(),
			),
		);
	}

	/**
	 * Schema de pizza_configuration v1.
	 *
	 * @return array<string, mixed>
	 */
	public static function configuration_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'version', 'catalog_revision', 'size_id', 'crust_id', 'sauce_id', 'cheese_ingredient_id', 'toppings', 'quantity' ),
			'properties' => array(
				'version'              => array(
					'type' => 'integer',
					'enum' => array( PizzaConfigurationValidator::VERSION ),
				),
				'catalog_revision'     => self::positive_integer_schema(),
				'size_id'              => self::uuid_schema(),
				'crust_id'             => self::uuid_schema(),
				'sauce_id'             => self::uuid_schema(),
				'cheese_ingredient_id' => self::uuid_schema(),
				'toppings'             => array(
					'type'                 => 'object',
					'maxProperties'        => PizzaConfigurationValidator::MAX_TOPPINGS,
					'additionalProperties' => self::zone_schema(),
				),
				'quantity'             => self::positive_integer_schema(),
			),
		);
	}

	/**
	 * Schema de componente con importe entero.
	 *
	 * @return array<string, mixed>
	 */
	private static function component_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'public_id', 'name', 'amount_minor' ),
			'properties' => array(
				'public_id'    => self::uuid_schema(),
				'name'         => array( 'type' => 'string' ),
				'amount_minor' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * Schema de UUID v4.
	 *
	 * @return array<string, string>
	 */
	private static function uuid_schema(): array {
		return array(
			'type'   => 'string',
			'format' => 'uuid',
		);
	}

	/**
	 * Schema de zona exclusiva.
	 *
	 * @return array<string, mixed>
	 */
	private static function zone_schema(): array {
		return array(
			'type' => 'string',
			'enum' => PizzaConfigurationValidator::ZONES,
		);
	}

	/**
	 * Schema de entero positivo.
	 *
	 * @return array<string, int|string>
	 */
	private static function positive_integer_schema(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 1,
		);
	}
}
