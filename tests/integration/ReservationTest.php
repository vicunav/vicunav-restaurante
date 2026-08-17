<?php
/**
 * Pruebas transaccionales de horarios, capacidad y reservas.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Installer;
use Vicu\Restaurante\Reservation\ReservationAvailability;
use Vicu\Restaurante\Reservation\ReservationPostType;
use Vicu\Restaurante\Reservation\ReservationProjection;
use Vicu\Restaurante\Reservation\ReservationService;
use Vicu\Restaurante\Reservation\ReservationSettings;
use Vicu\Restaurante\Rest\ReservationRoutes;
use Vicu\Restaurante\Schema;

/**
 * Verifica solapamientos, ownership, idempotencia, REST y liberación atómica.
 */
final class ReservationTest extends WP_UnitTestCase {
	/**
	 * Fecha futura usada por la suite.
	 *
	 * @var string
	 */
	private string $date;

	/** Instala tablas vacías y un horario técnico futuro. */
	public function setUp(): void {
		parent::setUp();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( Installer::install() );
		( new ReservationPostType() )->register();
		$this->truncate_domain_tables();
		$this->date = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
		$this->configure( array() );
		wp_set_current_user( 0 );
		wp_cache_flush();
	}

	/** Limpia ajustes e identidad compartida. */
	public function tearDown(): void {
		delete_option( ReservationSettings::OPTION_NAME );
		delete_option( ReservationSettings::REVISION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Una reserva ocupa cada intervalo solapado y no permite vender los últimos puestos dos veces.
	 */
	public function test_overlapping_capacity_cannot_be_oversold(): void {
		$first = ReservationService::create( $this->guest(), 'reservation-first-0001', $this->input( '18:00', 3 ) );
		$this->assertNotWPError( $first );
		$this->assertSame( 'pendiente', $first['status'] );

		$overlap = ReservationAvailability::get( $this->date, 2 );
		$this->assertNotWPError( $overlap );
		$this->assertSame( 'unavailable', $this->slot( $overlap, '18:30' )['status'] );
		$this->assertSame( 'available', $this->slot( $overlap, '19:30' )['status'] );

		$second = ReservationService::create( $this->guest(), 'reservation-second-001', $this->input( '18:30', 2 ) );
		$this->assertWPError( $second );
		$this->assertSame( 'vicu_restaurante_unavailable', $second->get_error_code() );
		$this->assertSame( 1, $this->count_rows( Schema::reservations_table_name() ) );
		$this->assertSame( array( 3, 3, 3 ), $this->occupancy_values() );
	}

	/** Cancelar libera los intervalos congelados exactamente una vez. */
	public function test_cancellation_is_idempotent_and_releases_capacity_once(): void {
		$reservation = ReservationService::create( $this->guest(), 'reservation-cancel-001', $this->input( '18:00', 4 ) );
		$this->assertNotWPError( $reservation );
		$this->assertSame( array( 4, 4, 4 ), $this->occupancy_values() );

		$cancelled = ReservationService::cancel( $reservation['public_id'], $this->guest(), $reservation['access_token'], 1 );
		$this->assertNotWPError( $cancelled );
		$this->assertSame( 'cancelada', $cancelled['status'] );
		$this->assertSame( 2, $cancelled['revision'] );
		$this->assertSame( array( 0, 0, 0 ), $this->occupancy_values() );

		$replay = ReservationService::cancel( $reservation['public_id'], $this->guest(), $reservation['access_token'], 1 );
		$this->assertNotWPError( $replay );
		$this->assertSame( 2, $replay['revision'] );
		$this->assertSame( 2, $this->count_rows( Schema::reservation_events_table_name() ) );
		$this->assertSame( array( 0, 0, 0 ), $this->occupancy_values() );
	}

	/** Replay conserva UUID y token, y una colisión no revela secretos. */
	public function test_creation_is_idempotent_and_tokens_remain_hashed(): void {
		global $wpdb;
		$key         = 'reservation-replay-001';
		$reservation = ReservationService::create( $this->guest(), $key, $this->input( '18:00', 2 ) );
		$replay      = ReservationService::create( $this->guest(), $key, $this->input( '18:00', 2 ) );
		$this->assertNotWPError( $reservation );
		$this->assertNotWPError( $replay );
		$this->assertSame( $reservation['public_id'], $replay['public_id'] );
		$this->assertSame( $reservation['access_token'], $replay['access_token'] );

		$collision = ReservationService::create( $this->guest(), $key, $this->input( '18:30', 2 ) );
		$this->assertWPError( $collision );
		$this->assertSame( 'vicu_restaurante_idempotency_collision', $collision->get_error_code() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( 'SELECT access_token_hash FROM ' . Schema::reservations_table_name() . ' LIMIT 1', ARRAY_A );
		$this->assertNotSame( $reservation['access_token'], $row['access_token_hash'] );
		$this->assertSame( 64, strlen( $row['access_token_hash'] ) );
	}

	/** Token o cuenta propietaria acceden sin exponer contacto en la respuesta pública. */
	public function test_private_access_prevents_cross_account_enumeration(): void {
		$reservation = ReservationService::create( $this->guest(), 'reservation-private-01', $this->input( '18:00', 2 ) );
		$this->assertNotWPError( $reservation );
		$this->assertWPError( ReservationService::get( $reservation['public_id'], $this->guest(), str_repeat( '0', 64 ) ) );
		$owned = ReservationService::get( $reservation['public_id'], $this->guest(), $reservation['access_token'] );
		$this->assertNotWPError( $owned );
		$this->assertArrayNotHasKey( 'guest_name', $owned );
		$this->assertArrayNotHasKey( 'guest_phone', $owned );
		$this->assertSame( 'Persona', ReservationService::admin_detail( $reservation['public_id'] )['guest_name'] );

		$user_a  = self::factory()->user->create();
		$user_b  = self::factory()->user->create();
		$account = ReservationService::create( array( 'user_id' => $user_a ), 'reservation-account-01', $this->input( '19:30', 2 ) );
		$this->assertNotWPError( $account );
		$this->assertArrayNotHasKey( 'access_token', $account );
		$this->assertNotWPError( ReservationService::get( $account['public_id'], array( 'user_id' => $user_a ) ) );
		$this->assertWPError( ReservationService::get( $account['public_id'], array( 'user_id' => $user_b ) ) );
	}

	/** Horarios, cierres, excepciones, aviso y timezone se aplican en servidor. */
	public function test_server_applies_schedule_exceptions_notice_and_timezone(): void {
		$blocked                       = $this->settings();
		$blocked['recurring_closures'] = array( substr( $this->date, 5 ) );
		update_option( ReservationSettings::OPTION_NAME, $blocked, false );
		$result = ReservationAvailability::get( $this->date, 2 );
		$this->assertSame( 'blocked', $result['status'] );
		$this->assertSame( 'recurring_closure', $result['reason'] );

		$exception                       = $this->settings();
		$exception['recurring_closures'] = array();
		$exception['exceptions']         = array(
			array(
				'date'    => $this->date,
				'closed'  => false,
				'periods' => array(
					array(
						'opens_at'  => '20:00',
						'closes_at' => '22:00',
					),
				),
			),
		);
		update_option( ReservationSettings::OPTION_NAME, $exception, false );
		$result = ReservationAvailability::get( $this->date, 2 );
		$this->assertSame( '20:00', $result['slots'][0]['time'] );
		$this->assertSame( 'America/Caracas', $result['timezone'] );
		$this->assertStringContainsString( '00:00', $result['slots'][0]['starts_at'] );
	}

	/** REST publica schemas válidos, no-store y ownership por token. */
	public function test_rest_creation_read_and_cancel_are_private(): void {
		$response = $this->dispatch( 'POST', '/vicu/v1/restaurante/reservations', $this->input( '18:00', 2 ), array( 'Idempotency-Key' => 'reservation-rest-key-01' ) );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] );
		$this->assertTrue( rest_validate_value_from_schema( $response->get_data(), ReservationRoutes::reservation_schema() ) );

		$id      = $response->get_data()['public_id'];
		$missing = $this->dispatch( 'GET', '/vicu/v1/restaurante/reservations/' . $id );
		$this->assertSame( 401, $missing->get_status() );
		$headers = array( 'X-Vicu-Reservation-Token' => $response->get_data()['access_token'] );
		$read    = $this->dispatch( 'GET', '/vicu/v1/restaurante/reservations/' . $id, array(), $headers );
		$this->assertSame( 200, $read->get_status() );
		$cancel = $this->dispatch( 'POST', '/vicu/v1/restaurante/reservations/' . $id . '/cancel', array( 'expected_revision' => 1 ), $headers );
		$this->assertSame( 200, $cancel->get_status() );
		$this->assertSame( 'cancelada', $cancel->get_data()['status'] );
	}

	/** Un rate limiter conectado puede cerrar disponibilidad y creación. */
	public function test_rest_rate_limit_filters_fail_closed(): void {
		$deny         = static fn(): bool => false;
		add_filter( 'vicu_restaurante_allow_reservation_availability', $deny );
		$availability = $this->dispatch(
			'GET',
			'/vicu/v1/restaurante/reservations/availability',
			array(
				'date'       => $this->date,
				'party_size' => 2,
			)
		);
		remove_filter( 'vicu_restaurante_allow_reservation_availability', $deny );
		$this->assertSame( 429, $availability->get_status() );

		add_filter( 'vicu_restaurante_allow_reservation_creation', $deny );
		$creation = $this->dispatch( 'POST', '/vicu/v1/restaurante/reservations', $this->input( '18:00', 2 ), array( 'Idempotency-Key' => 'reservation-denied-001' ) );
		remove_filter( 'vicu_restaurante_allow_reservation_creation', $deny );
		$this->assertSame( 429, $creation->get_status() );
	}

	/** La proyección se puede reconstruir sin intervenir en capacidad. */
	public function test_projection_is_rebuildable(): void {
		$reservation = ReservationService::create( $this->guest(), 'reservation-project-001', $this->input( '18:00', 2 ) );
		$this->assertNotWPError( $reservation );
		$this->assertSame( 1, ReservationProjection::rebuild()['synced'] );
	}

	/**
	 * Configura un horario técnico para la fecha de prueba.
	 *
	 * @param array<string, mixed> $overrides Sobrescrituras.
	 */
	private function configure( array $overrides ): void {
		$day                                 = strtolower( gmdate( 'l', strtotime( $this->date ) ) );
		$settings                            = array(
			'timezone'              => 'America/Caracas',
			'weekly_schedule'       => array_fill_keys( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ), array() ),
			'exceptions'            => array(),
			'recurring_closures'    => array(),
			'interval_minutes'      => 30,
			'duration_minutes'      => 90,
			'capacity'              => 4,
			'min_party_size'        => 1,
			'max_party_size'        => 12,
			'min_notice_minutes'    => 0,
			'limited_threshold_bps' => 2500,
			'auto_confirm'          => false,
		);
		$settings['weekly_schedule'][ $day ] = array(
			array(
				'opens_at'  => '18:00',
				'closes_at' => '23:00',
			),
		);
		update_option( ReservationSettings::OPTION_NAME, array_replace( $settings, $overrides ), false );
		update_option( ReservationSettings::REVISION_NAME, '1', false );
	}

