<?php
/**
 * Persistencia, ownership y concurrencia de reservas.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Reservation;

use DateTimeImmutable;
use DateTimeZone;
use Vicu\Restaurante\Catalog\CatalogDatabase;
use Vicu\Restaurante\Schema;
use WP_Error;

/**
 * Las tablas de dominio son autoridad; ninguna proyección participa en capacidad.
 */
final class ReservationService {
	/**
	 * Crea exactamente una reserva tras bloquear cada intervalo cronológicamente.
	 *
	 * @param array<string, int|string> $identity Identidad pública o cuenta.
	 * @param string                    $key      Clave idempotente.
	 * @param array<string, mixed>      $input    Datos privados.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( array $identity, string $key, array $input ): array|WP_Error {
		global $wpdb;

		$data = self::normalize( $input );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$fingerprint = hash( 'sha256', (string) wp_json_encode( $data ) );
		$scope       = 'reservation:create|' . ( 0 < (int) $identity['user_id'] ? 'user:' . $identity['user_id'] : 'guest' );

		if ( ! CatalogDatabase::begin() ) {
			return self::storage_error();
		}

		$claim = ReservationIdempotency::claim( $scope, $key, $fingerprint );

		if ( is_wp_error( $claim ) ) {
			CatalogDatabase::rollback();
			return $claim;
		}

		if ( 'replay' === $claim['mode'] ) {
			if ( ! CatalogDatabase::commit() ) {
				return self::storage_error();
			}

			$public_id = (string) ( $claim['response']['reservation_public_id'] ?? '' );
			$row       = self::row( $public_id );

			return null === $row ? self::storage_error() : self::with_token( self::public_response( $row ), $row, $identity, $key );
		}

		$settings_revision = ReservationSettings::revision();
		$slot              = ReservationAvailability::slot_definition( $data['date'], $data['time'], $data['party_size'] );

		if ( is_wp_error( $slot ) ) {
			CatalogDatabase::rollback();
			return $slot;
		}

		$occupancy_table = Schema::reservation_occupancy_table_name();
		$now             = current_time( 'mysql', true );

		foreach ( $slot['intervals'] as $interval ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$occupancy_table} (interval_start_utc, occupied, revision, updated_at) VALUES (%s, 0, 1, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$interval->format( 'Y-m-d H:i:s' ),
					$now
				)
			);

			if ( false === $inserted ) {
				CatalogDatabase::rollback();
				return self::storage_error();
			}
		}

		$placeholders = implode( ',', array_fill( 0, count( $slot['intervals'] ), '%s' ) );
		$values       = array_map( static fn( DateTimeImmutable $interval ): string => $interval->format( 'Y-m-d H:i:s' ), $slot['intervals'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$locked = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$occupancy_table} WHERE interval_start_utc IN ({$placeholders}) ORDER BY interval_start_utc ASC FOR UPDATE", ...$values ), ARRAY_A );

		wp_cache_delete( ReservationSettings::REVISION_NAME, 'options' );

		if ( count( $slot['intervals'] ) !== count( $locked ) || ReservationSettings::revision() !== $settings_revision ) {
			CatalogDatabase::rollback();
			return self::unavailable( $data );
		}

		foreach ( $locked as $interval ) {
			if ( (int) $interval['occupied'] + $data['party_size'] > $slot['settings']['capacity'] ) {
				CatalogDatabase::rollback();
				return self::unavailable( $data );
			}
		}

		$public_id    = wp_generate_uuid4();
		$access_token = ReservationIdempotency::access_token( $public_id, $key );
		$confirmation = 'RSV-' . strtoupper( substr( str_replace( '-', '', $public_id ), 0, 12 ) );
		$status       = $slot['settings']['auto_confirm'] ? 'confirmada' : 'pendiente';
		$reservations = Schema::reservations_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$reservations,
			array(
				'public_id'         => $public_id,
				'confirmation_code' => $confirmation,
				'access_token_hash' => ReservationIdempotency::token_hash( $access_token ),
				'user_id'           => 0 < (int) $identity['user_id'] ? (int) $identity['user_id'] : null,
				'status'            => $status,
				'revision'          => 1,
				'guest_name'        => $data['guest_name'],
				'guest_phone'       => $data['guest_phone'],
				'guest_email'       => $data['guest_email'],
				'notes'             => $data['notes'],
				'zone_preference'   => $data['zone_preference'],
				'party_size'        => $data['party_size'],
				'interval_minutes'  => $slot['settings']['interval_minutes'],
				'local_date'        => $data['date'],
				'local_time'        => $data['time'] . ':00',
				'timezone'          => $slot['settings']['timezone'],
				'starts_at_utc'     => $slot['start']->format( 'Y-m-d H:i:s' ),
				'ends_at_utc'       => $slot['end']->format( 'Y-m-d H:i:s' ),
				'created_at'        => $now,
				'updated_at'        => $now,
				'cancelled_at'      => null,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			CatalogDatabase::rollback();
			return self::storage_error();
		}

		$reservation_id = (int) $wpdb->insert_id;

		foreach ( $locked as $interval ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$occupancy_table} SET occupied = occupied + %d, revision = revision + 1, updated_at = %s WHERE interval_start_utc = %s AND occupied + %d <= %d", $data['party_size'], $now, $interval['interval_start_utc'], $data['party_size'], $slot['settings']['capacity'] ) );

			if ( 1 !== $updated ) {
				CatalogDatabase::rollback();
				return self::unavailable( $data );
			}
		}

		if ( ! self::insert_event( $reservation_id, null, $status, 'customer', 0 < (int) $identity['user_id'] ? (int) $identity['user_id'] : null, null, 1 ) || ! ReservationIdempotency::complete( (int) $claim['id'], $public_id ) || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return self::storage_error();
		}

		ReservationProjection::sync( $public_id );
		$row = self::row( $public_id );

		return null === $row ? self::storage_error() : self::with_token( self::public_response( $row ), $row, $identity, $key );
	}

	/**
	 * Devuelve una reserva solo a su cuenta o token propietario.
	 *
	 * @param string               $public_id UUID de reserva.
	 * @param array<string, mixed> $identity  Identidad actual.
	 * @param string               $token     Token invitado.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( string $public_id, array $identity, string $token = '' ): array|WP_Error {
		$row = self::row( $public_id );

		return null === $row || ! self::owns( $row, $identity, $token ) ? self::not_found() : self::public_response( $row );
	}

	/**
	 * Cancela una reserva propietaria de manera idempotente.
	 *
	 * @param string               $public_id         UUID de reserva.
	 * @param array<string, mixed> $identity          Identidad actual.
	 * @param string               $token             Token invitado.
	 * @param int                  $expected_revision Revisión esperada.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function cancel( string $public_id, array $identity, string $token, int $expected_revision ): array|WP_Error {
		$row = self::row( $public_id );

		if ( null === $row || ! self::owns( $row, $identity, $token ) ) {
			return self::not_found();
		}

		return self::transition( $public_id, $expected_revision, 'cancelada', 'customer', 0 < (int) $identity['user_id'] ? (int) $identity['user_id'] : null );
	}

	/**
	 * Aplica una transición con bloqueo, CAS, evento y liberación atómica.
	 *
	 * @param string      $public_id         UUID de reserva.
	 * @param int         $expected_revision Revisión esperada.
	 * @param string      $target            Estado destino.
	 * @param string      $actor_type        Tipo de actor.
	 * @param int|null    $actor_id          Usuario operador.
	 * @param string|null $reason            Motivo opcional.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function transition( string $public_id, int $expected_revision, string $target, string $actor_type, ?int $actor_id = null, ?string $reason = null ): array|WP_Error {
		global $wpdb;

		if ( 1 > $expected_revision || ! in_array( $target, ReservationStateMachine::STATES, true ) || ! in_array( $actor_type, array( 'customer', 'operator', 'system' ), true ) ) {
			return self::invalid();
		}

		if ( ! CatalogDatabase::begin() ) {
			return self::storage_error();
		}

		$table = Schema::reservations_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s FOR UPDATE", $public_id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			CatalogDatabase::rollback();
			return self::not_found();
		}

		if ( 'cancelada' === $target && 'cancelada' === $row['status'] ) {
			CatalogDatabase::commit();
			return self::public_response( $row );
		}

		if ( (int) $row['revision'] !== $expected_revision ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::stale_error( (int) $row['revision'] );
		}

		if ( ! ReservationStateMachine::allows( (string) $row['status'], $target ) ) {
			CatalogDatabase::rollback();
			return self::invalid_transition();
		}

		if ( ReservationStateMachine::consumes_capacity( (string) $row['status'] ) && ! ReservationStateMachine::consumes_capacity( $target ) && ! self::release_capacity( $row ) ) {
			CatalogDatabase::rollback();
			return self::storage_error();
		}

		$new_revision = $expected_revision + 1;
		$now          = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array(
				'status'       => $target,
				'revision'     => $new_revision,
				'updated_at'   => $now,
				'cancelled_at' => 'cancelada' === $target ? $now : null,
			),
			array(
				'id'       => (int) $row['id'],
				'revision' => $expected_revision,
			),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d', '%d' )
		);

		if ( 1 !== $updated || ! self::insert_event( (int) $row['id'], (string) $row['status'], $target, $actor_type, $actor_id, $reason, $new_revision ) || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return self::storage_error();
		}

		ReservationProjection::sync( $public_id );
		$fresh = self::row( $public_id );

		return null === $fresh ? self::storage_error() : self::public_response( $fresh );
	}

	/**
	 * Devuelve detalle privado para wp-admin.
	 *
	 * @param string $public_id UUID de reserva.
	 * @return array<string, mixed>|null
	 */
	public static function admin_detail( string $public_id ): ?array {
		$row = self::row( $public_id );

		if ( null === $row ) {
			return null;
		}

		$result                    = self::public_response( $row );
		$result['internal_id']     = (int) $row['id'];
		$result['guest_name']      = (string) $row['guest_name'];
		$result['guest_phone']     = (string) $row['guest_phone'];
		$result['guest_email']     = null === $row['guest_email'] ? null : (string) $row['guest_email'];
		$result['notes']           = null === $row['notes'] ? null : (string) $row['notes'];
		$result['zone_preference'] = null === $row['zone_preference'] ? null : (string) $row['zone_preference'];
		$result['events']          = self::events( (int) $row['id'] );

		return $result;
	}

