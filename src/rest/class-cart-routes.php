<?php
/**
 * API privada de sesiones y carritos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Rest;

use Vicu\Core\Rest;
use Vicu\Restaurante\Cart\CartAuthentication;
use Vicu\Restaurante\Cart\CartService;
use Vicu\Restaurante\Cart\CartSessionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Publica únicamente la proyección propia, sin secretos ni IDs internos.
 */
final class CartRoutes {
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

		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		self::$hooks_registered = true;
	}

	/**
	 * Registra la superficie contractual completa de REST-02H.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		Rest::register_route(
			'/restaurante/carts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create_cart' ),
				'permission_callback' => array( self::class, 'allow_create' ),
				'schema'              => array( self::class, 'cart_schema' ),
			)
		);

		Rest::register_route(
			'/restaurante/cart',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_cart' ),
				'permission_callback' => array( self::class, 'allow_read' ),
				'schema'              => array( self::class, 'cart_schema' ),
			)
		);

		Rest::register_route(
			'/restaurante/cart/items',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'add_item' ),
				'permission_callback' => array( self::class, 'allow_write' ),
				'args'                => self::item_args(),
				'schema'              => array( self::class, 'cart_schema' ),
			)
		);

		Rest::register_route(
			'/restaurante/cart/items/(?P<line_id>[a-f0-9-]{36})',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( self::class, 'replace_item' ),
					'permission_callback' => array( self::class, 'allow_write' ),
					'args'                => array_merge( self::line_mutation_args(), self::item_args() ),
					'schema'              => array( self::class, 'cart_schema' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'remove_item' ),
					'permission_callback' => array( self::class, 'allow_write' ),
					'args'                => self::line_mutation_args(),
					'schema'              => array( self::class, 'cart_schema' ),
				),
			)
		);

		Rest::register_route(
			'/restaurante/cart/discount',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::class, 'apply_discount' ),
					'permission_callback' => array( self::class, 'allow_write' ),
					'args'                => array_merge(
						self::mutation_args(),
						array(
							'code' => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 64,
								'required'  => true,
							),
						)
					),
					'schema'              => array( self::class, 'cart_schema' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'remove_discount' ),
					'permission_callback' => array( self::class, 'allow_write' ),
					'args'                => self::mutation_args(),
					'schema'              => array( self::class, 'cart_schema' ),
				),
			)
		);

		Rest::register_route(
			'/restaurante/cart/fulfillment',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'set_fulfillment' ),
				'permission_callback' => array( self::class, 'allow_write' ),
				'args'                => array_merge(
					self::mutation_args(),
					array(
						'fulfillment'      => array(
							'type'     => 'string',
							'enum'     => array( 'pickup', 'delivery' ),
							'required' => true,
						),
						'delivery_zone_id' => array(
							'type'     => 'string',
							'format'   => 'uuid',
							'required' => false,
						),
					)
				),
				'schema'              => array( self::class, 'cart_schema' ),
			)
		);

		Rest::register_route(
			'/restaurante/cart/tip',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'set_tip' ),
				'permission_callback' => array( self::class, 'allow_write' ),
				'args'                => array_merge(
					self::mutation_args(),
					array(
						'tip_rate_bps' => array(
							'type'     => 'integer',
							'minimum'  => 0,
							'maximum'  => 10000,
							'required' => true,
						),
					)
				),
				'schema'              => array( self::class, 'cart_schema' ),
			)
		);
	}

	/**
	 * Limita la creación pública y exige origen o nonce según identidad.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return bool|WP_Error
	 */
	public static function allow_create( WP_REST_Request $request ): bool|WP_Error {
		/**
		 * Filtra si se permite crear o recuperar una sesión de carrito.
		 *
		 * @since 0.8.0
		 *
		 * @param bool            $allowed Estado inicial.
		 * @param WP_REST_Request $request Solicitud.
		 */
		if ( ! (bool) apply_filters( 'vicu_restaurante_allow_cart_creation', true, $request ) ) {
			return new WP_Error( 'vicu_restaurante_rate_limited', __( 'Intenta crear el carrito nuevamente más tarde.', 'vicunav-restaurante' ), array( 'status' => 429 ) );
		}

		if ( 0 < get_current_user_id() ) {
			$identity = CartAuthentication::resolve( $request, false );

			return is_wp_error( $identity ) ? $identity : true;
		}

		return CartAuthentication::same_origin( $request ) ? true : new WP_Error( 'vicu_restaurante_forbidden', __( 'El origen de la solicitud no es válido.', 'vicunav-restaurante' ), array( 'status' => 403 ) );
	}

	/**
	 * Verifica identidad de lectura.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return bool|WP_Error
	 */
	public static function allow_read( WP_REST_Request $request ): bool|WP_Error {
		$identity = CartAuthentication::resolve( $request, false );

		return is_wp_error( $identity ) ? $identity : true;
	}

	/**
	 * Verifica nonce o sesión, CSRF y origen.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return bool|WP_Error
	 */
	public static function allow_write( WP_REST_Request $request ): bool|WP_Error {
		$identity = CartAuthentication::resolve( $request, true );

		return is_wp_error( $identity ) ? $identity : true;
	}

	/**
	 * Crea o recupera carrito sin publicar la credencial de cookie.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_cart( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( 0 < get_current_user_id() ) {
			$identity = CartAuthentication::resolve( $request, false );
			$session  = isset( $_COOKIE[ CartSessionService::COOKIE_NAME ] )
				? CartSessionService::resolve_anonymous( sanitize_text_field( wp_unslash( (string) $_COOKIE[ CartSessionService::COOKIE_NAME ] ) ) )
				: null;
		} else {
			$identity = isset( $_COOKIE[ CartSessionService::COOKIE_NAME ] ) ? CartAuthentication::resolve( $request, false ) : CartSessionService::create_anonymous();

			if ( is_wp_error( $identity ) ) {
				$identity = CartSessionService::create_anonymous();
			}

			if ( ! is_wp_error( $identity ) && isset( $identity['credential'] ) ) {
				CartSessionService::send_cookie( (string) $identity['credential'], (string) $identity['expires_at'] );
			}
		}

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$cart = isset( $session ) && is_array( $session )
			? CartService::associate( $session, $identity )
			: CartService::create( $identity );

		return is_wp_error( $cart ) ? $cart : self::response( self::with_csrf( $cart, $identity ), 201 );
	}

	/**
	 * Devuelve el carrito propio.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_cart( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$identity = CartAuthentication::resolve( $request, false );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$cart = CartService::get( $identity );

		return is_wp_error( $cart ) ? $cart : self::response( self::with_csrf( $cart, $identity ) );
	}

	/**
	 * Añade una línea.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = $request->get_param( 'item' );

		return is_array( $input ) ? self::mutation( $request, static fn( array $identity, int $revision ) => CartService::add_item( $identity, $revision, $input ) ) : self::invalid();
	}

	/**
	 * Sustituye una línea completa.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function replace_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = $request->get_param( 'item' );

		return is_array( $input ) ? self::mutation( $request, static fn( array $identity, int $revision ) => CartService::replace_item( $identity, $revision, (string) $request['line_id'], $input ) ) : self::invalid();
	}

	/**
	 * Elimina una línea.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::mutation( $request, static fn( array $identity, int $revision ) => CartService::remove_item( $identity, $revision, (string) $request['line_id'] ) );
	}

	/**
	 * Aplica un descuento validado.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function apply_discount( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$code = $request->get_param( 'code' );

		return is_string( $code ) && '' !== trim( $code ) ? self::mutation( $request, static fn( array $identity, int $revision ) => CartService::set_discount( $identity, $revision, $code ) ) : self::invalid();
	}

	/**
	 * Retira el descuento.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove_discount( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::mutation( $request, static fn( array $identity, int $revision ) => CartService::set_discount( $identity, $revision, null ) );
	}

	/**
	 * Define pickup o delivery.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_fulfillment( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$fulfillment = sanitize_key( (string) $request->get_param( 'fulfillment' ) );
		$zone        = $request->get_param( 'delivery_zone_id' );
		$zone        = is_string( $zone ) && '' !== $zone ? $zone : null;

		return self::mutation( $request, static fn( array $identity, int $revision ) => CartService::set_fulfillment( $identity, $revision, $fulfillment, $zone ) );
	}

	/**
	 * Define una propina configurada.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_tip( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$value = $request->get_param( 'tip_rate_bps' );

		return is_numeric( $value ) ? self::mutation( $request, static fn( array $identity, int $revision ) => CartService::set_tip( $identity, $revision, (int) $value ) ) : self::invalid();
	}

	/**
	 * Ejecuta una mutación ya autorizada y añade metadatos privados.
	 *
	 * @param WP_REST_Request                                 $request   Solicitud.
	 * @param callable(array<string, int|string>, int): mixed $operation Operación.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function mutation( WP_REST_Request $request, callable $operation ): WP_REST_Response|WP_Error {
		$identity = CartAuthentication::resolve( $request, true );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$revision = $request->get_param( 'expected_revision' );

		if ( ! is_numeric( $revision ) || 1 > (int) $revision ) {
			return self::invalid();
		}

		$cart = $operation( $identity, (int) $revision );

		return is_wp_error( $cart ) ? $cart : self::response( self::with_csrf( $cart, $identity ) );
	}

	/**
	 * Añade el CSRF derivado solo a respuestas privadas anónimas.
	 *
	 * @param array<string, mixed>      $cart     Carrito.
	 * @param array<string, int|string> $identity Identidad.
	 * @return array<string, mixed>
	 */
	private static function with_csrf( array $cart, array $identity ): array {
		if ( 'session' === $identity['type'] ) {
			$cart['csrf_token'] = (string) $identity['csrf_token'];
		}

		return $cart;
	}

	/**
	 * Construye respuesta privada sin caché y con revisión HTTP.
	 *
	 * @param array<string, mixed> $cart   Carrito.
	 * @param int                  $status Estado HTTP.
	 * @return WP_REST_Response
	 */
	private static function response( array $cart, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $cart, $status );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'ETag', '"cart-' . $cart['public_id'] . '-' . $cart['revision'] . '"' );
		$response->header( 'Vary', 'Cookie, X-WP-Nonce' );

		return $response;
	}

	/**
	 * Args comunes a todas las mutaciones.
	 *
	 * @return array<string, mixed>
	 */
	private static function mutation_args(): array {
		return array(
			'expected_revision' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);
	}

	/**
	 * Args de una selección completa sin aceptar importes contractuales.
	 *
	 * @return array<string, mixed>
	 */
	private static function item_args(): array {
		return array_merge(
			self::mutation_args(),
			array(
				'item' => array(
					'type'     => 'object',
					'required' => true,
				),
			)
		);
	}

	/**
	 * Args comunes más UUID de línea.
	 *
	 * @return array<string, mixed>
	 */
	private static function line_mutation_args(): array {
		return array_merge(
			self::mutation_args(),
			array(
				'line_id' => array(
					'type'     => 'string',
					'format'   => 'uuid',
					'required' => true,
				),
			)
		);
	}

	/**
	 * Schema estable de la proyección del carrito.
	 *
	 * @return array<string, mixed>
	 */
	public static function cart_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'vicu_restaurante_cart',
			'type'       => 'object',
			'required'   => array( 'public_id', 'status', 'revision', 'catalog_revision', 'availability_revision', 'pricing_revision', 'items', 'fulfillment', 'tip_rate_bps', 'totals', 'expires_at' ),
			'properties' => array(
				'public_id'             => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'status'                => array(
					'type' => 'string',
					'enum' => array( 'active' ),
				),
				'revision'              => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'catalog_revision'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'availability_revision' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'pricing_revision'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'items'                 => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'discount_code'         => array( 'type' => array( 'string', 'null' ) ),
				'fulfillment'           => array(
					'type' => 'string',
					'enum' => array( 'pickup', 'delivery' ),
				),
				'delivery_zone_id'      => array( 'type' => array( 'string', 'null' ) ),
				'tip_rate_bps'          => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'totals'                => array( 'type' => 'object' ),
				'expires_at'            => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'csrf_token'            => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Error común de payload.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_request', __( 'La solicitud de carrito no es válida.', 'vicunav-restaurante' ), array( 'status' => 400 ) );
	}
}
