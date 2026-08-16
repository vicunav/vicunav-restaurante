<?php
/**
 * Checkout y consulta privada de pedidos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Rest;

use Vicu\Core\Rest;
use Vicu\Restaurante\Cart\CartAuthentication;
use Vicu\Restaurante\Order\OrderService;
use Vicu\Restaurante\Order\OrderStateMachine;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * No publica contacto, dirección, token ni eventos administrativos.
 */
final class OrderRoutes {
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
	 * Registra checkout, estado propietario y transición operativa.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		Rest::register_route(
			'/restaurante/orders',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'checkout' ),
				'permission_callback' => array( self::class, 'allow_checkout' ),
				'args'                => self::checkout_args(),
				'schema'              => array( self::class, 'order_schema' ),
			)
		);

		Rest::register_route(
			'/restaurante/orders/(?P<public_id>[a-f0-9-]{36})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_order' ),
				'permission_callback' => array( self::class, 'allow_order_read' ),
				'args'                => array(
					'public_id' => array(
						'type'     => 'string',
						'format'   => 'uuid',
						'required' => true,
					),
				),
				'schema'              => array( self::class, 'order_schema' ),
			)
		);

		Rest::register_route(
			'/restaurante/admin/orders/(?P<public_id>[a-f0-9-]{36})/transition',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'admin_transition' ),
				'permission_callback' => array( self::class, 'allow_admin_transition' ),
				'args'                => array(
					'public_id'         => array(
						'type'     => 'string',
						'format'   => 'uuid',
						'required' => true,
					),
					'expected_revision' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'target'            => array(
						'type'     => 'string',
						'enum'     => array( 'en_preparacion', 'listo', 'en_reparto', 'completado', 'cancelado' ),
						'required' => true,
					),
					'reason'            => array(
						'type'      => 'string',
						'maxLength' => 500,
						'required'  => false,
					),
				),
				'schema'              => array( self::class, 'order_schema' ),
			)
		);
	}

	/**
	 * Checkout reutiliza identidad, CSRF y origen del carrito.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return true|WP_Error
	 */
	public static function allow_checkout( WP_REST_Request $request ): true|WP_Error {
		$identity = CartAuthentication::resolve( $request, true );

		return is_wp_error( $identity ) ? $identity : true;
	}

	/**
	 * Cuenta con nonce o invitado con token no vacío.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return true|WP_Error
	 */
	public static function allow_order_read( WP_REST_Request $request ): true|WP_Error {
		if ( 0 < get_current_user_id() ) {
			$identity = CartAuthentication::resolve( $request, false );

			return is_wp_error( $identity ) ? $identity : true;
		}

		return 64 === strlen( trim( (string) $request->get_header( 'x-vicu-order-token' ) ) )
			? true
			: new WP_Error( 'vicu_restaurante_authentication_required', __( 'Se requiere una credencial del pedido.', 'vicunav-restaurante' ), array( 'status' => 401 ) );
	}

	/**
	 * Exige nonce y capability según el destino solicitado.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return true|WP_Error
	 */
	public static function allow_admin_transition( WP_REST_Request $request ): true|WP_Error {
		$identity = CartAuthentication::resolve( $request, false );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$capability = 'cancelado' === $request->get_param( 'target' ) ? 'manage_vicu_restaurant_orders' : 'fulfill_vicu_restaurant_orders';

		return current_user_can( $capability )
			? true
			: new WP_Error( 'vicu_restaurante_forbidden', __( 'No puedes operar este pedido.', 'vicunav-restaurante' ), array( 'status' => 403 ) );
	}

	/**
	 * Ejecuta checkout idempotente.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function checkout( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$identity = CartAuthentication::resolve( $request, true );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$key    = trim( (string) $request->get_header( 'idempotency-key' ) );
		$result = OrderService::checkout( $identity, $key, $request->get_params() );

		return is_wp_error( $result ) ? $result : self::response( $result, 201 );
	}

	/**
	 * Devuelve estado agregado sin datos privados.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_order( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$identity = 0 < get_current_user_id()
			? array(
				'type'       => 'user',
				'key'        => 'user:' . get_current_user_id(),
				'session_id' => 0,
				'user_id'    => get_current_user_id(),
				'csrf_token' => '',
				'expires_at' => '',
			)
			: array(
				'type'       => 'guest',
				'key'        => 'guest',
				'session_id' => 0,
				'user_id'    => 0,
				'csrf_token' => '',
				'expires_at' => '',
			);
		$result   = OrderService::get( (string) $request['public_id'], $identity, trim( (string) $request->get_header( 'x-vicu-order-token' ) ) );

		return is_wp_error( $result ) ? $result : self::response( $result );
	}

	/**
	 * Ejecuta una transición operativa autorizada.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_transition( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = OrderService::transition(
			(string) $request['public_id'],
			(int) $request->get_param( 'expected_revision' ),
			(string) $request->get_param( 'target' ),
			'operator',
			get_current_user_id(),
			is_string( $request->get_param( 'reason' ) ) ? (string) $request->get_param( 'reason' ) : null
		);

		return is_wp_error( $result ) ? $result : self::response( $result );
	}

	/**
	 * Respuesta privada con revisión HTTP.
	 *
	 * @param array<string, mixed> $order  Pedido.
	 * @param int                  $status Estado.
	 * @return WP_REST_Response
	 */
	private static function response( array $order, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $order, $status );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'ETag', '"order-' . $order['public_id'] . '-' . $order['revision'] . '"' );
		$response->header( 'Vary', 'Cookie, X-WP-Nonce, X-Vicu-Order-Token' );

		return $response;
	}

	/**
	 * Args privados de checkout.
	 *
	 * @return array<string, mixed>
	 */
	private static function checkout_args(): array {
		return array(
			'expected_revision'     => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
			'customer'              => array(
				'type'       => 'object',
				'required'   => true,
				'properties' => array(
					'name'  => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 100,
						'required'  => true,
					),
					'email' => array(
						'type'      => 'string',
						'maxLength' => 191,
						'required'  => false,
					),
					'phone' => array(
						'type'      => 'string',
						'minLength' => 3,
						'maxLength' => 32,
						'required'  => true,
					),
				),
			),
			'delivery_address'      => array(
				'type'      => 'string',
				'maxLength' => 500,
				'required'  => false,
			),
			'delivery_instructions' => array(
				'type'      => 'string',
				'maxLength' => 500,
				'required'  => false,
			),
			'customer_note'         => array(
				'type'      => 'string',
				'maxLength' => 500,
				'required'  => false,
			),
		);
	}

	/**
	 * Schema de respuesta estable.
	 *
	 * @return array<string, mixed>
	 */
	public static function order_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'vicu_restaurante_order',
			'type'       => 'object',
			'required'   => array( 'public_id', 'order_number', 'status', 'revision', 'fulfillment', 'currency', 'items', 'totals', 'payment_expires_at', 'payment_sync_status', 'created_at', 'updated_at' ),
			'properties' => array(
				'public_id'           => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'order_number'        => array( 'type' => 'string' ),
				'status'              => array(
					'type' => 'string',
					'enum' => OrderStateMachine::STATES,
				),
				'revision'            => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'fulfillment'         => array(
					'type' => 'string',
					'enum' => array( 'pickup', 'delivery' ),
				),
				'currency'            => array(
					'type'    => 'string',
					'pattern' => '^[A-Z]{3}$',
				),
				'items'               => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'totals'              => array( 'type' => 'object' ),
				'payment_expires_at'  => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'payment_sync_status' => array(
					'type' => 'string',
					'enum' => array( 'pending', 'synced', 'error' ),
				),
				'created_at'          => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'updated_at'          => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'access_token'        => array( 'type' => 'string' ),
			),
		);
	}
}
