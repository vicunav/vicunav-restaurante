<?php
/**
 * CRUD propietario y lectura compartida de pizzas guardadas.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Rest;

use Vicu\Core\Rest;
use Vicu\Restaurante\Cart\CartAuthentication;
use Vicu\Restaurante\SavedPizza\SavedPizzaService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Una credencial compartida nunca se reutiliza como autorización de cuenta.
 */
final class SavedPizzaRoutes {
	/**
	 * Evita registrar hooks duplicados.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/** Registra la superficie REST una sola vez. */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		self::$hooks_registered = true;
	}

	/** Registra CRUD de cuenta, rotación y consumo público. */
	public static function register_routes(): void {
		Rest::register_route(
			'/restaurante/saved-pizzas',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_items' ),
					'permission_callback' => array( self::class, 'allow_account' ),
					'schema'              => array( self::class, 'collection_schema' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create_item' ),
					'permission_callback' => array( self::class, 'allow_account' ),
					'args'                => array(
						'name'          => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 100,
							'required'  => true,
						),
						'configuration' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
					'schema'              => array( self::class, 'item_schema' ),
				),
			)
		);

		Rest::register_route(
			'/restaurante/saved-pizzas/(?P<public_id>[a-f0-9-]{36})',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( self::class, 'update_item' ),
					'permission_callback' => array( self::class, 'allow_account' ),
					'args'                => self::mutation_args(),
					'schema'              => array( self::class, 'item_schema' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'delete_item' ),
					'permission_callback' => array( self::class, 'allow_account' ),
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
					),
				),
			)
		);

		Rest::register_route(
			'/restaurante/saved-pizzas/(?P<public_id>[a-f0-9-]{36})/share',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rotate_share' ),
				'permission_callback' => array( self::class, 'allow_account' ),
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
				),
			)
		);

		Rest::register_route(
			'/restaurante/saved-pizzas/shared/(?P<token>[A-Za-z0-9_-]{43})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'shared' ),
				'permission_callback' => array( self::class, 'allow_shared' ),
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'pattern'  => '^[A-Za-z0-9_-]{43}$',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Exige cuenta y nonce de WordPress.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return true|WP_Error
	 */
	public static function allow_account( WP_REST_Request $request ): true|WP_Error {
		$identity = CartAuthentication::resolve( $request, false );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		return 0 < (int) $identity['user_id']
			? true
			: new WP_Error( 'vicu_restaurante_authentication_required', __( 'Se requiere una cuenta para gestionar pizzas guardadas.', 'vicunav-restaurante' ), array( 'status' => 401 ) );
	}

	/**
	 * Permite conectar rate limiting a enlaces compartidos.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return true|WP_Error
	 */
	public static function allow_shared( WP_REST_Request $request ): true|WP_Error {
		/**
		 * Filtra la lectura de un enlace compartido.
		 *
		 * @param bool            $allowed Estado predeterminado.
		 * @param WP_REST_Request $request Solicitud.
		 */
		$allowed = (bool) apply_filters( 'vicu_restaurante_allow_shared_pizza', true, $request );

		return $allowed
			? true
			: new WP_Error( 'vicu_restaurante_rate_limited', __( 'Intenta abrir la pizza nuevamente más tarde.', 'vicunav-restaurante' ), array( 'status' => 429 ) );
	}

	/**
	 * Lista pizzas de la cuenta actual.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response
	 */
	public static function list_items( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return self::response( array( 'items' => SavedPizzaService::list_for_user( get_current_user_id() ) ) );
	}

	/**
	 * Crea una pizza guardada validada.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$configuration = $request->get_param( 'configuration' );
		$result        = is_array( $configuration ) ? SavedPizzaService::create( get_current_user_id(), (string) $request['name'], $configuration ) : self::invalid();
		return is_wp_error( $result ) ? $result : self::response( $result, 201 );
	}

	/**
	 * Actualiza nombre o configuración con revisión.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$configuration = $request->get_param( 'configuration' );
		$result        = SavedPizzaService::update(
			get_current_user_id(),
			(string) $request['public_id'],
			(int) $request['expected_revision'],
			is_string( $request->get_param( 'name' ) ) ? (string) $request->get_param( 'name' ) : null,
			is_array( $configuration ) ? $configuration : null
		);
		return is_wp_error( $result ) ? $result : self::response( $result );
	}

	/**
	 * Elimina una pizza propietaria.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = SavedPizzaService::delete( get_current_user_id(), (string) $request['public_id'], (int) $request['expected_revision'] );
		return is_wp_error( $result ) ? $result : self::response( $result );
	}

	/**
	 * Rota y devuelve una credencial compartible.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rotate_share( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = SavedPizzaService::rotate_share( get_current_user_id(), (string) $request['public_id'], (int) $request['expected_revision'] );
		return is_wp_error( $result ) ? $result : self::response( $result );
	}

	/**
	 * Resuelve y recotiza una credencial compartida.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function shared( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = SavedPizzaService::shared( (string) $request['token'] );
		return is_wp_error( $result ) ? $result : self::response( $result );
	}

	/**
	 * Define el schema de colección privada.
	 *
	 * @return array<string, mixed>
	 */
	public static function collection_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'restaurant-saved-pizza-collection',
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => self::item_schema(),
				),
			),
		);
	}

	/**
	 * Define el schema de pizza guardada.
	 *
	 * @return array<string, mixed>
	 */
	public static function item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'restaurant-saved-pizza',
			'type'       => 'object',
			'properties' => array(
				'public_id'     => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'name'          => array( 'type' => 'string' ),
				'configuration' => PizzaQuoteRoute::configuration_schema(),
				'revision'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'share_enabled' => array( 'type' => 'boolean' ),
				'created_at'    => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'updated_at'    => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'share_token'   => array(
					'type'      => 'string',
					'minLength' => 43,
					'maxLength' => 43,
				),
				'share_path'    => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Define argumentos de PATCH.
	 *
	 * @return array<string, mixed>
	 */
	private static function mutation_args(): array {
		return array(
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
			'name'              => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 100,
				'required'  => false,
			),
			'configuration'     => array(
				'type'     => 'object',
				'required' => false,
			),
		);
	}

	/**
	 * Devuelve una respuesta no cacheable.
	 *
	 * @param array<string, mixed> $data   Datos.
	 * @param int                  $status Estado HTTP.
	 * @return WP_REST_Response
	 */
	private static function response( array $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'Vary', 'Cookie, X-WP-Nonce' );
		return $response;
	}

	/**
	 * Construye un error de entrada estable.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_request', __( 'La configuración de pizza no es válida.', 'vicunav-restaurante' ), array( 'status' => 400 ) );
	}
}
