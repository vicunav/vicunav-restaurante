<?php
/**
 * Doble del servicio público de solicitudes.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Pagos;

use WP_Error;

// phpcs:disable Squiz.Commenting,Generic.Commenting.DocComment -- Doble contractual acotado a pruebas.

final class PaymentRequests {
	/** @var array<int, array<string, mixed>> */
	private static array $requests = array();

	private static int $next_id = 100;

	public static bool $publish_events = true;

	public static int $create_calls = 0;

	public static int $get_calls = 0;

	public static function reset(): void {
		self::$requests       = array();
		self::$next_id        = 100;
		self::$publish_events = true;
		self::$create_calls   = 0;
		self::$get_calls      = 0;
	}

	/** @return array<string, mixed>|WP_Error */
	public static function create( array $attributes ): array|WP_Error {
		++self::$create_calls;
		$type = (string) ( $attributes['external_type'] ?? '' );
		$id   = (string) ( $attributes['external_id'] ?? '' );

		foreach ( self::$requests as $request ) {
			if ( $type === $request['external_reference']['type'] && $id === $request['external_reference']['id'] ) {
				return (int) ( $attributes['amount_minor'] ?? 0 ) === $request['amount_minor'] && (string) ( $attributes['currency'] ?? '' ) === $request['currency'] && (string) ( $attributes['expires_at'] ?? '' ) === (string) $request['expires_at']
					? $request
					: new WP_Error( 'vicu_pagos_reference_collision', 'Colisión.', array( 'status' => 409 ) );
			}
		}

		if ( 'vicu_order' !== $type || '' === $id || 1 > (int) ( $attributes['amount_minor'] ?? 0 ) ) {
			return new WP_Error( 'vicu_pagos_invalid_request', 'Inválido.', array( 'status' => 400 ) );
		}

		$now                              = gmdate( DATE_RFC3339 );
		$request                          = array(
			'id'                 => self::$next_id++,
			'external_reference' => array(
				'type' => $type,
				'id'   => $id,
			),
			'amount_minor'       => (int) $attributes['amount_minor'],
			'currency'           => (string) $attributes['currency'],
			'provider'           => null,
			'state'              => PaymentRequestState::PENDING,
			'revision'           => 1,
			'expires_at'         => (string) ( $attributes['expires_at'] ?? '' ),
			'created_at'         => $now,
			'updated_at'         => $now,
		);
		self::$requests[ $request['id'] ] = $request;

		return $request;
	}

	/** @return array<string, mixed>|WP_Error */
	public static function get( int $request_id ): array|WP_Error {
		++self::$get_calls;

		return self::$requests[ $request_id ] ?? new WP_Error( 'vicu_pagos_request_not_found', 'Ausente.', array( 'status' => 404 ) );
	}

	/** @return array<string, mixed>|WP_Error */
	public static function transition( int $request_id, string $target, ?int $expected_revision = null ): array|WP_Error {
		$request = self::$requests[ $request_id ] ?? null;

		if ( null === $request ) {
			return new WP_Error( 'vicu_pagos_request_not_found', 'Ausente.' );
		}

		if ( null !== $expected_revision && $expected_revision !== $request['revision'] ) {
			return new WP_Error( 'vicu_pagos_concurrent_transition', 'Obsoleta.', array( 'status' => 409 ) );
		}

		if ( ! PaymentRequestState::can_transition( $request['state'], $target ) ) {
			return new WP_Error( 'vicu_pagos_invalid_transition', 'Inválida.', array( 'status' => 409 ) );
		}

		$from                          = $request['state'];
		$request['state']              = $target;
		$request['revision']           = $request['revision'] + 1;
		$request['updated_at']         = gmdate( DATE_RFC3339 );
		self::$requests[ $request_id ] = $request;
		$hooks                         = array(
			PaymentRequestState::CONFIRMED => 'vicu_pagos_confirmado',
			PaymentRequestState::REJECTED  => 'vicu_pagos_rechazado',
			PaymentRequestState::EXPIRED   => 'vicu_pagos_expirado',
		);

		if ( self::$publish_events && isset( $hooks[ $target ] ) ) {
			do_action( $hooks[ $target ], self::payload( $target, $from, $request ) );
		}

		return $request;
	}

	/** @return array<string, mixed>|WP_Error */
	public static function submit_manual( int $request_id, ?int $expected_revision ): array|WP_Error {
		$request = self::$requests[ $request_id ] ?? null;

		if ( null === $request ) {
			return new WP_Error( 'vicu_pagos_request_not_found', 'Ausente.' );
		}

		if ( null !== $expected_revision && $expected_revision !== $request['revision'] ) {
			return new WP_Error( 'vicu_pagos_concurrent_transition', 'Obsoleta.', array( 'status' => 409 ) );
		}

		if ( ! PaymentRequestState::can_transition( $request['state'], PaymentRequestState::PROOF_UPLOADED ) ) {
			return new WP_Error( 'vicu_pagos_invalid_transition', 'Inválida.', array( 'status' => 409 ) );
		}

		$request['state']              = PaymentRequestState::PROOF_UPLOADED;
		$request['provider']           = 'manual';
		$request['revision']           = $request['revision'] + 1;
		$request['updated_at']         = gmdate( DATE_RFC3339 );
		self::$requests[ $request_id ] = $request;

		return $request;
	}

	/** @param array<string, mixed> $request Solicitud alterada para pruebas negativas. */
	public static function replace( array $request ): void {
		self::$requests[ (int) $request['id'] ] = $request;
	}

	/** @return array<string, mixed> */
	private static function payload( string $event, string $from, array $request ): array {
		return array(
			'payload_version' => '1.0.0',
			'event'           => $event,
			'occurred_at'     => $request['updated_at'],
			'transition'      => array(
				'from' => $from,
				'to'   => $event,
			),
			'request'         => $request,
		);
	}
}
