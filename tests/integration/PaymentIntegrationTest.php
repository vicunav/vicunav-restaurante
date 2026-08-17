<?php
/**
 * Pruebas de integración pública con `vicunav-pagos`.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Pagos\ManualPaymentProvider;
use Vicu\Pagos\PaymentRequests;
use Vicu\Pagos\PaymentRequestState;
use Vicu\Restaurante\Cart\CartService;
use Vicu\Restaurante\Cart\CartSessionService;
use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Commerce\PricingRevision;
use Vicu\Restaurante\Installer;
use Vicu\Restaurante\Menu\CatalogRevision;
use Vicu\Restaurante\Menu\MenuCategory;
use Vicu\Restaurante\Menu\MenuItemPostType;
use Vicu\Restaurante\Menu\MenuMeta;
use Vicu\Restaurante\Order\OrderPostType;
use Vicu\Restaurante\Order\OrderService;
use Vicu\Restaurante\Order\PaymentEvidenceService;
use Vicu\Restaurante\Order\PaymentIntegration;
use Vicu\Restaurante\Rest\OrderRoutes;
use Vicu\Restaurante\Schema;
use Vicu\Restaurante\Settings\RestaurantSettings;

/**
 * Verifica idempotencia, eventos versionados, reconciliación y privacidad.
 */
final class PaymentIntegrationTest extends WP_UnitTestCase {
	/**
	 * Instala y aísla dominio y doble público de pagos.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( Installer::install() );
		MenuCategory::register();
		MenuMeta::register_meta();
		( new OrderPostType() )->register();
		$this->truncate_domain_tables();
		PaymentRequests::reset();
		ManualPaymentProvider::reset();
		update_option( CatalogRevision::OPTION_NAME, '1', false );
		CatalogRevision::reset_request();
		update_option( AvailabilityRevision::OPTION_NAME, '1', false );
		AvailabilityRevision::clear_cache();
		update_option( PricingRevision::OPTION_NAME, '1', false );
		PricingRevision::clear_cache();
		update_option(
			RestaurantSettings::OPTION_NAME,
			array(
				'currency'                    => 'USD',
				'tax_rate_bps'                => 800,
				'tip_rates_bps'               => array( 0, 1000, 1500, 2000 ),
				'cart_lifetime_hours'         => 72,
				'payment_lifetime_minutes'    => 30,
				'manual_payment_instructions' => 'Indica la referencia de la operación.',
			),
			false
		);
		wp_set_current_user( 0 );
		$_COOKIE = array();
		wp_cache_flush();
	}

	/**
	 * Limpia identidad y opciones propias.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$_COOKIE = array();
		wp_set_current_user( 0 );
		delete_option( RestaurantSettings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Checkout y replay recuperan una única solicitud con valores congelados.
	 */
	public function test_checkout_creates_one_idempotent_payment_request(): void {
		$scenario = $this->create_order( 'payment-checkout-0001' );
		$order    = $scenario['order'];
		$record   = OrderService::payment_record( $order['public_id'] );

		$this->assertSame( 100, $record['payment_request_id'] );
		$this->assertSame( 1, $record['payment_revision'] );
		$this->assertSame( PaymentRequestState::PENDING, $record['payment_state'] );
		$this->assertSame( 'synced', $record['payment_sync_status'] );
		$this->assertSame( 'Indica la referencia de la operación.', $order['payment']['instructions'] );

		$replay = OrderService::checkout( $scenario['identity'], 'payment-checkout-0001', $scenario['input'] );
		$this->assertNotWPError( $replay );
		$this->assertSame( $order['public_id'], $replay['public_id'] );
		$this->assertSame( 2, PaymentRequests::$create_calls );
		$this->assertSame( 100, OrderService::payment_record( $order['public_id'] )['payment_request_id'] );
	}

