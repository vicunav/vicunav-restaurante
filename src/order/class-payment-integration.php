<?php
/**
 * Adaptador público entre pedidos y `vicunav-pagos`.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Order;

use DateTimeImmutable;
use DateTimeZone;
use Vicu\Pagos\PaymentRequests;
use WP_Error;

/**
 * Nunca lee persistencia, posts o metadatos internos del motor de pagos.
 */
final class PaymentIntegration {
	public const RECONCILIATION_HOOK = 'vicu_restaurante_reconcile_payments';

	/**
	 * Evita hooks duplicados.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Escucha eventos versionados y la tarea repetible.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		foreach ( array( 'vicu_pagos_comprobante_recibido', 'vicu_pagos_confirmado', 'vicu_pagos_rechazado', 'vicu_pagos_expirado' ) as $hook ) {
			add_action( $hook, array( self::class, 'handle_event' ) );
		}

		add_action( self::RECONCILIATION_HOOK, array( self::class, 'reconcile_due' ) );
		self::$hooks_registered = true;
	}

	/**
	 * Agenda una única reconciliación horaria.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::RECONCILIATION_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::RECONCILIATION_HOOK );
		}
	}

	/**
	 * Retira solo el cron propio.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::RECONCILIATION_HOOK );
	}

	/**
	 * Crea o recupera la solicitud con la referencia e importes congelados.
	 *
	 * @param string $public_id UUID del pedido.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function ensure_request( string $public_id ): array|WP_Error {
		$order = OrderService::payment_record( $public_id );

		if ( null === $order ) {
			return new WP_Error( 'vicu_restaurante_not_found', __( 'No se encontró el pedido solicitado.', 'vicunav-restaurante' ), array( 'status' => 404 ) );
		}

		$request = PaymentRequests::create(
			array(
				'external_type' => 'vicu_order',
				'external_id'   => $order['public_id'],
				'amount_minor'  => $order['total_minor'],
				'currency'      => $order['currency'],
				'expires_at'    => self::payment_expiration( $order['payment_expires_at'] ),
			)
		);

		if ( is_wp_error( $request ) ) {
			$error = 'vicu_pagos_reference_collision' === $request->get_error_code()
				? 'vicu_restaurante_payment_mismatch'
				: 'vicu_restaurante_dependency_unavailable';
			OrderService::mark_payment_error( $public_id, $error );
			return $request;
		}

		$observed = OrderService::observe_payment( $request, 'checkout' );

		return is_wp_error( $observed ) ? $observed : $request;
	}

	/**
	 * Convierte el vencimiento UTC interno al RFC 3339 exigido por pagos.
	 *
	 * @param string $value Fecha UTC normalizada por la proyección del pedido.
	 * @return string Fecha pública o cadena vacía si la persistencia es inválida.
	 */
	private static function payment_expiration( string $value ): string {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s', $value, new DateTimeZone( 'UTC' ) );

		return false === $date ? '' : $date->format( DATE_RFC3339 );
	}

	/**
	 * Consume solo payloads 1.0.0 del dominio y referencia propios.
	 *
	 * @param mixed $payload Payload del hook.
	 * @return void
	 */
	public static function handle_event( mixed $payload ): void {
		if ( ! is_array( $payload ) || '1.0.0' !== ( $payload['payload_version'] ?? null ) || ! is_array( $payload['request'] ?? null ) ) {
			return;
		}

		$reference = $payload['request']['external_reference'] ?? null;

		if ( ! is_array( $reference ) || 'vicu_order' !== ( $reference['type'] ?? null ) ) {
			return;
		}

		OrderService::observe_payment( $payload['request'], 'hook' );
	}

	/**
	 * Recupera un pedido aunque el hook se haya perdido.
	 *
	 * @param string $public_id UUID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function reconcile_order( string $public_id ): array|WP_Error {
		$order = OrderService::payment_record( $public_id );

		if ( null === $order ) {
			return new WP_Error( 'vicu_restaurante_not_found', __( 'No se encontró el pedido solicitado.', 'vicunav-restaurante' ), array( 'status' => 404 ) );
		}

		if ( null === $order['payment_request_id'] ) {
			return self::ensure_request( $public_id );
		}

		$request = PaymentRequests::get( $order['payment_request_id'] );

		if ( is_wp_error( $request ) ) {
			OrderService::mark_payment_error( $public_id, 'vicu_restaurante_dependency_unavailable' );
			return $request;
		}

		return OrderService::observe_payment( $request, 'reconciliation' );
	}

	/**
	 * Procesa un lote acotado y devuelve salud agregada.
	 *
	 * @param int $limit Máximo de pedidos.
	 * @return array{synced: int, failed: int}
	 */
	public static function reconcile_due( int $limit = 100 ): array {
		$result = array(
			'synced' => 0,
			'failed' => 0,
		);

		foreach ( OrderService::payment_candidates( $limit ) as $order ) {
			$key = is_wp_error( self::reconcile_order( $order['public_id'] ) ) ? 'failed' : 'synced';
			++$result[ $key ];
		}

		return $result;
	}
}
