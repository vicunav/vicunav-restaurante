<?php
/**
 * Rutas públicas del menú.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Rest;

use Vicu\Core\Rest;
use Vicu\Restaurante\Menu\CatalogRepository;
use Vicu\Restaurante\Menu\CatalogRevision;
use Vicu\Restaurante\Menu\MenuMeta;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Publica lecturas cacheables sin exponer post meta ni IDs internos.
 */
final class MenuRoutes {
	private const CACHE_CONTROL = 'public, max-age=60, stale-while-revalidate=300';

	/**
	 * Evita duplicar el hook de registro.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Enlaza las rutas al lifecycle REST.
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
	 * Registra colección y detalle mediante el contrato de core.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		Rest::register_route(
			'/restaurante/menu',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_collection' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'category' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => array( self::class, 'validate_category' ),
					),
				),
				'schema'              => array( self::class, 'get_collection_schema' ),
			)
		);

		Rest::register_route(
			'/restaurante/menu/(?P<public_id>[a-f0-9-]{36})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_item' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'public_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( MenuMeta::class, 'sanitize_public_id' ),
						'validate_callback' => array( self::class, 'validate_public_id' ),
					),
				),
				'schema'              => array( self::class, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Devuelve el catálogo completo o filtrado.
	 *
	 * @param WP_REST_Request $request Solicitud pública.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_collection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$category   = sanitize_key( (string) $request->get_param( 'category' ) );
		$repository = new CatalogRepository();

		if ( '' !== $category && ! $repository->has_visible_category( $category ) ) {
			return new WP_Error(
				'vicu_restaurante_invalid_request',
				__( 'La categoría solicitada no es válida.', 'vicunav-restaurante' ),
				array( 'status' => 400 )
			);
		}

		$etag = self::etag( 'collection:' . ( '' === $category ? 'all' : $category ) );

		if ( self::matches_etag( $request, $etag ) ) {
			return self::not_modified( $etag );
		}

		return self::cacheable_response( $repository->all( $category ), $etag );
	}

	/**
	 * Devuelve un item por UUID público o un 404 no enumerable.
	 *
	 * @param WP_REST_Request $request Solicitud pública.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$public_id = MenuMeta::sanitize_public_id( $request->get_param( 'public_id' ) );
		$item      = ( new CatalogRepository() )->find( $public_id );

		if ( null === $item ) {
			return new WP_Error(
				'vicu_restaurante_not_found',
				__( 'No se encontró el elemento solicitado.', 'vicunav-restaurante' ),
				array( 'status' => 404 )
			);
		}

		$etag = self::etag( 'item:' . $public_id );

		if ( self::matches_etag( $request, $etag ) ) {
			return self::not_modified( $etag );
		}

		return self::cacheable_response(
			array(
				'revision' => CatalogRevision::current(),
				'item'     => $item,
			),
			$etag
		);
	}

	/**
	 * Valida un slug acotado antes de consultar WordPress.
	 *
	 * @param mixed $value Valor recibido.
	 * @return bool
	 */
	public static function validate_category( mixed $value ): bool {
		return is_string( $value ) && sanitize_key( $value ) === $value && 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $value );
	}

	/**
	 * Valida que el detalle reciba un UUID v4 canónico.
	 *
	 * @param mixed $value Valor recibido.
	 * @return bool
	 */
	public static function validate_public_id( mixed $value ): bool {
		return is_string( $value ) && '' !== MenuMeta::sanitize_public_id( $value );
	}

	/**
	 * Schema público de la colección.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_collection_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'vicu_restaurante_menu',
			'type'       => 'object',
			'required'   => array( 'revision', 'categories', 'items' ),
			'properties' => array(
				'revision'   => array(
					'type'     => 'integer',
					'minimum'  => 1,
					'readonly' => true,
				),
				'categories' => array(
					'type'     => 'array',
					'items'    => self::category_schema(),
					'readonly' => true,
				),
				'items'      => array(
					'type'     => 'array',
					'items'    => self::item_schema(),
					'readonly' => true,
				),
			),
		);
	}

	/**
	 * Schema público del detalle.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'vicu_restaurante_menu_item_response',
			'type'       => 'object',
			'required'   => array( 'revision', 'item' ),
			'properties' => array(
				'revision' => array(
					'type'     => 'integer',
					'minimum'  => 1,
					'readonly' => true,
				),
				'item'     => self::item_schema(),
			),
		);
	}

	/**
	 * Construye una respuesta pública con validadores HTTP.
	 *
	 * @param array<string, mixed> $data Payload público.
	 * @param string               $etag ETag fuerte.
	 * @return WP_REST_Response
	 */
	private static function cacheable_response( array $data, string $etag ): WP_REST_Response {
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', self::CACHE_CONTROL );

		return $response;
	}

	/**
	 * Devuelve 304 conservando los validadores de caché.
	 *
	 * @param string $etag ETag vigente.
	 * @return WP_REST_Response
	 */
	private static function not_modified( string $etag ): WP_REST_Response {
		$response = new WP_REST_Response( null, 304 );
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', self::CACHE_CONTROL );

		return $response;
	}

	/**
	 * Compara el validador exacto sin interpretar datos privados.
	 *
	 * @param WP_REST_Request $request Solicitud pública.
	 * @param string          $etag    ETag vigente.
	 * @return bool
	 */
	private static function matches_etag( WP_REST_Request $request, string $etag ): bool {
		return hash_equals( $etag, trim( (string) $request->get_header( 'if-none-match' ) ) );
	}

	/**
	 * Deriva un ETag estable de la revisión y el recurso.
	 *
	 * @param string $scope Recurso lógico.
	 * @return string
	 */
	private static function etag( string $scope ): string {
		return '"vicu-menu-' . CatalogRevision::current() . '-' . md5( $scope ) . '"';
	}

	/**
	 * Schema congelado de una categoría.
	 *
	 * @return array<string, mixed>
	 */
	private static function category_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'slug', 'name', 'description', 'order' ),
			'properties'           => array(
				'slug'        => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'name'        => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'description' => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'order'       => array(
					'type'     => 'integer',
					'minimum'  => 0,
					'readonly' => true,
				),
			),
		);
	}

	/**
	 * Schema congelado de un item público.
	 *
	 * @return array<string, mixed>
	 */
	private static function item_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'public_id', 'name', 'description', 'story', 'price_minor', 'currency', 'available', 'calories_kcal', 'allergens', 'dietary_tags', 'category', 'order', 'image' ),
			'properties'           => array(
				'public_id'     => array(
					'type'     => 'string',
					'format'   => 'uuid',
					'readonly' => true,
				),
				'name'          => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'description'   => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'story'         => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'price_minor'   => array(
					'type'     => 'integer',
					'minimum'  => 0,
					'readonly' => true,
				),
				'currency'      => array(
					'type'     => 'string',
					'pattern'  => '^[A-Z]{3}$',
					'readonly' => true,
				),
				'available'     => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
				'calories_kcal' => array(
					'type'     => 'integer',
					'minimum'  => 0,
					'readonly' => true,
				),
				'allergens'     => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => MenuMeta::allergen_ids(),
					),
					'uniqueItems' => true,
					'readonly'    => true,
				),
				'dietary_tags'  => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => MenuMeta::dietary_tag_ids(),
					),
					'uniqueItems' => true,
					'readonly'    => true,
				),
				'category'      => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'order'         => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'image'         => array(
					'type'                 => array( 'object', 'null' ),
					'additionalProperties' => false,
					'required'             => array( 'url', 'alt', 'width', 'height' ),
					'properties'           => array(
						'url'    => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'alt'    => array( 'type' => 'string' ),
						'width'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'height' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'readonly'             => true,
				),
			),
		);
	}
}