	/**
	 * Evidencia se guarda una vez, no se expone y lleva el pedido a revisión.
	 */
	public function test_manual_evidence_is_private_and_idempotent(): void {
		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$scenario = $this->create_order( 'evidence-checkout-01' );
		$key      = 'evidence-key-0000001';
		$first    = PaymentEvidenceService::submit( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'], $key, 'REF-PRIVADA-42' );
		$retry    = PaymentEvidenceService::submit( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'], $key, 'REF-PRIVADA-42' );

		$this->assertNotWPError( $first );
		$this->assertNotWPError( $retry );
		$this->assertSame( $first['evidence']['public_id'], $retry['evidence']['public_id'] );
		$this->assertArrayNotHasKey( 'reference', $first['evidence'] );
		$this->assertSame( 'pago_en_revision', $retry['order']['status'] );
		$this->assertSame( 2, $retry['order']['revision'] );
		$this->assertSame( 1, $this->count_rows( Schema::payment_evidence_table_name() ) );
		$this->assertSame( 2, $this->count_rows( Schema::order_events_table_name() ) );

		$collision = PaymentEvidenceService::submit( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'], $key, 'OTRA-REFERENCIA' );
		$this->assertWPError( $collision );
		$this->assertSame( 'vicu_restaurante_idempotency_collision', $collision->get_error_code() );
		$private = PaymentEvidenceService::admin_for_order( OrderService::payment_record( $scenario['order']['public_id'] )['internal_id'] );
		$this->assertSame( 'REF-PRIVADA-42', $private[0]['reference_text'] );
	}

	/**
	 * Confirmación y duplicados respetan revisión y eventos únicos.
	 */
	public function test_confirmation_event_is_monotonic_and_duplicate_safe(): void {
		$scenario  = $this->order_with_evidence( 'confirmation-checkout-1', 'confirmation-evidence-1' );
		$record    = OrderService::payment_record( $scenario['order']['public_id'] );
		$confirmed = PaymentRequests::transition( $record['payment_request_id'], PaymentRequestState::CONFIRMED, 2 );

		$this->assertNotWPError( $confirmed );
		$order = OrderService::get( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'] );
		$this->assertSame( 'confirmado', $order['status'] );
		$this->assertSame( 3, $order['revision'] );
		$this->assertSame( 3, $order['payment']['revision'] );

		PaymentIntegration::handle_event( $this->payload( 'confirmado', $confirmed ) );
		$duplicate = OrderService::get( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'] );
		$this->assertSame( 3, $duplicate['revision'] );
		$this->assertSame( 3, $this->count_rows( Schema::order_events_table_name() ) );
	}

	/**
	 * Rechazo vuelve a pendiente y una evidencia nueva reabre revisión.
	 */
	public function test_rejection_and_new_evidence_follow_valid_arcs(): void {
		$scenario = $this->order_with_evidence( 'rejection-checkout-01', 'rejection-evidence-01' );
		$record   = OrderService::payment_record( $scenario['order']['public_id'] );
		$rejected = PaymentRequests::transition( $record['payment_request_id'], PaymentRequestState::REJECTED, 2 );
		$this->assertNotWPError( $rejected );
		$this->assertSame( 'pendiente_pago', OrderService::get( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'] )['status'] );

		$new = PaymentEvidenceService::submit( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'], 'rejection-evidence-02', 'REF-NUEVA' );
		$this->assertNotWPError( $new );
		$this->assertSame( 'pago_en_revision', $new['order']['status'] );
		$this->assertSame( 4, $new['order']['revision'] );
		$this->assertSame( 2, $this->count_rows( Schema::payment_evidence_table_name() ) );
	}

	/**
	 * Expiración pendiente es terminal y no duplica eventos.
	 */
	public function test_expiration_is_idempotent_for_order_observation(): void {
		$scenario = $this->create_order( 'expiration-checkout-1' );
		$record   = OrderService::payment_record( $scenario['order']['public_id'] );
		$expired  = PaymentRequests::transition( $record['payment_request_id'], PaymentRequestState::EXPIRED, 1 );
		$this->assertNotWPError( $expired );
		$this->assertSame( 'expirado', OrderService::get( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'] )['status'] );

		PaymentIntegration::handle_event( $this->payload( 'expirado', $expired ) );
		$this->assertSame( 2, $this->count_rows( Schema::order_events_table_name() ) );
	}

	/**
	 * Reconciliación recupera una confirmación cuyo hook no llegó.
	 */
	public function test_reconciliation_recovers_lost_hook(): void {
		$scenario                        = $this->order_with_evidence( 'lost-hook-checkout-1', 'lost-hook-evidence-1' );
		$record                          = OrderService::payment_record( $scenario['order']['public_id'] );
		PaymentRequests::$publish_events = false;
		$confirmed                       = PaymentRequests::transition( $record['payment_request_id'], PaymentRequestState::CONFIRMED, 2 );
		$this->assertNotWPError( $confirmed );
		$this->assertSame( 'pago_en_revision', OrderService::get( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'] )['status'] );

		$result = PaymentIntegration::reconcile_order( $scenario['order']['public_id'] );
		$this->assertNotWPError( $result );
		$this->assertSame( 'confirmado', OrderService::get( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'] )['status'] );
		$this->assertGreaterThan( 0, PaymentRequests::$get_calls );
	}

	/**
	 * Monto divergente crea alerta y nunca transiciona el pedido.
	 */
	public function test_payment_mismatch_creates_safe_alert(): void {
		$scenario                = $this->create_order( 'mismatch-checkout-01' );
		$record                  = OrderService::payment_record( $scenario['order']['public_id'] );
		$request                 = PaymentRequests::get( $record['payment_request_id'] );
		$request['amount_minor'] = $request['amount_minor'] + 1;
		$request['revision']     = 2;
		PaymentRequests::replace( $request );

		$result = PaymentIntegration::reconcile_order( $scenario['order']['public_id'] );
		$this->assertWPError( $result );
		$this->assertSame( 'vicu_restaurante_payment_mismatch', $result->get_error_code() );
		$detail = OrderService::admin_detail( $scenario['order']['public_id'] );
		$this->assertSame( 'pendiente_pago', $detail['status'] );
		$this->assertSame( 'error', $detail['payment_sync_status'] );
		$this->assertSame( 'vicu_restaurante_payment_mismatch', $detail['payment_last_error'] );
		$this->assertSame( 1, $this->count_rows( Schema::order_events_table_name() ) );
	}

	/**
	 * Payload ajeno o con versión desconocida no toca pedidos.
	 */
	public function test_unknown_or_foreign_event_is_ignored(): void {
		$scenario                   = $this->create_order( 'foreign-event-checkout-1' );
		$record                     = OrderService::payment_record( $scenario['order']['public_id'] );
		$request                    = PaymentRequests::get( $record['payment_request_id'] );
		$request['state']           = PaymentRequestState::PROOF_UPLOADED;
		$request['revision']        = 2;
		$payload                    = $this->payload( 'comprobante_recibido', $request );
		$payload['payload_version'] = '2.0.0';
		PaymentIntegration::handle_event( $payload );

		$payload['payload_version']                       = '1.0.0';
		$payload['request']['external_reference']['type'] = 'vicu_booking';
		PaymentIntegration::handle_event( $payload );
		$order = OrderService::get( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'] );
		$this->assertSame( 'pendiente_pago', $order['status'] );
		$this->assertSame( 1, $order['revision'] );
		$this->assertSame( 1, $this->count_rows( Schema::order_events_table_name() ) );
	}

	/**
	 * Una entrega fallida se reanuda con la misma evidencia al habilitar proveedor.
	 */
	public function test_disabled_provider_leaves_recoverable_evidence(): void {
		$scenario = $this->create_order( 'disabled-checkout-1' );
		$failed   = PaymentEvidenceService::submit( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'], 'disabled-evidence-01', 'REF-PENDIENTE' );
		$this->assertWPError( $failed );
		$this->assertSame( 'vicu_pagos_manual_provider_disabled', $failed->get_error_code() );
		$this->assertSame( 1, $this->count_rows( Schema::payment_evidence_table_name() ) );

		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$recovered = PaymentEvidenceService::submit( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'], 'disabled-evidence-01', 'REF-PENDIENTE' );
		$this->assertNotWPError( $recovered );
		$this->assertSame( 'submitted', $recovered['evidence']['status'] );
		$this->assertSame( 'pago_en_revision', $recovered['order']['status'] );
		$this->assertSame( 1, $this->count_rows( Schema::payment_evidence_table_name() ) );
	}

	/**
	 * REST exige token, no publica texto y valida su schema.
	 */
	public function test_rest_payment_evidence_is_private(): void {
		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$scenario = $this->create_order( 'rest-evidence-checkout-1' );
		$route    = '/vicu/v1/restaurante/orders/' . $scenario['order']['public_id'] . '/payment-evidence';
		$missing  = $this->dispatch( 'POST', $route, array( 'reference' => 'REF-REST' ), array( 'Idempotency-Key' => 'rest-evidence-key-01' ) );
		$this->assertSame( 401, $missing->get_status() );

		$response = $this->dispatch(
			'POST',
			$route,
			array( 'reference' => 'REF-REST' ),
			array(
				'Idempotency-Key'    => 'rest-evidence-key-01',
				'X-Vicu-Order-Token' => $scenario['order']['access_token'],
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( rest_validate_value_from_schema( $response->get_data(), OrderRoutes::evidence_schema() ) );
		$this->assertStringNotContainsString( 'REF-REST', wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] );
	}

	/**
	 * El job se agenda una vez y se retira sin borrar pedidos.
	 */
	public function test_reconciliation_schedule_is_idempotent(): void {
		PaymentIntegration::unschedule();
		$this->assertFalse( wp_next_scheduled( PaymentIntegration::RECONCILIATION_HOOK ) );
		PaymentIntegration::schedule();
		$scheduled = wp_next_scheduled( PaymentIntegration::RECONCILIATION_HOOK );
		$this->assertIsInt( $scheduled );
		PaymentIntegration::schedule();
		$this->assertSame( $scheduled, wp_next_scheduled( PaymentIntegration::RECONCILIATION_HOOK ) );
		PaymentIntegration::unschedule();
		$this->assertFalse( wp_next_scheduled( PaymentIntegration::RECONCILIATION_HOOK ) );
	}

	/**
	 * Crea un pedido mínimo con solicitud sincronizada.
	 *
	 * @param string $key Clave idempotente.
	 * @return array{order: array<string, mixed>, identity: array<string, int|string>, input: array<string, mixed>}
	 */
	private function create_order( string $key ): array {
		$menu     = $this->seed_menu_item();
		$identity = CartSessionService::create_anonymous();
		$this->assertNotWPError( $identity );
		$cart = CartService::create( $identity );
		$this->assertNotWPError( $cart );
		$cart = CartService::add_item(
			$identity,
			$cart['revision'],
			array(
				'type'                   => 'menu',
				'menu_item_id'           => $menu,
				'quantity'               => 1,
				'options'                => array(),
				'removed_ingredient_ids' => array(),
				'note'                   => '',
			)
		);
		$this->assertNotWPError( $cart );
		$input = array(
			'expected_revision' => $cart['revision'],
			'customer'          => array(
				'name'  => 'Cliente Pago',
				'email' => 'pago@example.com',
				'phone' => '+58 412 1111111',
			),
		);
		$order = OrderService::checkout( $identity, $key, $input );
		$this->assertNotWPError( $order );

		return compact( 'order', 'identity', 'input' );
	}

	/**
	 * Crea pedido y entrega evidencia manual.
	 *
	 * @param string $checkout_key Clave de checkout.
	 * @param string $evidence_key Clave de evidencia.
	 * @return array{order: array<string, mixed>, identity: array<string, int|string>, input: array<string, mixed>}
	 */
	private function order_with_evidence( string $checkout_key, string $evidence_key ): array {
		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$scenario = $this->create_order( $checkout_key );
		$result   = PaymentEvidenceService::submit( $scenario['order']['public_id'], $this->guest_identity(), $scenario['order']['access_token'], $evidence_key, 'REF-' . $evidence_key );
		$this->assertNotWPError( $result );

		return $scenario;
	}

	/**
	 * Crea un item público mínimo.
	 *
	 * @return string UUID.
	 */
	private function seed_menu_item(): string {
		$term = wp_insert_term( 'Pagos', MenuCategory::TAXONOMY, array( 'slug' => 'pagos-' . wp_generate_password( 8, false ) ) );
		$this->assertNotWPError( $term );
		update_term_meta( $term['term_id'], MenuCategory::META_VISIBLE, true );
		update_term_meta( $term['term_id'], MenuCategory::META_ORDER, 0 );
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => MenuItemPostType::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Producto',
			)
		);
		wp_set_object_terms( $post_id, array( (int) $term['term_id'] ), MenuCategory::TAXONOMY );
		$public_id = wp_generate_uuid4();
		update_post_meta( $post_id, MenuMeta::PUBLIC_ID, $public_id );
		update_post_meta( $post_id, MenuMeta::PRICE_MINOR, 1000 );
		update_post_meta( $post_id, MenuMeta::CURRENCY, 'USD' );
		update_post_meta( $post_id, MenuMeta::AVAILABLE, true );
		update_post_meta( $post_id, MenuMeta::CALORIES_KCAL, 0 );
		update_post_meta( $post_id, MenuMeta::ALLERGENS, array() );
		update_post_meta( $post_id, MenuMeta::DIETARY_TAGS, array() );
		wp_cache_flush();

		return $public_id;
	}

	/**
	 * Identidad invitada para token de pedido.
	 *
	 * @return array<string, int|string>
	 */
	private function guest_identity(): array {
		return array(
			'type'       => 'guest',
			'key'        => 'guest',
			'session_id' => 0,
			'user_id'    => 0,
			'csrf_token' => '',
			'expires_at' => '',
		);
	}

	/**
	 * Construye un payload versionado repetible.
	 *
	 * @param string               $event   Evento.
	 * @param array<string, mixed> $request Solicitud.
	 * @return array<string, mixed>
	 */
	private function payload( string $event, array $request ): array {
		return array(
			'payload_version' => '1.0.0',
			'event'           => $event,
			'occurred_at'     => $request['updated_at'],
			'transition'      => array(),
			'request'         => $request,
		);
	}

	/**
	 * Cuenta filas de una tabla fija.
	 *
	 * @param string $table Tabla.
	 * @return int
	 */
	private function count_rows( string $table ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Despacha una solicitud REST interna.
	 *
	 * @param string                $method  Método.
	 * @param string                $route   Ruta.
	 * @param array<string, mixed>  $params  Body.
	 * @param array<string, string> $headers Headers.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, string $route, array $params = array(), array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_body_params( $params );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Vacía autoridades en orden seguro.
	 *
	 * @return void
	 */
	private function truncate_domain_tables(): void {
		global $wpdb;

		$tables = array(
			Schema::payment_evidence_table_name(),
			Schema::order_events_table_name(),
			Schema::order_items_table_name(),
			Schema::orders_table_name(),
			Schema::idempotency_table_name(),
			Schema::cart_items_table_name(),
			Schema::carts_table_name(),
			Schema::cart_sessions_table_name(),
			Schema::discount_codes_table_name(),
			Schema::delivery_zones_table_name(),
			Schema::menu_ingredients_table_name(),
			Schema::ingredients_table_name(),
			Schema::pizza_options_table_name(),
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertNotFalse( $wpdb->query( "TRUNCATE TABLE {$table}" ) );
		}
	}
}
