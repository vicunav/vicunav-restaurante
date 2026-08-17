<?php
/**
 * Pruebas transaccionales de checkout y pedidos.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Cart\CartService;
use Vicu\Restaurante\Cart\CartSessionService;
use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Commerce\DeliveryZoneService;
use Vicu\Restaurante\Commerce\DiscountService;
use Vicu\Restaurante\Commerce\PricingRevision;
use Vicu\Restaurante\Installer;
use Vicu\Restaurante\Menu\CatalogRevision;
use Vicu\Restaurante\Menu\MenuCategory;
use Vicu\Restaurante\Menu\MenuItemPostType;
use Vicu\Restaurante\Menu\MenuMeta;
use Vicu\Restaurante\Order\OrderPostType;
use Vicu\Restaurante\Order\OrderProjection;
use Vicu\Restaurante\Order\OrderService;
use Vicu\Restaurante\Rest\OrderRoutes;
use Vicu\Restaurante\Schema;
use Vicu\Restaurante\Settings\RestaurantSettings;
use Vicu\Pagos\ManualPaymentProvider;
use Vicu\Pagos\PaymentRequests;

/**
 * Verifica atomicidad, idempotencia, snapshots, ownership, estados y proyección.
 */
final class OrderTest extends WP_UnitTestCase {
	/**
	 * Instala y aísla las autoridades del dominio.
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
				'manual_payment_instructions' => '',
			),
			false
		);
		wp_set_current_user( 0 );
		$_COOKIE = array();
		wp_cache_flush();
	}

	/**
	 * Limpia identidad y settings.
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
	 * Retry idéntico devuelve el mismo pedido y otro payload colisiona.
	 *
	 * @return void
	 */
	public function test_checkout_is_atomic_and_idempotent(): void {
		global $wpdb;

		$menu     = $this->seed_menu_item( 'Pasta', 2000 );
		$identity = $this->anonymous_identity();
		$cart     = $this->cart_with_item( $identity, $menu['public_id'] );
		$input    = $this->checkout_input( $cart['revision'] );
		$key      = 'checkout-key-00000001';

		$order = OrderService::checkout( $identity, $key, $input );
		$this->assertNotWPError( $order );
		$this->assertSame( 'pendiente_pago', $order['status'] );
		$this->assertSame( 1, $order['revision'] );
		$this->assertSame( 2160, $order['totals']['total'] );
		$this->assertSame( 64, strlen( $order['access_token'] ) );
		$this->assertSame( 'synced', $order['payment_sync_status'] );
		$this->assertSame( 'pendiente', $order['payment']['state'] );

		$replay = OrderService::checkout( $identity, $key, $input );
		$this->assertNotWPError( $replay );
		$this->assertSame( $order['public_id'], $replay['public_id'] );
		$this->assertSame( $order['access_token'], $replay['access_token'] );
		$this->assertSame( $order['payment_expires_at'], $replay['payment_expires_at'] );

		$changed                     = $input;
		$changed['customer']['name'] = 'Otra persona';
		$collision                   = OrderService::checkout( $identity, $key, $changed );
		$this->assertWPError( $collision );
		$this->assertSame( 'vicu_restaurante_idempotency_collision', $collision->get_error_code() );

		$this->assertSame( 1, $this->count_rows( Schema::orders_table_name() ) );
		$this->assertSame( 1, $this->count_rows( Schema::order_items_table_name() ) );
		$this->assertSame( 1, $this->count_rows( Schema::order_events_table_name() ) );
		$this->assertSame( 1, $this->count_rows( Schema::idempotency_table_name() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- El nombre de tabla es interno y no admite placeholder.
		$record = $wpdb->get_row( 'SELECT * FROM ' . Schema::idempotency_table_name() . ' LIMIT 1', ARRAY_A );
		$this->assertNotSame( $key, $record['key_hash'] );
		$this->assertStringNotContainsString( 'access_token', (string) $record['response_json'] );
		$this->assertStringNotContainsString( $key, (string) $record['response_json'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- El nombre de tabla es interno y no admite placeholder.
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::carts_table_name() . ' WHERE public_id = %s', $cart['public_id'] ) );
		$this->assertSame( 'converted', $status );
	}

	/**
	 * Un descuento limitado se consume una vez dentro de la misma transacción.
	 *
	 * @return void
	 */
	public function test_checkout_consumes_limited_discount_once(): void {
		$menu     = $this->seed_menu_item( 'Cena', 2000 );
		$discount = DiscountService::create( $this->discount_input( 'ULTIMO', 1000, 1 ) );
		$this->assertNotWPError( $discount );
		$first_identity  = $this->anonymous_identity();
		$second_identity = $this->anonymous_identity();
		$first_cart      = $this->cart_with_item( $first_identity, $menu['public_id'] );
		$second_cart     = $this->cart_with_item( $second_identity, $menu['public_id'] );
		$first_cart      = CartService::set_discount( $first_identity, $first_cart['revision'], 'ULTIMO' );
		$second_cart     = CartService::set_discount( $second_identity, $second_cart['revision'], 'ULTIMO' );
		$this->assertNotWPError( $first_cart );
		$this->assertNotWPError( $second_cart );

		$first = OrderService::checkout( $first_identity, 'discount-checkout-0001', $this->checkout_input( $first_cart['revision'] ) );
		$this->assertNotWPError( $first );
		$this->assertSame( 1944, $first['totals']['total'] );
		$this->assertSame( 1, DiscountService::find( $discount['public_id'] )['uses_count'] );

		$second = OrderService::checkout( $second_identity, 'discount-checkout-0002', $this->checkout_input( $second_cart['revision'] ) );
		$this->assertWPError( $second );
		$this->assertSame( 'vicu_restaurante_unavailable', $second->get_error_code() );
		$this->assertSame( 1, $this->count_rows( Schema::orders_table_name() ) );
		$this->assertSame( 'active', CartService::get( $second_identity )['status'] );
		$this->assertSame( 'ULTIMO', CartService::get( $second_identity )['discount_code'] );
	}

	/**
	 * Pedido y proyección conservan snapshots aunque cambie el catálogo.
	 *
	 * @return void
	 */
	public function test_snapshots_are_immutable_and_projection_rebuildable(): void {
		$menu     = $this->seed_menu_item( 'Original', 1250 );
		$identity = $this->anonymous_identity();
		$cart     = $this->cart_with_item( $identity, $menu['public_id'] );
		$order    = OrderService::checkout( $identity, 'snapshot-checkout-01', $this->checkout_input( $cart['revision'] ) );
		$this->assertNotWPError( $order );

		wp_update_post(
			array(
				'ID'         => $menu['post_id'],
				'post_title' => 'Cambiado',
			)
		);
		update_post_meta( $menu['post_id'], MenuMeta::PRICE_MINOR, 9999 );
		$read = OrderService::get( $order['public_id'], $this->guest_identity(), $order['access_token'] );
		$this->assertSame( 'Original', $read['items'][0]['snapshot']['name'] );
		$this->assertSame( 1250, $read['items'][0]['unit_price_minor'] );
		$this->assertSame( 1350, $read['totals']['total'] );

		$projection = $this->projection_post( $order['public_id'] );
		$this->assertGreaterThan( 0, $projection );
		wp_delete_post( $projection, true );
		$this->assertSame( 0, $this->projection_post( $order['public_id'] ) );
		$rebuilt = OrderProjection::rebuild();
		$this->assertSame( 1, $rebuilt['synced'] );
		$this->assertSame( 0, $rebuilt['failed'] );
		$this->assertGreaterThan( 0, $this->projection_post( $order['public_id'] ) );
		$this->assertSame( 'synced', OrderService::admin_detail( $order['public_id'] )['projection_status'] );
	}

	/**
	 * Token anónimo y cuenta propietaria no permiten enumeración cruzada.
	 *
	 * @return void
	 */
	public function test_order_access_requires_token_or_owner(): void {
		$menu      = $this->seed_menu_item( 'Sopa', 700 );
		$anonymous = $this->anonymous_identity();
		$cart      = $this->cart_with_item( $anonymous, $menu['public_id'] );
		$order     = OrderService::checkout( $anonymous, 'anonymous-checkout-01', $this->checkout_input( $cart['revision'] ) );
		$this->assertNotWPError( $order );
		$this->assertWPError( OrderService::get( $order['public_id'], $this->guest_identity(), str_repeat( '0', 64 ) ) );
		$owned = OrderService::get( $order['public_id'], $this->guest_identity(), $order['access_token'] );
		$this->assertNotWPError( $owned );
		$this->assertArrayNotHasKey( 'customer', $owned );
		$this->assertArrayNotHasKey( 'delivery_address', $owned );

		$user_a     = self::factory()->user->create();
		$user_b     = self::factory()->user->create();
		$identity_a = CartSessionService::authenticated( $user_a );
		$user_cart  = $this->cart_with_item( $identity_a, $menu['public_id'] );
		$user_order = OrderService::checkout( $identity_a, 'account-checkout-0001', $this->checkout_input( $user_cart['revision'] ) );
		$this->assertNotWPError( $user_order );
		$this->assertArrayNotHasKey( 'access_token', $user_order );
		$this->assertNotWPError( OrderService::get( $user_order['public_id'], $identity_a ) );
		$this->assertWPError( OrderService::get( $user_order['public_id'], CartSessionService::authenticated( $user_b ) ) );
	}

	/**
	 * Transiciones usan CAS, eventos únicos y motivos para cancelación confirmada.
	 *
	 * @return void
	 */
	public function test_transitions_are_atomic_and_auditable(): void {
		$menu     = $this->seed_menu_item( 'Pizza', 1000 );
		$identity = $this->anonymous_identity();
		$cart     = $this->cart_with_item( $identity, $menu['public_id'] );
		$order    = OrderService::checkout( $identity, 'transition-checkout-1', $this->checkout_input( $cart['revision'] ) );
		$this->assertNotWPError( $order );

		$invalid = OrderService::transition( $order['public_id'], 1, 'en_preparacion', 'operator', 1 );
		$this->assertWPError( $invalid );
		$this->assertSame( 'vicu_restaurante_invalid_transition', $invalid->get_error_code() );
		$this->assertSame( 1, $this->count_rows( Schema::order_events_table_name() ) );

		$reviewed  = OrderService::transition( $order['public_id'], 1, 'pago_en_revision', 'payment' );
		$confirmed = OrderService::transition( $order['public_id'], 2, 'confirmado', 'payment' );
		$this->assertNotWPError( $reviewed );
		$this->assertNotWPError( $confirmed );
		$this->assertSame( 3, $confirmed['revision'] );

		$stale = OrderService::transition( $order['public_id'], 2, 'cancelado', 'operator', 1, 'duplicado' );
		$this->assertWPError( $stale );
		$this->assertSame( 'vicu_restaurante_stale_revision', $stale->get_error_code() );
		$without_reason = OrderService::transition( $order['public_id'], 3, 'cancelado', 'operator', 1 );
		$this->assertWPError( $without_reason );
		$this->assertSame( 'vicu_restaurante_invalid_transition', $without_reason->get_error_code() );

		$cancelled = OrderService::transition( $order['public_id'], 3, 'cancelado', 'operator', 1, 'Cliente solicitó cancelar' );
		$this->assertNotWPError( $cancelled );
		$this->assertSame( 'cancelado', $cancelled['status'] );
		$this->assertSame( 4, $cancelled['revision'] );
		$events = OrderService::admin_detail( $order['public_id'] )['events'];
		$this->assertCount( 4, $events );
		$this->assertSame( array( 1, 2, 3, 4 ), array_column( $events, 'revision' ) );
		$this->assertSame( 'Cliente solicitó cancelar', $events[3]['reason'] );
	}

	/**
	 * Delivery exige dirección y congela el vencimiento sin alterar el carrito al fallar.
	 *
	 * @return void
	 */
	public function test_delivery_checkout_requires_address_and_freezes_expiration(): void {
		$menu = $this->seed_menu_item( 'Combo', 1500 );
		$zone = DeliveryZoneService::create( $this->zone_input( 'Centro', 200 ) );
		$this->assertNotWPError( $zone );
		$identity = $this->anonymous_identity();
		$cart     = $this->cart_with_item( $identity, $menu['public_id'] );
		$cart     = CartService::set_fulfillment( $identity, $cart['revision'], 'delivery', $zone['public_id'] );
		$this->assertNotWPError( $cart );

		$input   = $this->checkout_input( $cart['revision'] );
		$invalid = OrderService::checkout( $identity, 'delivery-checkout-001', $input );
		$this->assertWPError( $invalid );
		$this->assertSame( 'vicu_restaurante_invalid_request', $invalid->get_error_code() );
		$this->assertSame( 'active', CartService::get( $identity )['status'] );

		$input['delivery_address']      = 'Calle 1, edificio 2';
		$input['delivery_instructions'] = 'Tocar el timbre';
		$order                          = OrderService::checkout( $identity, 'delivery-checkout-002', $input );
		$this->assertNotWPError( $order );
		$this->assertSame( 1820, $order['totals']['total'] );
		$expires = strtotime( $order['payment_expires_at'] );
		$this->assertGreaterThanOrEqual( time() + 29 * MINUTE_IN_SECONDS, $expires );
		$this->assertLessThanOrEqual( time() + 31 * MINUTE_IN_SECONDS, $expires );
		$private = OrderService::admin_detail( $order['public_id'] );
		$this->assertSame( 'Calle 1, edificio 2', $private['delivery_address'] );
		$this->assertSame( 'Tocar el timbre', $private['delivery_instructions'] );
	}

	/**
	 * REST conserva CSRF en checkout y token en la consulta privada.
	 *
	 * @return void
	 */
	public function test_rest_checkout_and_private_read(): void {
		$menu                                       = $this->seed_menu_item( 'Ensalada', 900 );
		$identity                                   = $this->anonymous_identity();
		$cart                                       = $this->cart_with_item( $identity, $menu['public_id'] );
		$_COOKIE[ CartSessionService::COOKIE_NAME ] = $identity['credential'];
		$headers                                    = array(
			'Origin'          => home_url( '/' ),
			'X-Vicu-CSRF'     => $identity['csrf_token'],
			'Idempotency-Key' => 'rest-checkout-key-001',
		);
		$response                                   = $this->dispatch( 'POST', '/vicu/v1/restaurante/orders', $this->checkout_input( $cart['revision'] ), $headers );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] );
		$this->assertTrue( rest_validate_value_from_schema( $response->get_data(), OrderRoutes::order_schema() ) );
		$this->assertArrayNotHasKey( 'customer', $response->get_data() );

		$order_id = $response->get_data()['public_id'];
		$missing  = $this->dispatch( 'GET', '/vicu/v1/restaurante/orders/' . $order_id );
		$this->assertSame( 401, $missing->get_status() );
		$wrong = $this->dispatch( 'GET', '/vicu/v1/restaurante/orders/' . $order_id, array(), array( 'X-Vicu-Order-Token' => str_repeat( '0', 64 ) ) );
		$this->assertSame( 404, $wrong->get_status() );
		$read = $this->dispatch( 'GET', '/vicu/v1/restaurante/orders/' . $order_id, array(), array( 'X-Vicu-Order-Token' => $response->get_data()['access_token'] ) );
		$this->assertSame( 200, $read->get_status() );
		$this->assertSame( $order_id, $read->get_data()['public_id'] );
	}

