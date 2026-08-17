<?php
/**
 * Motor autoritativo de disponibilidad de reservas.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Reservation;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Vicu\Restaurante\Schema;
use WP_Error;

/**
 * Calcula slots por todos los intervalos que cruza la duración.
 */
final class ReservationAvailability {
	/**
	 * Devuelve disponibilidad agregada y no cacheable.
	 *
	 * @param string                 $date       Fecha local.
	 * @param int                    $party_size Personas.
	 * @param DateTimeImmutable|null $now        Instante UTC inyectable.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( string $date, int $party_size, ?DateTimeImmutable $now = null ): array|WP_Error {
		$settings = ReservationSettings::get();
		$context  = self::date_context( $date, $settings );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( $party_size < $settings['min_party_size'] || $party_size > $settings['max_party_size'] ) {
			return self::result( 'party-too-large', 'party_size', array(), $settings );
		}

		if ( 'blocked' === $context['status'] ) {
			return self::result( 'blocked', $context['reason'], array(), $settings );
		}

		if ( array() === $context['periods'] ) {
			return self::result( 'closed', 'closed', array(), $settings );
		}

		$now        = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$candidates = self::candidates( $date, $context['periods'], $settings, $now );
		$occupancy  = self::occupancy( $candidates, $settings );
		$threshold  = (int) ceil( $settings['capacity'] * $settings['limited_threshold_bps'] / 10000 );
		$slots      = array();

		foreach ( $candidates as $candidate ) {
			$remaining = $settings['capacity'];

			foreach ( self::intervals( $candidate['start'], $candidate['end'], $settings['interval_minutes'] ) as $interval ) {
				$key       = $interval->format( 'Y-m-d H:i:s' );
				$remaining = min( $remaining, $settings['capacity'] - ( $occupancy[ $key ] ?? 0 ) );
			}

			$status  = $remaining < $party_size ? 'unavailable' : ( $remaining <= $threshold ? 'limited' : 'available' );
			$slots[] = array(
				'time'               => $candidate['local_time'],
				'status'             => $status,
				'remaining_capacity' => max( 0, $remaining ),
				'starts_at'          => $candidate['start']->format( DATE_RFC3339 ),
			);
		}

		return self::result( 'ok', null, $slots, $settings );
	}

	/**
	 * Valida un inicio exacto y devuelve los intervalos UTC del rango.
	 *
	 * @param string                 $date       Fecha local.
	 * @param string                 $time       Hora local.
	 * @param int                    $party_size Personas.
	 * @param DateTimeImmutable|null $now        Instante UTC.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function slot_definition( string $date, string $time, int $party_size, ?DateTimeImmutable $now = null ): array|WP_Error {
		$availability = self::get( $date, $party_size, $now );

		if ( is_wp_error( $availability ) || 'ok' !== $availability['status'] ) {
			return is_wp_error( $availability ) ? $availability : self::unavailable( $availability );
		}

		foreach ( $availability['slots'] as $slot ) {
			if ( $time !== $slot['time'] ) {
				continue;
			}

			if ( 'unavailable' === $slot['status'] ) {
				return self::unavailable( $availability, $time );
			}

			$settings = ReservationSettings::get();
			$start    = new DateTimeImmutable( $slot['starts_at'] );
			$end      = $start->add( new DateInterval( 'PT' . $settings['duration_minutes'] . 'M' ) );

			return array(
				'start'     => $start->setTimezone( new DateTimeZone( 'UTC' ) ),
				'end'       => $end->setTimezone( new DateTimeZone( 'UTC' ) ),
				'intervals' => self::intervals( $start, $end, $settings['interval_minutes'] ),
				'settings'  => $settings,
			);
		}

		return self::unavailable( $availability, $time );
	}

	/**
	 * Alternativas más próximas que admiten el grupo.
	 *
	 * @param array<string, mixed> $availability Resultado actual.
	 * @param string               $requested    Hora solicitada.
	 * @param int                  $limit        Máximo.
	 * @return array<int, array<string, mixed>>
	 */
	public static function alternatives( array $availability, string $requested, int $limit = 3 ): array {
		$requested_minutes = self::minutes( $requested );
		$slots             = array_values(
			array_filter(
				$availability['slots'] ?? array(),
				static fn( array $slot ): bool => $requested !== $slot['time'] && 'unavailable' !== $slot['status']
			)
		);
		usort( $slots, static fn( array $left, array $right ): int => abs( self::minutes( $left['time'] ) - $requested_minutes ) <=> abs( self::minutes( $right['time'] ) - $requested_minutes ) );

		return array_slice( $slots, 0, max( 1, min( 10, $limit ) ) );
	}

