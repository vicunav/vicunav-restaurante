<?php
/**
 * Lecturas públicas de ingredientes y opciones.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Rest;

use Vicu\Core\Rest;
use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Catalog\IngredientService;
use Vicu\Restaurante\Catalog\PizzaOptionService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Publica una sola revisión para builder, administración y revalidación.
 */
final class CatalogRoutes {
	/**
	 * Evita duplicar hooks.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Enlaza el registro al lifecycle REST.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		self::$hooks_registered = true;
	}

	/**
	 * Registra ambos endpoints mediante core.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		Rest::register_route(
			'/restaurante/ingredients/availability',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_availability' ),
				'permission_callback' => '__return_true',
				'schema'              => array( self::class, 'availability_schema' ),
			)
		);

		Rest::register_route(
			'/restaurante/pizza/options',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_pizza_options' ),
				'permission_callback' => '__return_true',
				'schema'              => array( self::class, 'pizza_options_schema' ),
			)
		);
	}

	/**
	 * Devuelve disponibilidad liviana, incluidas referencias no disponibles.
	 *
	 * @param WP_REST_Request $request Solicitud pública.
	 * @return WP_REST_Response
	 */
	public static function get_availability( WP_REST_Request $request ): WP_REST_Response {
		$revision = AvailabilityRevision::current();
		$etag     = self::etag( 'availability', $revision );

		if ( self::matches( $request, $etag ) ) {
			return self::response( null, 304, $etag, 'no-cache, max-age=0, must-revalidate' );
		}

		$ingredients = array_map(
			static function ( array $ingredient ): array {
				return array(
					'public_id' => $ingredient['public_id'],
					'available' => $ingredient['available'],
					'revision'  => $ingredient['revision'],
				);
			},
			IngredientService::all()
		);

		return self::response(
			array(
				'revision'    => $revision,
				'ingredients' => $ingredients,
			),
			200,
			$etag,
			'no-cache, max-age=0, must-revalidate'
		);
	}

	/**
	 * Agrupa opciones e ingredientes del builder sin calcular precios.
	 *
	 * @param WP_REST_Request $request Solicitud pública.
	 * @return WP_REST_Response
	 */
	public static function get_pizza_options( WP_REST_Request $request ): WP_REST_Response {
		$revision = AvailabilityRevision::current();
		$etag     = self::etag( 'pizza-options', $revision );

		if ( self::matches( $request, $etag ) ) {
			return self::response( null, 304, $etag, 'public, max-age=60, stale-while-revalidate=300' );
		}

		$cache_key = 'pizza-options:' . $revision;
		$data      = wp_cache_get( $cache_key, 'vicu_restaurante_catalog' );

		if ( ! is_array( $data ) ) {
			$data = array(
				'revision' => $revision,
				'sizes'    => array(),
				'crusts'   => array(),
				'sauces'   => array(),
				'cheeses'  => array(),
				'toppings' => array(),
			);

			foreach ( PizzaOptionService::all() as $option ) {
				$key            = $option['type'] . 's';
				$data[ $key ][] = $option;
			}

			foreach ( IngredientService::all() as $ingredient ) {
				if ( 'cheese' === $ingredient['category'] ) {
					$data['cheeses'][] = $ingredient;
				} elseif ( 'topping' === $ingredient['category'] ) {
					$data['toppings'][] = $ingredient;
				}
			}

			wp_cache_set( $cache_key, $data, 'vicu_restaurante_catalog', 300 );
		}

		return self::response( $data, 200, $etag, 'public, max-age=60, stale-while-revalidate=300' );
	}

	/**
	 * Schema de disponibilidad liviana.
	 *
	 * @return array<string, mixed>
	 */
	public static function availability_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'vicu_restaurante_ingredient_availability',
			'type'       => 'object',
			'required'   => array( 'revision', 'ingredients' ),
			'properties' => array(
				'revision'    => self::revision_schema(),
				'ingredients' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'required'   => array( 'public_id', 'available', 'revision' ),
						'properties' => array(
							'public_id' => array(
								'type'   => 'string',
								'format' => 'uuid',
							),
							'available' => array( 'type' => 'boolean' ),
							'revision'  => self::revision_schema(),
						),
					),
				),
			),
		);
	}

	/**
	 * Schema congelado del catálogo del builder.
	 *
	 * @return array<string, mixed>
	 */
	public static function pizza_options_schema(): array {
		$properties = array( 'revision' => self::revision_schema() );

		foreach ( array( 'sizes', 'crusts', 'sauces' ) as $key ) {
			$properties[ $key ] = array(
				'type'  => 'array',
				'items' => self::option_schema(),
			);
		}

		foreach ( array( 'cheeses', 'toppings' ) as $key ) {
			$properties[ $key ] = array(
				'type'  => 'array',
				'items' => self::ingredient_schema(),
			);
		}

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'vicu_restaurante_pizza_options',
			'type'       => 'object',
			'required'   => array_keys( $properties ),
			'properties' => $properties,
		);
	}

	/**
	 * Construye la respuesta y sus validadores.
	 *
	 * @param array<string, mixed>|null $data          Payload o null para 304.
	 * @param int                       $status        Estado HTTP.
	 * @param string                    $etag          ETag vigente.
	 * @param string                    $cache_control Política HTTP.
	 * @return WP_REST_Response
	 */
	private static function response( ?array $data, int $status, string $etag, string $cache_control ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', $cache_control );

		return $response;
	}

	/**
	 * Compara el ETag condicional.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @param string          $etag    ETag vigente.
	 * @return bool
	 */
	private static function matches( WP_REST_Request $request, string $etag ): bool {
		return hash_equals( $etag, trim( (string) $request->get_header( 'if-none-match' ) ) );
	}

	/**
	 * Construye el validador desde scope y revisión.
	 *
	 * @param string $scope    Recurso lógico.
	 * @param int    $revision Revisión vigente.
	 * @return string
	 */
	private static function etag( string $scope, int $revision ): string {
		return '"vicu-' . $scope . '-' . $revision . '"';
	}

	/**
	 * Schema de revisión positiva.
	 *
	 * @return array<string, mixed>
	 */
	private static function revision_schema(): array {
		return array(
			'type'     => 'integer',
			'minimum'  => 1,
			'readonly' => true,
		);
	}

	/**
	 * Schema de una opción.
	 *
	 * @return array<string, mixed>
	 */
	private static function option_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'public_id', 'type', 'name', 'price_modifier_minor', 'available', 'display_order', 'revision' ),
			'properties' => array(
				'public_id'            => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'type'                 => array(
					'type' => 'string',
					'enum' => array( 'crust', 'sauce', 'size' ),
				),
				'name'                 => array( 'type' => 'string' ),
				'price_modifier_minor' => array( 'type' => 'integer' ),
				'available'            => array( 'type' => 'boolean' ),
				'display_order'        => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'revision'             => self::revision_schema(),
			),
		);
	}

	/**
	 * Schema de un ingrediente del builder.
	 *
	 * @return array<string, mixed>
	 */
	private static function ingredient_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'public_id', 'name', 'category', 'price_modifier_minor', 'available', 'allergens', 'dietary_tags', 'revision' ),
			'properties' => array(
				'public_id'            => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'name'                 => array( 'type' => 'string' ),
				'category'             => array(
					'type' => 'string',
					'enum' => array( 'cheese', 'topping' ),
				),
				'price_modifier_minor' => array( 'type' => 'integer' ),
				'available'            => array( 'type' => 'boolean' ),
				'allergens'            => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'dietary_tags'         => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'revision'             => self::revision_schema(),
			),
		);
	}
}
