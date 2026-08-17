<?php
/**
 * Disponibilidad y ciclo privado de reservas.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Rest;

use Vicu\Core\Rest;
use Vicu\Restaurante\Reservation\ReservationAvailability;
use Vicu\Restaurante\Reservation\ReservationService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Mantiene contacto, notas, tokens y capacidad interna fuera de respuestas públicas.
 */
final class ReservationRoutes {
	/**
	 * Evita registrar hooks duplicados.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/** Enlaza el registro REST una sola vez. */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		self::$hooks_registered = true;
	}

	/** Registra disponibilidad, creación, lectura propietaria y cancelación. */
	public static function register_routes(): void {
		Rest::register_route(
			'/restaurante/reservations/availability',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'availability' ),
				'permission_callback' => array( self::class, 'allow_availability' ),
				'args'                => array(
					'date'       => array(
						'type'     => 'string',
						'format'   => 'date',
						'required' => true,
					),
					'party_size' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
				'schema'              => array( self::class, 'availability_schema' ),
			)
		);

		Rest::register_route(
			'/restaurante/reservations',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( self::class, 'allow_create' ),
				'args'                => self::create_args(),
				'schema'              => array( self::class, 'reservation_schema' ),
			)
		);

		Rest::register_route(
			'/restaurante/reservations/(?P<public_id>[a-f0-9-]{36})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_reservation' ),
				'permission_callback' => array( self::class, 'allow_private' ),
				'args'                => array(
					'public_id' => array(
						'type'     => 'string',
						'format'   => 'uuid',
						'required' => true,
					),
				),
				'schema'              => array( self::class, 'reservation_schema' ),
			)
		);

		Rest::register_route(
			'/restaurante/reservations/(?P<public_id>[a-f0-9-]{36})/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'cancel' ),
				'permission_callback' => array( self::class, 'allow_private' ),
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
				'schema'              => array( self::class, 'reservation_schema' ),
			)
		);
	}

	/**
	 * Permite integrar un rate limiter sin asumir una implementación.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return true|WP_Error
	 */
	public static function allow_availability( WP_REST_Request $request ): true|WP_Error {
		/**
		 * Filtra el acceso a consultas públicas de disponibilidad.
		 *
		 * @param true|WP_Error  $allowed Estado inicial.
		 * @param WP_REST_Request $request Solicitud.
		 */
		$allowed = apply_filters( 'vicu_restaurante_allow_reservation_availability', true, $request );

		return self::rate_limit_result( $allowed );
	}

	/**
	 * Exige nonce para cuentas y deja conectable el límite de creación invitada.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return true|WP_Error
	 */
	public static function allow_create( WP_REST_Request $request ): true|WP_Error {
		$authenticated = self::authenticated( $request );

		if ( is_wp_error( $authenticated ) ) {
			return $authenticated;
		}

		/**
		 * Filtra el acceso a creación de reservas para rate limiting o antifraude.
		 *
		 * @param true|WP_Error  $allowed Estado inicial.
		 * @param WP_REST_Request $request Solicitud.
		 */
		$allowed = apply_filters( 'vicu_restaurante_allow_reservation_creation', true, $request );

		return self::rate_limit_result( $allowed );
	}

	/**
	 * Requiere nonce de cuenta o token invitado de 64 caracteres.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return true|WP_Error
	 */
	public static function allow_private( WP_REST_Request $request ): true|WP_Error {
		$authenticated = self::authenticated( $request );

		if ( is_wp_error( $authenticated ) ) {
			return $authenticated;
		}

		if ( 0 < get_current_user_id() || 64 === strlen( trim( (string) $request->get_header( 'x-vicu-reservation-token' ) ) ) ) {
			return true;
		}

		return new WP_Error( 'vicu_restaurante_authentication_required', __( 'Se requiere una credencial de la reserva.', 'vicunav-restaurante' ), array( 'status' => 401 ) );
	}

	/**
	 * Calcula disponibilidad autoritativa sin caché.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function availability( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = ReservationAvailability::get( (string) $request['date'], (int) $request['party_size'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = new WP_REST_Response( $result );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	/**
	 * Crea una reserva idempotente.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = ReservationService::create( self::identity(), trim( (string) $request->get_header( 'idempotency-key' ) ), $request->get_params() );

		return is_wp_error( $result ) ? $result : self::private_response( $result, 201 );
	}

	/**
	 * Devuelve una reserva al propietario.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_reservation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = ReservationService::get( (string) $request['public_id'], self::identity(), trim( (string) $request->get_header( 'x-vicu-reservation-token' ) ) );

		return is_wp_error( $result ) ? $result : self::private_response( $result );
	}

	/**
	 * Cancela una reserva propietaria.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = ReservationService::cancel( (string) $request['public_id'], self::identity(), trim( (string) $request->get_header( 'x-vicu-reservation-token' ) ), (int) $request['expected_revision'] );

		return is_wp_error( $result ) ? $result : self::private_response( $result );
	}

	/**
	 * Define el schema exacto de disponibilidad.
	 *
	 * @return array<string, mixed>
	 */
	public static function availability_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'restaurant-reservation-availability',
			'type'       => 'object',
			'properties' => array(
				'status'            => array(
					'type' => 'string',
					'enum' => array( 'ok', 'blocked', 'closed', 'party-too-large' ),
				),
				'reason'            => array( 'type' => array( 'string', 'null' ) ),
				'timezone'          => array( 'type' => 'string' ),
				'settings_revision' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'slots'             => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'time'               => array( 'type' => 'string' ),
							'status'             => array(
								'type' => 'string',
								'enum' => array( 'available', 'limited', 'unavailable' ),
							),
							'remaining_capacity' => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
							'starts_at'          => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Define el schema público de reserva.
	 *
	 * @return array<string, mixed>
	 */
	public static function reservation_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'restaurant-reservation',
			'type'       => 'object',
			'properties' => array(
				'public_id'         => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'confirmation_code' => array( 'type' => 'string' ),
				'status'            => array(
					'type' => 'string',
					'enum' => array( 'pendiente', 'confirmada', 'completada', 'cancelada', 'no_asistio' ),
				),
				'revision'          => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'date'              => array(
					'type'   => 'string',
					'format' => 'date',
				),
				'time'              => array( 'type' => 'string' ),
				'timezone'          => array( 'type' => 'string' ),
				'party_size'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'starts_at'         => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'ends_at'           => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'created_at'        => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'updated_at'        => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'access_token'      => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
			),
		);
	}

	/**
	 * Define los datos aceptados en creación.
	 *
	 * @return array<string, mixed>
	 */
	private static function create_args(): array {
		return array(
			'guest_name'      => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 100,
				'required'  => true,
			),
			'phone'           => array(
				'type'      => 'string',
				'minLength' => 3,
				'maxLength' => 32,
				'required'  => true,
			),
			'email'           => array(
				'type'      => 'string',
				'maxLength' => 191,
				'required'  => false,
			),
			'notes'           => array(
				'type'      => 'string',
				'maxLength' => 500,
				'required'  => false,
			),
			'zone_preference' => array(
				'type'      => 'string',
				'maxLength' => 100,
				'required'  => false,
			),
			'date'            => array(
				'type'     => 'string',
				'format'   => 'date',
				'required' => true,
			),
			'time'            => array(
				'type'     => 'string',
				'pattern'  => '^(?:[01]\\d|2[0-3]):[0-5]\\d$',
				'required' => true,
			),
			'party_size'      => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);
	}

	/**
	 * Construye una respuesta privada no cacheable.
	 *
	 * @param array<string, mixed> $reservation Reserva.
	 * @param int                  $status      Estado HTTP.
	 * @return WP_REST_Response
	 */
	private static function private_response( array $reservation, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $reservation, $status );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'ETag', '"reservation-' . $reservation['public_id'] . '-' . $reservation['revision'] . '"' );
		$response->header( 'Vary', 'Cookie, X-WP-Nonce, X-Vicu-Reservation-Token' );
		return $response;
	}

	/**
	 * Verifica nonce cuando existe una cuenta autenticada.
	 *
	 * @param WP_REST_Request $request Solicitud.
	 * @return true|WP_Error
	 */
	private static function authenticated( WP_REST_Request $request ): true|WP_Error {
		if ( 0 === get_current_user_id() ) {
			return true;
		}

		return wp_verify_nonce( trim( (string) $request->get_header( 'x-wp-nonce' ) ), 'wp_rest' )
			? true
			: new WP_Error( 'vicu_restaurante_invalid_nonce', __( 'La credencial de la solicitud no es válida.', 'vicunav-restaurante' ), array( 'status' => 403 ) );
	}

	/**
	 * Construye la identidad mínima del dominio.
	 *
	 * @return array<string, int|string>
	 */
	private static function identity(): array {
		return array( 'user_id' => get_current_user_id() );
	}

	/**
	 * Normaliza la decisión conectable del rate limiter.
	 *
	 * @param mixed $allowed Resultado del filtro.
	 * @return true|WP_Error
	 */
	private static function rate_limit_result( mixed $allowed ): true|WP_Error {
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return false !== $allowed
			? true
			: new WP_Error( 'vicu_restaurante_rate_limited', __( 'Intenta reservar nuevamente más tarde.', 'vicunav-restaurante' ), array( 'status' => 429 ) );
	}
}