	/**
	 * Crea una identidad anónima.
	 *
	 * @return array<string, int|string>
	 */
	private function anonymous_identity(): array {
		$identity = CartSessionService::create_anonymous();
		$this->assertNotWPError( $identity );

		return $identity;
	}

	/**
	 * Identidad sin cuenta para consulta por token.
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
	 * Crea y llena un carrito.
	 *
	 * @param array<string, int|string> $identity Identidad.
	 * @param string                    $menu_id  UUID de menú.
	 * @return array<string, mixed>
	 */
	private function cart_with_item( array $identity, string $menu_id ): array {
		$cart = CartService::create( $identity );
		$this->assertNotWPError( $cart );
		$cart = CartService::add_item(
			$identity,
			$cart['revision'],
			array(
				'type'                   => 'menu',
				'menu_item_id'           => $menu_id,
				'quantity'               => 1,
				'options'                => array(),
				'removed_ingredient_ids' => array(),
				'note'                   => '',
			)
		);
		$this->assertNotWPError( $cart );

		return $cart;
	}

	/**
	 * Datos privados válidos.
	 *
	 * @param int $revision Revisión.
	 * @return array<string, mixed>
	 */
	private function checkout_input( int $revision ): array {
		return array(
			'expected_revision' => $revision,
			'customer'          => array(
				'name'  => 'Cliente Ejemplo',
				'email' => 'cliente@example.com',
				'phone' => '+58 412 0000000',
			),
			'customer_note'     => 'Sin datos sensibles adicionales',
		);
	}

