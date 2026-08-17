<?php
/**
 * Checkout, snapshots y estado autoritativo de pedidos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Order;

use Vicu\Pagos\ManualPaymentProvider;
use Vicu\Pagos\PaymentRequestState;
use Vicu\Restaurante\Cart\CartService;
use Vicu\Restaurante\Catalog\CatalogDatabase;
use Vicu\Restaurante\Commerce\DiscountService;
use Vicu\Restaurante\Commerce\PricingRevision;
use Vicu\Restaurante\Schema;
use Vicu\Restaurante\Settings\RestaurantSettings;
use WP_Error;

/**
 * Mantiene tablas de pedido como autoridad y la proyección WordPress como derivada.
 */
final class OrderService {
	/**
	 * Convierte exactamente una vez un carrito revalidado.
	 *
	 * @param array<string, int|string> $identity        Identidad del carrito.
	 * @param string                    $idempotency_key Clave externa.
	 * @param array<string, mixed>      $input           Datos privados de checkout.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function checkout( array $identity, string $idempotency_key, array $input ): array|WP_Error {
		global $wpdb;

		$data = self::normalize_checkout( $input );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$fingerprint = hash( 'sha256', (string) wp_json_encode( self::canonicalize( $data ) ) );
		$scope       = 'order:create|' . (string) $identity['key'];

		if ( ! CatalogDatabase::begin() ) {
			return self::storage_error();
		}

		$claim = OrderIdempotency::claim( $scope, $idempotency_key, $fingerprint );

		if ( is_wp_error( $claim ) ) {
			CatalogDatabase::rollback();
			return $claim;
		}

		if ( 'replay' === $claim['mode'] ) {
			CatalogDatabase::commit();
			$order_public_id = (string) ( $claim['response']['order_public_id'] ?? '' );
			PaymentIntegration::ensure_request( $order_public_id );
			$row = self::row( $order_public_id );

			if ( null === $row ) {
				return self::storage_error();
			}

			return self::with_access_token( self::public_response( $row ), $row, $identity, $idempotency_key );
		}

		$locked_cart = CartService::lock_for_checkout( $identity, $data['expected_revision'] );

		if ( is_wp_error( $locked_cart ) ) {
			CatalogDatabase::rollback();
			return $locked_cart;
		}

		$cart = $locked_cart['cart'];

		if ( ! self::checkout_matches_cart( $data, $cart ) ) {
			CatalogDatabase::rollback();
			return self::invalid();
		}

		if ( null !== $cart['discount_code'] ) {
			$consumed = DiscountService::consume_in_transaction( $cart['discount_code'], (int) $cart['totals']['subtotal_minor'] );

			if ( is_wp_error( $consumed ) ) {
				CatalogDatabase::rollback();
				return $consumed;
			}
		}

		$public_id       = wp_generate_uuid4();
		$access_token    = OrderIdempotency::access_token( $public_id, $idempotency_key );
		$order_number    = 'R-' . gmdate( 'Ymd' ) . '-' . strtoupper( substr( str_replace( '-', '', $public_id ), 0, 8 ) );
		$payment_expires = gmdate( 'Y-m-d H:i:s', time() + RestaurantSettings::payment_lifetime_minutes() * MINUTE_IN_SECONDS );
		$now             = current_time( 'mysql', true );
		$totals          = $cart['totals'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			Schema::orders_table_name(),
			array(
				'public_id'             => $public_id,
				'order_number'          => $order_number,
				'cart_public_id'        => $cart['public_id'],
				'user_id'               => 0 < (int) $identity['user_id'] ? (int) $identity['user_id'] : null,
				'access_token_hash'     => OrderIdempotency::access_token_hash( $access_token ),
				'status'                => 'pendiente_pago',
				'revision'              => 1,
				'fulfillment'           => $cart['fulfillment'],
				'customer_name'         => $data['customer_name'],
				'customer_email'        => $data['customer_email'],
				'customer_phone'        => $data['customer_phone'],
				'delivery_address'      => $data['delivery_address'],
				'delivery_instructions' => $data['delivery_instructions'],
				'customer_note'         => $data['customer_note'],
				'currency'              => $totals['currency'],
				'subtotal_minor'        => $totals['subtotal_minor'],
				'discount_total'        => $totals['discount_total'],
				'tax_total'             => $totals['tax_total'],
				'tip_total'             => $totals['tip_total'],
				'delivery_total'        => $totals['delivery_total'],
				'total_minor'           => $totals['total'],
				'totals_json'           => wp_json_encode( $totals ),
				'payment_expires_at'    => $payment_expires,
				'payment_sync_status'   => 'pending',
				'payment_request_id'    => null,
				'payment_revision'      => 0,
				'projection_status'     => 'pending',
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			CatalogDatabase::rollback();
			return self::storage_error();
		}

		$order_id = (int) $wpdb->insert_id;

		foreach ( $cart['items'] as $item ) {
			if ( ! self::insert_item( $order_id, $item, $now ) ) {
				CatalogDatabase::rollback();
				return self::storage_error();
			}
		}

		if ( ! self::insert_event( $order_id, null, 'pendiente_pago', 'customer', 0 < (int) $identity['user_id'] ? (int) $identity['user_id'] : null, null, array(), 1 ) ) {
			CatalogDatabase::rollback();
			return self::storage_error();
		}

		$converted = CartService::convert_in_transaction( (int) $locked_cart['internal_id'], (int) $cart['revision'] );

		if ( is_wp_error( $converted ) || ! OrderIdempotency::complete( (int) $claim['id'], 201, array( 'order_public_id' => $public_id ) ) || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return is_wp_error( $converted ) ? $converted : self::storage_error();
		}

		PricingRevision::clear_cache();
		OrderProjection::sync( $public_id );
		PaymentIntegration::ensure_request( $public_id );
		$row = self::row( $public_id );

		return null === $row ? self::storage_error() : self::with_access_token( self::public_response( $row ), $row, $identity, $idempotency_key );
	}

	/**
	 * Lee un pedido si la cuenta o el token acreditan ownership.
	 *
	 * @param string                    $public_id UUID.
	 * @param array<string, int|string> $identity  Identidad, puede ser invitado.
	 * @param string                    $token     Token de header.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( string $public_id, array $identity, string $token = '' ): array|WP_Error {
		$row = self::row( $public_id );

		if ( null === $row || ! self::owns( $row, $identity, $token ) ) {
			return self::not_found();
		}

		return self::public_response( $row );
	}

	/**
	 * Aplica una transición válida con CAS y un único evento.
	 *
	 * @param string               $public_id         UUID.
	 * @param int                  $expected_revision Revisión.
	 * @param string               $target            Destino.
	 * @param string               $actor_type        operator, payment o system.
	 * @param int|null             $actor_id          Usuario opcional.
	 * @param string|null          $reason            Motivo seguro.
	 * @param array<string, mixed> $metadata          Metadatos no sensibles.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function transition( string $public_id, int $expected_revision, string $target, string $actor_type, ?int $actor_id = null, ?string $reason = null, array $metadata = array() ): array|WP_Error {
		global $wpdb;

		$target     = sanitize_key( $target );
		$actor_type = sanitize_key( $actor_type );
		$reason     = null === $reason ? null : trim( sanitize_textarea_field( $reason ) );

		if ( 1 > $expected_revision || ! in_array( $target, OrderStateMachine::STATES, true ) || ! in_array( $actor_type, array( 'operator', 'payment', 'system' ), true ) || ( null !== $reason && 500 < strlen( $reason ) ) ) {
			return self::invalid();
		}

		if ( ! CatalogDatabase::begin() ) {
			return self::storage_error();
		}

		$table = Schema::orders_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s FOR UPDATE", $public_id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			CatalogDatabase::rollback();
			return self::not_found();
		}

		if ( (int) $row['revision'] !== $expected_revision ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::stale_error( (int) $row['revision'] );
		}

		if ( ! OrderStateMachine::allows( (string) $row['status'], $target, (string) $row['fulfillment'] ) || ( 'cancelado' === $target && in_array( $row['status'], array( 'confirmado', 'en_preparacion' ), true ) && ( null === $reason || '' === $reason ) ) ) {
			CatalogDatabase::rollback();
			return self::invalid_transition();
		}

		$new_revision = $expected_revision + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array(
				'status'     => $target,
				'revision'   => $new_revision,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id'       => (int) $row['id'],
				'revision' => $expected_revision,
			),
			array( '%s', '%d', '%s' ),
			array( '%d', '%d' )
		);

		if ( 1 !== $updated || ! self::insert_event( (int) $row['id'], (string) $row['status'], $target, $actor_type, $actor_id, $reason, self::safe_metadata( $metadata ), $new_revision ) || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return self::storage_error();
		}

		OrderProjection::sync( $public_id );
		$fresh = self::row( $public_id );

		return null === $fresh ? self::storage_error() : self::public_response( $fresh );
	}

	/**
	 * Devuelve detalle privado para administración autorizada.
	 *
	 * @param string $public_id UUID.
	 * @return array<string, mixed>|null
	 */
	public static function admin_detail( string $public_id ): ?array {
		$row = self::row( $public_id );

		if ( null === $row ) {
			return null;
		}

		$result                               = self::public_response( $row );
		$result['internal_id']                = (int) $row['id'];
		$result['customer']                   = array(
			'name'  => (string) $row['customer_name'],
			'email' => null === $row['customer_email'] ? null : (string) $row['customer_email'],
			'phone' => (string) $row['customer_phone'],
		);
		$result['delivery_address']           = null === $row['delivery_address'] ? null : (string) $row['delivery_address'];
		$result['delivery_instructions']      = null === $row['delivery_instructions'] ? null : (string) $row['delivery_instructions'];
		$result['customer_note']              = null === $row['customer_note'] ? null : (string) $row['customer_note'];
		$result['projection_status']          = (string) $row['projection_status'];
		$result['payment_last_error']         = null === ( $row['payment_last_error'] ?? null ) ? null : (string) $row['payment_last_error'];
		$result['payment_last_reconciled_at'] = null === ( $row['payment_last_reconciled_at'] ?? null ) ? null : mysql_to_rfc3339( (string) $row['payment_last_reconciled_at'] );
		$result['events']                     = self::events( (int) $row['id'] );

		return $result;
	}