	/**
	 * Resuelve horario regular, excepción o cierre para una fecha local.
	 *
	 * @param string               $date     Fecha local.
	 * @param array<string, mixed> $settings Ajustes vigentes.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function date_context( string $date, array $settings ): array|WP_Error {
		$timezone = new DateTimeZone( $settings['timezone'] );
		$local    = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );

		if ( false === $local || $local->format( 'Y-m-d' ) !== $date ) {
			return new WP_Error( 'vicu_restaurante_invalid_request', __( 'La fecha de reserva no es válida.', 'vicunav-restaurante' ), array( 'status' => 400 ) );
		}

		if ( in_array( $local->format( 'm-d' ), $settings['recurring_closures'], true ) ) {
			return array(
				'status'  => 'blocked',
				'reason'  => 'recurring_closure',
				'periods' => array(),
			);
		}

		foreach ( $settings['exceptions'] as $exception ) {
			if ( $date === $exception['date'] ) {
				return array(
					'status'  => $exception['closed'] ? 'blocked' : 'ok',
					'reason'  => $exception['closed'] ? 'exception_closed' : null,
					'periods' => $exception['periods'],
				);
			}
		}

		$day = strtolower( $local->format( 'l' ) );

		return array(
			'status'  => 'ok',
			'reason'  => null,
			'periods' => $settings['weekly_schedule'][ $day ] ?? array(),
		);
	}

	/**
	 * Genera inicios cuyo rango completo cabe en un periodo.
	 *
	 * @param string               $date     Fecha local.
	 * @param array<int, mixed>    $periods  Periodos del día.
	 * @param array<string, mixed> $settings Ajustes vigentes.
	 * @param DateTimeImmutable    $now      Instante UTC.
	 * @return array<int, array{local_time: string, start: DateTimeImmutable, end: DateTimeImmutable}>
	 */
	private static function candidates( string $date, array $periods, array $settings, DateTimeImmutable $now ): array {
		$timezone = new DateTimeZone( $settings['timezone'] );
		$notice   = $now->setTimezone( new DateTimeZone( 'UTC' ) )->add( new DateInterval( 'PT' . $settings['min_notice_minutes'] . 'M' ) );
		$result   = array();

		foreach ( $periods as $period ) {
			$cursor = new DateTimeImmutable( $date . ' ' . $period['opens_at'], $timezone );
			$close  = new DateTimeImmutable( $date . ' ' . $period['closes_at'], $timezone );

			while ( $cursor->add( new DateInterval( 'PT' . $settings['duration_minutes'] . 'M' ) ) <= $close ) {
				$start_utc = $cursor->setTimezone( new DateTimeZone( 'UTC' ) );

				if ( $start_utc >= $notice ) {
					$result[] = array(
						'local_time' => $cursor->format( 'H:i' ),
						'start'      => $start_utc,
						'end'        => $start_utc->add( new DateInterval( 'PT' . $settings['duration_minutes'] . 'M' ) ),
					);
				}

				$cursor = $cursor->add( new DateInterval( 'PT' . $settings['interval_minutes'] . 'M' ) );
			}
		}

		return $result;
	}

	/**
	 * Expande un rango a sus intervalos de ocupación UTC.
	 *
	 * @param DateTimeImmutable $start   Inicio UTC.
	 * @param DateTimeImmutable $end     Fin UTC.
	 * @param int               $minutes Tamaño del intervalo.
	 * @return DateTimeImmutable[]
	 */
	private static function intervals( DateTimeImmutable $start, DateTimeImmutable $end, int $minutes ): array {
		$result = array();
		$cursor = $start->setTimezone( new DateTimeZone( 'UTC' ) );

		while ( $cursor < $end ) {
			$result[] = $cursor;
			$cursor   = $cursor->add( new DateInterval( 'PT' . $minutes . 'M' ) );
		}

		return $result;
	}

	/**
	 * Lee la ocupación agregada del rango candidato.
	 *
	 * @param array<int, mixed>    $candidates Slots candidatos.
	 * @param array<string, mixed> $settings   Ajustes vigentes.
	 * @return array<string, int>
	 */
	private static function occupancy( array $candidates, array $settings ): array {
		global $wpdb;

		if ( array() === $candidates ) {
			return array();
		}

		$first          = $candidates[0]['start']->format( 'Y-m-d H:i:s' );
		$last_candidate = $candidates[ count( $candidates ) - 1 ];
		$last           = $last_candidate['end']->format( 'Y-m-d H:i:s' );
		$table          = Schema::reservation_occupancy_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT interval_start_utc, occupied FROM {$table} WHERE interval_start_utc >= %s AND interval_start_utc < %s", $first, $last ), ARRAY_A );

		$result = array();

		foreach ( $rows as $row ) {
			$result[ $row['interval_start_utc'] ] = min( $settings['capacity'], (int) $row['occupied'] );
		}

		return $result;
	}

	/**
	 * Construye la respuesta estable de disponibilidad.
	 *
	 * @param string               $status   Estado agregado.
	 * @param string|null          $reason   Motivo estable.
	 * @param array<int, mixed>    $slots    Slots calculados.
	 * @param array<string, mixed> $settings Ajustes vigentes.
	 * @return array<string, mixed>
	 */
	private static function result( string $status, ?string $reason, array $slots, array $settings ): array {
		return array(
			'status'            => $status,
			'reason'            => $reason,
			'slots'             => $slots,
			'timezone'          => $settings['timezone'],
			'settings_revision' => ReservationSettings::revision(),
		);
	}

	/**
	 * Devuelve conflicto con alternativas seguras.
	 *
	 * @param array<string, mixed> $availability Resultado de disponibilidad.
	 * @param string               $time         Hora solicitada.
	 * @return WP_Error
	 */
	private static function unavailable( array $availability, string $time = '' ): WP_Error {
		return new WP_Error(
			'vicu_restaurante_unavailable',
			__( 'El horario solicitado no está disponible.', 'vicunav-restaurante' ),
			array(
				'status'       => 409,
				'alternatives' => '' === $time ? array() : self::alternatives( $availability, $time ),
			)
		);
	}

	/**
	 * Convierte HH:mm a minutos desde medianoche.
	 *
	 * @param string $time Hora.
	 * @return int
	 */
	private static function minutes( string $time ): int {
		$parts = array_map( 'intval', explode( ':', $time ) );

		return ( $parts[0] ?? 0 ) * 60 + ( $parts[1] ?? 0 );
	}
}