	/**
	 * Crea un item contractual y devuelve su post interno solo al test.
	 *
	 * @param string $name  Nombre.
	 * @param int    $price Precio.
	 * @return array{post_id: int, public_id: string}
	 */
	private function seed_menu_item( string $name, int $price ): array {
		$term = wp_insert_term( 'Platos', MenuCategory::TAXONOMY, array( 'slug' => 'orden-' . wp_generate_password( 8, false ) ) );
		$this->assertNotWPError( $term );
		update_term_meta( $term['term_id'], MenuCategory::META_VISIBLE, true );
		update_term_meta( $term['term_id'], MenuCategory::META_ORDER, 0 );
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => MenuItemPostType::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
		wp_set_object_terms( $post_id, array( (int) $term['term_id'] ), MenuCategory::TAXONOMY );
		$public_id = wp_generate_uuid4();
		update_post_meta( $post_id, MenuMeta::PUBLIC_ID, $public_id );
		update_post_meta( $post_id, MenuMeta::PRICE_MINOR, $price );
		update_post_meta( $post_id, MenuMeta::CURRENCY, 'USD' );
		update_post_meta( $post_id, MenuMeta::AVAILABLE, true );
		update_post_meta( $post_id, MenuMeta::CALORIES_KCAL, 0 );
		update_post_meta( $post_id, MenuMeta::ALLERGENS, array() );
		update_post_meta( $post_id, MenuMeta::DIETARY_TAGS, array() );
		wp_cache_flush();

		return array(
			'post_id'   => $post_id,
			'public_id' => $public_id,
		);
	}

