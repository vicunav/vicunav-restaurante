<?php
/**
 * Lectura pública de zonas de entrega.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Rest;

use Vicu\Core\Rest;
use Vicu\Restaurante\Commerce\DeliveryZoneService;
use Vicu\Restaurante\Commerce\PricingRevision;
use Vicu\Restaurante\Settings\RestaurantSettings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Publica únicamente zonas activas con tarifa resuelta por servidor.
 */
final class DeliveryZonesRoute {
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
	 * Registra la colección pública.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		Rest::register_route(
			'/restaurante/delivery-zones',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_zones' ),
				'permission_callback' => '__return_true',
				'schema'              => array( self::class, 'schema' ),
			)
		);
	}

	/**
	 * Devuelve zonas activas o 304.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response
	 */
	public static function get_zones( WP_REST_Request $request ): WP_REST_Response {
		$revision = PricingRevision::current();
		$etag     = '"vicu-delivery-zones-' . $revision . '"';

		if ( hash_equals( $etag, trim( (string) $request->get_header( 'if-none-match' ) ) ) ) {
			return self::response( null, 304, $etag );
		}

		$zones = array_map(
			static function ( array $zone ): array {
				unset( $zone['active'] );

				return $zone;
			},
			DeliveryZoneService::all( true )
		);

		return self::response(
			array(
				'revision' => $revision,
				'currency' => RestaurantSettings::currency(),
				'zones'    => $zones,
			),
			200,
			$etag
		);
	}

	/**
	 * Schema de la colección pública.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'vicu_restaurante_delivery_zones',
			'type'       => 'object',
			'required'   => array( 'revision', 'currency', 'zones' ),
			'properties' => array(
				'revision' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'currency' => array(
					'type'    => 'string',
					'pattern' => '^[A-Z]{3}$',
				),
				'zones'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'required'   => array( 'public_id', 'name', 'fee_minor', 'eta_min_minutes', 'eta_max_minutes', 'display_order', 'revision' ),
						'properties' => array(
							'public_id'       => array(
								'type'   => 'string',
								'format' => 'uuid',
							),
							'name'            => array( 'type' => 'string' ),
							'fee_minor'       => self::non_negative_integer(),
							'eta_min_minutes' => self::non_negative_integer(),
							'eta_max_minutes' => self::non_negative_integer(),
							'display_order'   => self::non_negative_integer(),
							'revision'        => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Construye respuesta cacheable ligada a revisión.
	 *
	 * @param array<string, mixed>|null $data   Payload.
	 * @param int                       $status Estado.
	 * @param string                    $etag   ETag.
	 * @return WP_REST_Response
	 */
	private static function response( ?array $data, int $status, string $etag ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', 'public, max-age=60, stale-while-revalidate=300' );

		return $response;
	}

	/**
	 * Schema de entero no negativo.
	 *
	 * @return array<string, int|string>
	 */
	private static function non_negative_integer(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 0,
		);
	}
}