	/**
	 * Lista pedidos para reconstrucción y administración.
	 *
	 * @param int $limit Límite acotado.
	 * @return array<int, array<string, mixed>>
	 */
	public static function admin_list( int $limit = 100 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$table = Schema::orders_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", $limit ), ARRAY_A );

		return array_map( array( self::class, 'public_response' ), $rows );
	}

	/**
	 * Actualiza únicamente el estado reconstruible de la proyección.
	 *
	 * @param string $public_id UUID.
	 * @param string $status    synced o pending.
	 * @return bool
	 */
	public static function set_projection_status( string $public_id, string $status ): bool {
		global $wpdb;

		if ( ! in_array( $status, array( 'synced', 'pending' ), true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( Schema::orders_table_name(), array( 'projection_status' => $status ), array( 'public_id' => $public_id ), array( '%s' ), array( '%s' ) );

		return false !== $result;
	}

	/**
	 * Devuelve únicamente los datos necesarios para el contrato de pagos.
	 *
	 * @param string $public_id UUID del pedido.
	 * @return array<string, mixed>|null
	 */
	public static function payment_record( string $public_id ): ?array {
		$row = self::row( $public_id );

		return null === $row ? null : self::payment_record_from_row( $row );
	}

	/**
	 * Lista candidatos acotados para reconciliación.
	 *
	 * @param int $limit Límite máximo.
	 * @return array<int, array<string, mixed>>
	 */
	public static function payment_candidates( int $limit = 100 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$table = Schema::orders_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE payment_sync_status <> 'synced' OR status IN ('pendiente_pago','pago_en_revision') ORDER BY updated_at ASC, id ASC LIMIT %d", $limit ), ARRAY_A );

		return array_map( array( self::class, 'payment_record_from_row' ), $rows );
	}

	/**
	 * Registra un fallo recuperable sin alterar el estado del pedido.
	 *
	 * @param string $public_id UUID.
	 * @param string $error_code Código seguro.
	 * @return bool
	 */
	public static function mark_payment_error( string $public_id, string $error_code ): bool {
		global $wpdb;

		$error_code = substr( sanitize_key( $error_code ), 0, 64 );

		if ( '' === $error_code ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			Schema::orders_table_name(),
			array(
				'payment_sync_status'        => 'error',
				'payment_last_error'         => $error_code,
				'payment_last_reconciled_at' => current_time( 'mysql', true ),
			),
			array( 'public_id' => $public_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Aplica una observación pública de pagos bajo bloqueo y revisión monotónica.
	 *
	 * @param array<string, mixed> $request Solicitud pública de pagos.
	 * @param string               $source  hook, checkout, evidence o reconciliation.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function observe_payment( array $request, string $source ): array|WP_Error {
		global $wpdb;

		$normalized = self::normalize_payment_request( $request );
		$source     = sanitize_key( $source );

		if ( is_wp_error( $normalized ) || ! in_array( $source, array( 'hook', 'checkout', 'evidence', 'reconciliation' ), true ) ) {
			return is_wp_error( $normalized ) ? $normalized : self::invalid();
		}

		if ( ! CatalogDatabase::begin() ) {
			return self::storage_error();
		}

		$table = Schema::orders_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s FOR UPDATE", $normalized['external_id'] ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			CatalogDatabase::rollback();
			return self::not_found();
		}

		if ( ! self::payment_matches_order( $normalized, $row ) ) {
			if ( ! self::update_payment_error_row( (int) $row['id'], 'vicu_restaurante_payment_mismatch' ) || ! CatalogDatabase::commit() ) {
				CatalogDatabase::rollback();
				return self::storage_error();
			}
			OrderProjection::sync( (string) $row['public_id'] );

			return new WP_Error( 'vicu_restaurante_payment_mismatch', __( 'La solicitud de pago no coincide con el pedido.', 'vicunav-restaurante' ), array( 'status' => 409 ) );
		}

		if ( $normalized['revision'] <= (int) $row['payment_revision'] ) {
			if ( ! CatalogDatabase::commit() ) {
				CatalogDatabase::rollback();
				return self::storage_error();
			}
			return self::public_response( $row );
		}

		$targets = self::payment_targets( (string) $row['status'], $normalized['state'] );

		if ( is_wp_error( $targets ) ) {
			if ( ! self::update_payment_error_row( (int) $row['id'], 'vicu_restaurante_payment_attention' ) || ! CatalogDatabase::commit() ) {
				CatalogDatabase::rollback();
				return self::storage_error();
			}
			OrderProjection::sync( (string) $row['public_id'] );
			return $targets;
		}

		$current_status   = (string) $row['status'];
		$current_revision = (int) $row['revision'];

		foreach ( $targets as $target ) {
			if ( ! OrderStateMachine::allows( $current_status, $target, (string) $row['fulfillment'] ) ) {
				CatalogDatabase::rollback();
				return self::invalid_transition();
			}

			$new_revision = $current_revision + 1;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$table,
				array(
					'status'     => $target,
					'revision'   => $new_revision,
					'updated_at' => current_time( 'mysql', true ),
				),
				array(
					'id'       => (int) $row['id'],
					'revision' => $current_revision,
				),
				array( '%s', '%d', '%s' ),
				array( '%d', '%d' )
			);

			$metadata = array(
				'payment_request_id' => $normalized['id'],
				'payment_revision'   => $normalized['revision'],
				'payment_state'      => $normalized['state'],
				'source'             => $source,
			);

			if ( 1 !== $updated || ! self::insert_event( (int) $row['id'], $current_status, $target, 'payment', null, null, $metadata, $new_revision ) ) {
				CatalogDatabase::rollback();
				return self::storage_error();
			}

			$current_status   = $target;
			$current_revision = $new_revision;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array(
				'payment_request_id'         => (string) $normalized['id'],
				'payment_revision'           => $normalized['revision'],
				'payment_state'              => $normalized['state'],
				'payment_provider'           => $normalized['provider'],
				'payment_sync_status'        => 'synced',
				'payment_last_error'         => null,
				'payment_last_reconciled_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return self::storage_error();
		}

		OrderProjection::sync( (string) $row['public_id'] );
		$fresh = self::row( (string) $row['public_id'] );

		return null === $fresh ? self::storage_error() : self::public_response( $fresh );
	}

	/**
	 * Normaliza la forma pública 0.3.0 de una solicitud de pago.
	 *
	 * @param array<string, mixed> $request Solicitud.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function normalize_payment_request( array $request ): array|WP_Error {
		$reference = $request['external_reference'] ?? null;
		$provider  = $request['provider'] ?? null;

		if (
			! is_array( $reference ) ||
			! is_int( $request['id'] ?? null ) || 1 > $request['id'] ||
			! is_string( $reference['type'] ?? null ) ||
			! is_string( $reference['id'] ?? null ) ||
			! is_int( $request['amount_minor'] ?? null ) || 1 > $request['amount_minor'] ||
			! is_string( $request['currency'] ?? null ) || 1 !== preg_match( '/^[A-Z]{3}$/', $request['currency'] ) ||
			! is_string( $request['state'] ?? null ) || ! in_array( $request['state'], PaymentRequestState::all(), true ) ||
			! is_int( $request['revision'] ?? null ) || 1 > $request['revision'] ||
			( null !== $provider && ! is_string( $provider ) )
		) {
			return self::invalid();
		}

		$external_type = sanitize_key( $reference['type'] );
		$external_id   = sanitize_text_field( $reference['id'] );

		if ( $reference['type'] !== $external_type || $reference['id'] !== $external_id ) {
			return self::invalid();
		}

		return array(
			'id'            => $request['id'],
			'external_type' => $external_type,
			'external_id'   => $external_id,
			'amount_minor'  => $request['amount_minor'],
			'currency'      => $request['currency'],
			'state'         => $request['state'],
			'revision'      => $request['revision'],
			'provider'      => null === $provider ? null : substr( sanitize_key( $provider ), 0, 32 ),
		);
	}

	/**
	 * Comprueba referencia, monto, moneda e identidad de solicitud congelados.
	 *
	 * @param array<string, mixed> $request Solicitud normalizada.
	 * @param array<string, mixed> $row     Pedido bloqueado.
	 * @return bool
	 */
	private static function payment_matches_order( array $request, array $row ): bool {
		return 'vicu_order' === $request['external_type'] &&
			(string) $row['public_id'] === $request['external_id'] &&
			(int) $row['total_minor'] === $request['amount_minor'] &&
			(string) $row['currency'] === $request['currency'] &&
			( null === $row['payment_request_id'] || '' === $row['payment_request_id'] || (int) $row['payment_request_id'] === $request['id'] );
	}

	/**
	 * Traduce un estado de pago a cero, uno o dos arcos de pedido.
	 *
	 * @param string $order_status Estado del pedido.
	 * @param string $payment_state Estado de pagos.
	 * @return string[]|WP_Error
	 */
	private static function payment_targets( string $order_status, string $payment_state ): array|WP_Error {
		if ( PaymentRequestState::PENDING === $payment_state ) {
			return 'pendiente_pago' === $order_status ? array() : self::payment_attention();
		}

		if ( PaymentRequestState::PROOF_UPLOADED === $payment_state ) {
			return 'pendiente_pago' === $order_status ? array( 'pago_en_revision' ) : ( 'pago_en_revision' === $order_status ? array() : self::payment_attention() );
		}

		if ( PaymentRequestState::CONFIRMED === $payment_state ) {
			if ( 'pendiente_pago' === $order_status ) {
				return array( 'pago_en_revision', 'confirmado' );
			}

			return 'pago_en_revision' === $order_status ? array( 'confirmado' ) : ( in_array( $order_status, array( 'confirmado', 'en_preparacion', 'listo', 'en_reparto', 'completado' ), true ) ? array() : self::payment_attention() );
		}

		if ( PaymentRequestState::REJECTED === $payment_state ) {
			return 'pago_en_revision' === $order_status ? array( 'pendiente_pago' ) : ( 'pendiente_pago' === $order_status ? array() : self::payment_attention() );
		}

		if ( PaymentRequestState::EXPIRED === $payment_state ) {
			return in_array( $order_status, array( 'pendiente_pago', 'pago_en_revision' ), true ) ? array( 'expirado' ) : ( 'expirado' === $order_status ? array() : self::payment_attention() );
		}

		return self::payment_attention();
	}

	/**
	 * Construye la vista mínima usada por el adaptador de pagos.
	 *
	 * @param array<string, mixed> $row Pedido.
	 * @return array<string, mixed>
	 */
	private static function payment_record_from_row( array $row ): array {
		return array(
			'internal_id'         => (int) $row['id'],
			'public_id'           => (string) $row['public_id'],
			'status'              => (string) $row['status'],
			'total_minor'         => (int) $row['total_minor'],
			'currency'            => (string) $row['currency'],
			'payment_expires_at'  => mysql_to_rfc3339( (string) $row['payment_expires_at'] ),
			'payment_request_id'  => null === $row['payment_request_id'] ? null : (int) $row['payment_request_id'],
			'payment_revision'    => (int) $row['payment_revision'],
			'payment_state'       => null === ( $row['payment_state'] ?? null ) ? null : (string) $row['payment_state'],
			'payment_sync_status' => (string) $row['payment_sync_status'],
		);
	}

	/**
	 * Persiste un alerta segura sobre la fila bloqueada.
	 *
	 * @param int    $order_id   ID interno.
	 * @param string $error_code Código.
	 * @return bool
	 */
	private static function update_payment_error_row( int $order_id, string $error_code ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			Schema::orders_table_name(),
			array(
				'payment_sync_status'        => 'error',
				'payment_last_error'         => $error_code,
				'payment_last_reconciled_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $order_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Error que exige intervención sin inventar un arco.
	 *
	 * @return WP_Error
	 */
	private static function payment_attention(): WP_Error {
		return new WP_Error( 'vicu_restaurante_payment_attention', __( 'El pago requiere revisión administrativa.', 'vicunav-restaurante' ), array( 'status' => 409 ) );
	}

	/**
	 * Normaliza los únicos datos privados aceptados en checkout.
	 *
	 * @param array<string, mixed> $input Entrada.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function normalize_checkout( array $input ): array|WP_Error {
		$revision = self::integer( $input['expected_revision'] ?? null, 1, PHP_INT_MAX );
		$customer = $input['customer'] ?? null;

		if ( null === $revision || ! is_array( $customer ) ) {
			return self::invalid();
		}

		$name         = self::text( $customer['name'] ?? null, 1, 100 );
		$phone        = self::text( $customer['phone'] ?? null, 3, 32 );
		$email_input  = self::text( $customer['email'] ?? '', 0, 191 );
		$email        = '' === $email_input ? null : sanitize_email( $email_input );
		$address      = self::text( $input['delivery_address'] ?? '', 0, 500 );
		$instructions = self::text( $input['delivery_instructions'] ?? '', 0, 500 );
		$note         = self::text( $input['customer_note'] ?? '', 0, 500 );

		if ( null === $name || null === $phone || null === $email_input || ( null !== $email && $email !== $email_input ) || null === $address || null === $instructions || null === $note ) {
			return self::invalid();
		}

		return array(
			'expected_revision'     => $revision,
			'customer_name'         => $name,
			'customer_email'        => $email,
			'customer_phone'        => $phone,
			'delivery_address'      => '' === $address ? null : $address,
			'delivery_instructions' => '' === $instructions ? null : $instructions,
			'customer_note'         => '' === $note ? null : $note,
		);
	}

	/**
	 * Comprueba dirección según fulfillment ya autoritativo.
	 *
	 * @param array<string, mixed> $data Checkout.
	 * @param array<string, mixed> $cart Carrito.
	 * @return bool
	 */
	private static function checkout_matches_cart( array $data, array $cart ): bool {
		return 'delivery' === $cart['fulfillment']
			? null !== $data['delivery_address']
			: null === $data['delivery_address'] && null === $data['delivery_instructions'];
	}

	/**
	 * Inserta un snapshot inmutable.
	 *
	 * @param int                  $order_id ID interno.
	 * @param array<string, mixed> $item     Línea revalidada.
	 * @param string               $now      UTC.
	 * @return bool
	 */
	private static function insert_item( int $order_id, array $item, string $now ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			Schema::order_items_table_name(),
			array(
				'public_id'        => wp_generate_uuid4(),
				'order_id'         => $order_id,
				'line_public_id'   => $item['line_id'],
				'type'             => $item['type'],
				'quantity'         => $item['quantity'],
				'selection_json'   => wp_json_encode( $item['selection'] ),
				'snapshot_json'    => wp_json_encode( $item['snapshot'] ),
				'unit_price_minor' => $item['unit_price_minor'],
				'line_total_minor' => $item['line_total_minor'],
				'created_at'       => $now,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Inserta un evento append-only para una revisión única.
	 *
	 * @param int                  $order_id   ID interno.
	 * @param string|null          $from       Origen.
	 * @param string               $to         Destino.
	 * @param string               $actor_type Actor.
	 * @param int|null             $actor_id   Usuario.
	 * @param string|null          $reason     Motivo.
	 * @param array<string, mixed> $metadata   Metadatos.
	 * @param int                  $revision   Revisión resultante.
	 * @return bool
	 */
	private static function insert_event( int $order_id, ?string $from, string $to, string $actor_type, ?int $actor_id, ?string $reason, array $metadata, int $revision ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			Schema::order_events_table_name(),
			array(
				'public_id'     => wp_generate_uuid4(),
				'order_id'      => $order_id,
				'from_status'   => $from,
				'to_status'     => $to,
				'actor_type'    => $actor_type,
				'actor_id'      => $actor_id,
				'reason'        => $reason,
				'metadata_json' => wp_json_encode( $metadata ),
				'revision'      => $revision,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Lee fila por UUID.
	 *
	 * @param string $public_id UUID.
	 * @return array<string, mixed>|null
	 */
	private static function row( string $public_id ): ?array {
		global $wpdb;

		if ( ! wp_is_uuid( $public_id, 4 ) ) {
			return null;
		}

		$table = Schema::orders_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s", $public_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Proyecta respuesta sin datos de contacto ni secretos.
	 *
	 * @param array<string, mixed> $row Fila autorizada.
	 * @return array<string, mixed>
	 */
	private static function public_response( array $row ): array {
		global $wpdb;

		$items_table = Schema::order_items_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$items_table} WHERE order_id = %d ORDER BY id ASC", $row['id'] ), ARRAY_A );
		$items = array_map(
			static function ( array $item ): array {
				return array(
					'public_id'        => (string) $item['public_id'],
					'type'             => (string) $item['type'],
					'quantity'         => (int) $item['quantity'],
					'selection'        => self::decode( (string) $item['selection_json'] ),
					'snapshot'         => self::decode( (string) $item['snapshot_json'] ),
					'unit_price_minor' => (int) $item['unit_price_minor'],
					'line_total_minor' => (int) $item['line_total_minor'],
				);
			},
			$rows
		);

		$manual = ManualPaymentProvider::get_configuration();

		return array(
			'public_id'           => (string) $row['public_id'],
			'order_number'        => (string) $row['order_number'],
			'status'              => (string) $row['status'],
			'revision'            => (int) $row['revision'],
			'fulfillment'         => (string) $row['fulfillment'],
			'currency'            => (string) $row['currency'],
			'items'               => $items,
			'totals'              => self::decode( (string) $row['totals_json'] ),
			'payment_expires_at'  => mysql_to_rfc3339( (string) $row['payment_expires_at'] ),
			'payment_sync_status' => (string) $row['payment_sync_status'],
			'payment'             => array(
				'provider'         => 'manual',
				'provider_enabled' => true === ( $manual['enabled'] ?? false ),
				'instructions'     => RestaurantSettings::manual_payment_instructions(),
				'state'            => null === ( $row['payment_state'] ?? null ) ? null : (string) $row['payment_state'],
				'revision'         => (int) $row['payment_revision'],
			),
			'created_at'          => mysql_to_rfc3339( (string) $row['created_at'] ),
			'updated_at'          => mysql_to_rfc3339( (string) $row['updated_at'] ),
		);
	}

	/**
	 * Añade el token solo al resultado anónimo de checkout/replay.
	 *
	 * @param array<string, mixed>      $response        Respuesta.
	 * @param array<string, mixed>      $row             Pedido.
	 * @param array<string, int|string> $identity        Identidad.
	 * @param string                    $idempotency_key Clave conocida.
	 * @return array<string, mixed>
	 */
	private static function with_access_token( array $response, array $row, array $identity, string $idempotency_key ): array {
		if ( 0 === (int) $identity['user_id'] ) {
			$response['access_token'] = OrderIdempotency::access_token( (string) $row['public_id'], $idempotency_key );
		}

		return $response;
	}

	/**
	 * Comprueba ownership sin distinguir pedido ausente de token inválido.
	 *
	 * @param array<string, mixed>      $row      Pedido.
	 * @param array<string, int|string> $identity Identidad.
	 * @param string                    $token    Token.
	 * @return bool
	 */
	private static function owns( array $row, array $identity, string $token ): bool {
		if ( null !== $row['user_id'] ) {
			return 0 < (int) $identity['user_id'] && (int) $row['user_id'] === (int) $identity['user_id'];
		}

		return 64 === strlen( $token ) && hash_equals( (string) $row['access_token_hash'], OrderIdempotency::access_token_hash( $token ) );
	}

	/**
	 * Lee eventos sin IDs internos.
	 *
	 * @param int $order_id ID interno autorizado.
	 * @return array<int, array<string, mixed>>
	 */
	private static function events( int $order_id ): array {
		global $wpdb;

		$table = Schema::order_events_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY revision ASC", $order_id ), ARRAY_A );

		return array_map(
			static function ( array $event ): array {
				return array(
					'public_id'  => (string) $event['public_id'],
					'from'       => null === $event['from_status'] ? null : (string) $event['from_status'],
					'to'         => (string) $event['to_status'],
					'actor_type' => (string) $event['actor_type'],
					'actor_id'   => null === $event['actor_id'] ? null : (int) $event['actor_id'],
					'reason'     => null === $event['reason'] ? null : (string) $event['reason'],
					'metadata'   => self::decode( (string) $event['metadata_json'] ),
					'revision'   => (int) $event['revision'],
					'created_at' => mysql_to_rfc3339( (string) $event['created_at'] ),
				);
			},
			$rows
		);
	}

	/**
	 * Conserva solo metadatos escalares no sensibles y acotados.
	 *
	 * @param array<string, mixed> $metadata Entrada.
	 * @return array<string, int|string|bool>
	 */
	private static function safe_metadata( array $metadata ): array {
		$result = array();

		foreach ( array_slice( $metadata, 0, 20, true ) as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' !== $key && is_scalar( $value ) ) {
				$result[ $key ] = is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 191 ) : $value;
			}
		}

		ksort( $result, SORT_STRING );

		return $result;
	}

	/**
	 * Ordena recursivamente mapas para una huella estable.
	 *
	 * @param mixed $value Valor.
	 * @return mixed
	 */
	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}

		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}

	/**
	 * Decodifica JSON persistido.
	 *
	 * @param string $json JSON.
	 * @return array<string, mixed>
	 */
	private static function decode( string $json ): array {
		$value = json_decode( $json, true );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Sanitiza texto y longitud.
	 *
	 * @param mixed $value Valor.
	 * @param int   $min   Mínimo.
	 * @param int   $max   Máximo.
	 * @return string|null
	 */
	private static function text( mixed $value, int $min, int $max ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value  = trim( sanitize_textarea_field( (string) $value ) );
		$length = strlen( $value );

		return $min <= $length && $max >= $length ? $value : null;
	}

	/**
	 * Valida entero exacto.
	 *
	 * @param mixed $value Valor.
	 * @param int   $min   Mínimo.
	 * @param int   $max   Máximo.
	 * @return int|null
	 */
	private static function integer( mixed $value, int $min, int $max ): ?int {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) || trim( (string) $value ) !== (string) (int) $value ) {
			return null;
		}

		$value = (int) $value;

		return $min <= $value && $max >= $value ? $value : null;
	}

	/**
	 * Error de request.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_request', __( 'Los datos de checkout no son válidos.', 'vicunav-restaurante' ), array( 'status' => 400 ) );
	}

	/**
	 * Error de transición.
	 *
	 * @return WP_Error
	 */
	private static function invalid_transition(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_transition', __( 'La transición del pedido no está permitida.', 'vicunav-restaurante' ), array( 'status' => 409 ) );
	}

	/**
	 * Error no enumerable.
	 *
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'vicu_restaurante_not_found', __( 'No se encontró el pedido solicitado.', 'vicunav-restaurante' ), array( 'status' => 404 ) );
	}

	/**
	 * Error de persistencia sin datos SQL.
	 *
	 * @return WP_Error
	 */
	private static function storage_error(): WP_Error {
		return new WP_Error( 'vicu_restaurante_storage_error', __( 'No se pudo guardar el pedido.', 'vicunav-restaurante' ), array( 'status' => 500 ) );
	}
}