	/**
	 * Descuento porcentual.
	 *
	 * @param string   $code     Código.
	 * @param int      $value    Puntos base.
	 * @param int|null $max_uses Límite.
	 * @return array<string, mixed>
	 */
	private function discount_input( string $code, int $value, ?int $max_uses ): array {
		return array(
			'code'                   => $code,
			'type'                   => 'percent',
			'value'                  => $value,
			'active'                 => true,
			'valid_from'             => null,
			'valid_until'            => null,
			'minimum_subtotal_minor' => 0,
			'max_uses'               => $max_uses,
		);
	}

	/**
	 * Zona activa.
	 *
	 * @param string $name Nombre.
	 * @param int    $fee  Tarifa.
	 * @return array<string, mixed>
	 */
	private function zone_input( string $name, int $fee ): array {
		return array(
			'name'            => $name,
			'active'          => true,
			'fee_minor'       => $fee,
			'eta_min_minutes' => 20,
			'eta_max_minutes' => 40,
			'display_order'   => 0,
		);
	}

	/**
	 * Encuentra una proyección por meta.
	 *
	 * @param string $public_id UUID.
	 * @return int
	 */
	private function projection_post( string $public_id ): int {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Consulta acotada de prueba sobre la proyección canónica.
		$posts = get_posts(
			array(
				'post_type'      => OrderPostType::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => OrderPostType::META_PUBLIC_ID,
				'meta_value'     => $public_id,
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		return isset( $posts[0] ) ? (int) $posts[0] : 0;
	}

	/**
	 * Cuenta filas de una tabla fija.
	 *
	 * @param string $table Tabla del schema.
	 * @return int
	 */
	private function count_rows( string $table ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Despacha REST.
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
	 * Vacía tablas en orden seguro.
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

		foreach ( get_posts(
			array(
				'post_type'   => OrderPostType::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		) as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}
}
