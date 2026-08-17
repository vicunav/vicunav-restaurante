<?php
/**
 * Doble del proveedor manual público.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Pagos;

use WP_Error;

// phpcs:disable Squiz.Commenting,Generic.Commenting.DocComment -- Doble contractual acotado a pruebas.

final class ManualPaymentProvider {
	private static bool $enabled = false;

	/** @var array<string, array<string, mixed>> */
	private static array $submissions = array();

	private static int $next_id = 500;

	public static function reset(): void {
		self::$enabled     = false;
		self::$submissions = array();
		self::$next_id     = 500;
	}

	/** @return array<string, mixed>|WP_Error */
	public static function configure( array $configuration ): array|WP_Error {
		if ( array( 'enabled' ) !== array_keys( $configuration ) || ! is_bool( $configuration['enabled'] ) ) {
			return new WP_Error( 'vicu_pagos_manual_invalid_configuration', 'Inválida.' );
		}

		self::$enabled = $configuration['enabled'];

		return self::get_configuration();
	}

	/** @return array{provider: string, enabled: bool} */
	public static function get_configuration(): array {
		return array(
			'provider' => 'manual',
			'enabled'  => self::$enabled,
		);
	}

	/** @return array<string, mixed>|WP_Error */
	public static function submit_proof( int $request_id, array $submission, ?int $expected_revision = null ): array|WP_Error {
		if ( ! self::$enabled ) {
			return new WP_Error( 'vicu_pagos_manual_provider_disabled', 'Deshabilitado.', array( 'status' => 409 ) );
		}

		$key = $request_id . '|' . (string) ( $submission['idempotency_key'] ?? '' );

		if ( isset( self::$submissions[ $key ] ) ) {
			if ( ( $submission['proof_reference'] ?? null ) !== self::$submissions[ $key ]['proof_reference'] ) {
				return new WP_Error( 'vicu_pagos_manual_submission_collision', 'Colisión.', array( 'status' => 409 ) );
			}

			$request = PaymentRequests::get( $request_id );

			return is_wp_error( $request ) ? $request : array(
				'request'    => $request,
				'submission' => self::$submissions[ $key ],
			);
		}

		$request = PaymentRequests::get( $request_id );
		$from    = is_wp_error( $request ) ? '' : $request['state'];
		$request = PaymentRequests::submit_manual( $request_id, $expected_revision );

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$public                    = array(
			'id'               => self::$next_id++,
			'provider'         => 'manual',
			'proof_reference'  => (string) $submission['proof_reference'],
			'request_revision' => $request['revision'],
			'submitted_at'     => gmdate( DATE_RFC3339 ),
		);
		self::$submissions[ $key ] = $public;

		if ( PaymentRequests::$publish_events ) {
			do_action(
				'vicu_pagos_comprobante_recibido',
				array(
					'payload_version' => '1.0.0',
					'event'           => 'comprobante_recibido',
					'occurred_at'     => $public['submitted_at'],
					'transition'      => array(
						'from' => $from,
						'to'   => PaymentRequestState::PROOF_UPLOADED,
					),
					'provider'        => 'manual',
					'submission'      => $public,
					'request'         => $request,
				)
			);
		}

		return array(
			'request'    => $request,
			'submission' => $public,
		);
	}
}