	/**
	 * Lista un lote acotado para proyecciones administrativas.
	 *
	 * @param int $limit Máximo de filas.
	 * @return array<int, array<string, mixed>>
	 */
	public static function admin_list( int $limit = 100 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$table = Schema::reservations_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY starts_at_utc ASC, id ASC LIMIT %d", $limit ), ARRAY_A );

		return array_map( array( self::class, 'public_response' ), $rows );
	}

	/**
	 * Normaliza datos privados sin aceptar importes ni capacidad del cliente.
	 *
	 * @param array<string, mixed> $input Entrada.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function normalize( array $input ): array|WP_Error {
		$name  = self::text( $input['guest_name'] ?? null, 1, 100 );
		$phone = self::text( $input['phone'] ?? null, 3, 32 );
		$email = self::text( $input['email'] ?? '', 0, 191 );
		$notes = self::text( $input['notes'] ?? '', 0, 500 );
		$zone  = self::text( $input['zone_preference'] ?? '', 0, 100 );
		$date  = is_string( $input['date'] ?? null ) ? $input['date'] : '';
		$time  = is_string( $input['time'] ?? null ) ? $input['time'] : '';
		$party = self::integer( $input['party_size'] ?? null );

		if ( null === $name || null === $phone || null === $email || null === $notes || null === $zone || null === $party || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || 1 !== preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return self::invalid();
		}

		$sanitized_email = '' === $email ? null : sanitize_email( $email );

		if ( null !== $sanitized_email && $email !== $sanitized_email ) {
			return self::invalid();
		}

		return array(
			'guest_name'      => $name,
			'guest_phone'     => $phone,
			'guest_email'     => $sanitized_email,
			'notes'           => '' === $notes ? null : $notes,
			'zone_preference' => '' === $zone ? null : $zone,
			'date'            => $date,
			'time'            => $time,
			'party_size'      => $party,
		);
	}

	/**
	 * Libera exactamente los intervalos congelados al crear la reserva.
	 *
	 * @param array<string, mixed> $row Reserva bloqueada.
	 * @return bool
	 */
	private static function release_capacity( array $row ): bool {
		global $wpdb;

		$start     = new DateTimeImmutable( (string) $row['starts_at_utc'], new DateTimeZone( 'UTC' ) );
		$end       = new DateTimeImmutable( (string) $row['ends_at_utc'], new DateTimeZone( 'UTC' ) );
		$intervals = array();

		while ( $start < $end ) {
			$intervals[] = $start->format( 'Y-m-d H:i:s' );
			$start       = $start->modify( '+' . (int) $row['interval_minutes'] . ' minutes' );
		}

		$table        = Schema::reservation_occupancy_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $intervals ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$locked = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE interval_start_utc IN ({$placeholders}) ORDER BY interval_start_utc ASC FOR UPDATE", ...$intervals ), ARRAY_A );

		if ( count( $locked ) !== count( $intervals ) ) {
			return false;
		}

		foreach ( $locked as $interval ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET occupied = GREATEST(0, occupied - %d), revision = revision + 1, updated_at = %s WHERE interval_start_utc = %s AND occupied >= %d", $row['party_size'], current_time( 'mysql', true ), $interval['interval_start_utc'], $row['party_size'] ) );

			if ( 1 !== $updated ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Añade un evento append-only con revisión única.
	 *
	 * @param int         $reservation_id ID interno.
	 * @param string|null $from           Estado anterior.
	 * @param string      $to             Estado nuevo.
	 * @param string      $actor_type     Tipo de actor.
	 * @param int|null    $actor_id       Usuario actor.
	 * @param string|null $reason         Motivo.
	 * @param int         $revision       Revisión nueva.
	 * @return bool
	 */
	private static function insert_event( int $reservation_id, ?string $from, string $to, string $actor_type, ?int $actor_id, ?string $reason, int $revision ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			Schema::reservation_events_table_name(),
			array(
				'public_id'      => wp_generate_uuid4(),
				'reservation_id' => $reservation_id,
				'from_status'    => $from,
				'to_status'      => $to,
				'actor_type'     => $actor_type,
				'actor_id'       => $actor_id,
				'reason'         => null === $reason ? null : substr( sanitize_textarea_field( $reason ), 0, 500 ),
				'revision'       => $revision,
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Busca una reserva por UUID público.
	 *
	 * @param string $public_id UUID.
	 * @return array<string, mixed>|null
	 */
	private static function row( string $public_id ): ?array {
		global $wpdb;

		if ( ! wp_is_uuid( $public_id, 4 ) ) {
			return null;
		}

		$table = Schema::reservations_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s", $public_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Proyecta solo campos públicos no sensibles.
	 *
	 * @param array<string, mixed> $row Fila autoritativa.
	 * @return array<string, mixed>
	 */
	private static function public_response( array $row ): array {
		return array(
			'public_id'         => (string) $row['public_id'],
			'confirmation_code' => (string) $row['confirmation_code'],
			'status'            => (string) $row['status'],
			'revision'          => (int) $row['revision'],
			'date'              => (string) $row['local_date'],
			'time'              => substr( (string) $row['local_time'], 0, 5 ),
			'timezone'          => (string) $row['timezone'],
			'party_size'        => (int) $row['party_size'],
			'starts_at'         => mysql_to_rfc3339( (string) $row['starts_at_utc'] ),
			'ends_at'           => mysql_to_rfc3339( (string) $row['ends_at_utc'] ),
			'created_at'        => mysql_to_rfc3339( (string) $row['created_at'] ),
			'updated_at'        => mysql_to_rfc3339( (string) $row['updated_at'] ),
		);
	}

	/**
	 * Devuelve el historial privado ordenado por revisión.
	 *
	 * @param int $reservation_id ID interno.
	 * @return array<int, array<string, mixed>>
	 */
	private static function events( int $reservation_id ): array {
		global $wpdb;

		$table = Schema::reservation_events_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE reservation_id = %d ORDER BY revision ASC", $reservation_id ), ARRAY_A );

		return array_map(
			static fn( array $event ): array => array(
				'from'       => null === $event['from_status'] ? null : (string) $event['from_status'],
				'to'         => (string) $event['to_status'],
				'actor_type' => (string) $event['actor_type'],
				'actor_id'   => null === $event['actor_id'] ? null : (int) $event['actor_id'],
				'reason'     => null === $event['reason'] ? null : (string) $event['reason'],
				'revision'   => (int) $event['revision'],
				'created_at' => mysql_to_rfc3339( (string) $event['created_at'] ),
			),
			$rows
		);
	}

	/**
	 * Comprueba ownership sin revelar si el UUID existe.
	 *
	 * @param array<string, mixed> $row      Reserva.
	 * @param array<string, mixed> $identity Identidad actual.
	 * @param string               $token    Token invitado.
	 * @return bool
	 */
	private static function owns( array $row, array $identity, string $token ): bool {
		if ( null !== $row['user_id'] ) {
			return 0 < (int) $identity['user_id'] && (int) $row['user_id'] === (int) $identity['user_id'];
		}

		return 64 === strlen( $token ) && hash_equals( (string) $row['access_token_hash'], ReservationIdempotency::token_hash( $token ) );
	}

	/**
	 * Añade el token solo a una creación o replay invitado.
	 *
	 * @param array<string, mixed> $response Respuesta pública.
	 * @param array<string, mixed> $row      Reserva.
	 * @param array<string, mixed> $identity Identidad actual.
	 * @param string               $key      Clave idempotente.
	 * @return array<string, mixed>
	 */
	private static function with_token( array $response, array $row, array $identity, string $key ): array {
		if ( 0 === (int) $identity['user_id'] ) {
			$response['access_token'] = ReservationIdempotency::access_token( (string) $row['public_id'], $key );
		}

		return $response;
	}

	/**
	 * Normaliza texto acotado.
	 *
	 * @param mixed $value Entrada.
	 * @param int   $min   Longitud mínima.
	 * @param int   $max   Longitud máxima.
	 * @return string|null
	 */
	private static function text( mixed $value, int $min, int $max ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( sanitize_textarea_field( (string) $value ) );

		return $min <= strlen( $value ) && $max >= strlen( $value ) ? $value : null;
	}

	/**
	 * Acepta solo enteros positivos reales.
	 *
	 * @param mixed $value Entrada.
	 * @return int|null
	 */
	private static function integer( mixed $value ): ?int {
		return is_int( $value ) && 0 < $value ? $value : null;
	}

	/**
	 * Devuelve conflicto con alternativas recalculadas.
	 *
	 * @param array<string, mixed> $data Solicitud normalizada.
	 * @return WP_Error
	 */
	private static function unavailable( array $data ): WP_Error {
		$availability = ReservationAvailability::get( $data['date'], $data['party_size'] );

		return new WP_Error(
			'vicu_restaurante_unavailable',
			__( 'El horario solicitado ya no está disponible.', 'vicunav-restaurante' ),
			array(
				'status'       => 409,
				'alternatives' => is_wp_error( $availability ) ? array() : ReservationAvailability::alternatives( $availability, $data['time'] ),
			)
		);
	}

	/**
	 * Construye un error de validación estable.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_request', __( 'Los datos de la reserva no son válidos.', 'vicunav-restaurante' ), array( 'status' => 400 ) );
	}

	/**
	 * Construye un error de transición estable.
	 *
	 * @return WP_Error
	 */
	private static function invalid_transition(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_transition', __( 'La transición de reserva no está permitida.', 'vicunav-restaurante' ), array( 'status' => 409 ) );
	}

	/**
	 * Construye un error opaco de ownership o ausencia.
	 *
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'vicu_restaurante_not_found', __( 'No se encontró la reserva solicitada.', 'vicunav-restaurante' ), array( 'status' => 404 ) );
	}

	/**
	 * Construye un error seguro de persistencia.
	 *
	 * @return WP_Error
	 */
	private static function storage_error(): WP_Error {
		return new WP_Error( 'vicu_restaurante_storage_error', __( 'No se pudo guardar la reserva.', 'vicunav-restaurante' ), array( 'status' => 500 ) );
	}
}