	/**
	 * Devuelve los ajustes persistidos.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		return get_option( ReservationSettings::OPTION_NAME );
	}

	/**
	 * Devuelve una identidad invitada mínima.
	 *
	 * @return array<string, int>
	 */
	private function guest(): array {
		return array( 'user_id' => 0 );
	}

	/**
	 * Construye una solicitud privada válida.
	 *
	 * @param string $time       Hora local.
	 * @param int    $party_size Personas.
	 * @return array<string, mixed>
	 */
	private function input( string $time, int $party_size ): array {
		return array(
			'guest_name'      => 'Persona',
			'phone'           => '+58 000 000',
			'email'           => 'persona@example.test',
			'notes'           => 'Mesa tranquila',
			'zone_preference' => 'Interior',
			'date'            => $this->date,
			'time'            => $time,
			'party_size'      => $party_size,
		);
	}

	/**
	 * Busca un slot por hora.
	 *
	 * @param array<string, mixed> $availability Disponibilidad.
	 * @param string               $time         Hora local.
	 * @return array<string, mixed>
	 */
	private function slot( array $availability, string $time ): array {
		foreach ( $availability['slots'] as $slot ) {
			if ( $time === $slot['time'] ) {
				return $slot;
			}
		}
		return array();
	}

	/**
	 * Lee ocupación cronológica.
	 *
	 * @return int[]
	 */
	private function occupancy_values(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( 'SELECT occupied FROM ' . Schema::reservation_occupancy_table_name() . ' ORDER BY interval_start_utc ASC' ) );
	}

	/**
	 * Cuenta filas de una tabla interna conocida.
	 *
	 * @param string $table Tabla interna.
	 * @return int
	 */
	private function count_rows( string $table ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/** Limpia solo las autoridades usadas por esta suite. */
	private function truncate_domain_tables(): void {
		global $wpdb;
		foreach ( array( Schema::reservation_events_table_name(), Schema::reservation_occupancy_table_name(), Schema::reservations_table_name(), Schema::idempotency_table_name() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
	}

	/**
	 * Despacha una solicitud REST en proceso.
	 *
	 * @param string                $method  Método.
	 * @param string                $route   Ruta.
	 * @param array<string, mixed>  $params  Parámetros.
	 * @param array<string, string> $headers Headers.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, string $route, array $params = array(), array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_body_params( $params );
		$request->set_query_params( $params );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return rest_do_request( $request );
	}
}
