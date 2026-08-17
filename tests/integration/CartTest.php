<?php
/**
 * Pruebas transaccionales de sesiones y carritos.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Cart\CartService;
use Vicu\Restaurante\Cart\CartSessionService;
use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Catalog\IngredientService;
use Vicu\Restaurante\Catalog\PizzaOptionService;
use Vicu\Restaurante\Commerce\DeliveryZoneService;
use Vicu\Restaurante\Commerce\DiscountService;
use Vicu\Restaurante\Commerce\PricingRevision;
use Vicu\Restaurante\Installer;
use Vicu\Restaurante\Menu\CatalogRevision;
use Vicu\Restaurante\Menu\MenuCategory;
use Vicu\Restaurante\Menu\MenuItemPostType;
use Vicu\Restaurante\Menu\MenuMeta;
use Vicu\Restaurante\Rest\CartRoutes;
use Vicu\Restaurante\Schema;
use Vicu\Restaurante\Settings\RestaurantSettings;

/**
 * Verifica ownership, CAS, recálculo y protección REST con MySQL real.
 */
final class CartTest extends WP_UnitTestCase {
	/**
	 * Instala y vacía recursos propios antes de cada caso.
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
		$this->truncate_domain_tables();
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
	 * Restaura globals sensibles a otras suites.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$_COOKIE = array();
		wp_set_current_user( 0 );
		delete_option( RestaurantSettings::OPTION_NAME );
		remove_all_filters( 'vicu_restaurante_allow_cart_creation' );
		parent::tearDown();
	}

	/**
	 * El schema nace vacío y las sesiones nunca guardan secretos en claro.
	 *
	 * @return void
	 */
	public function test_schema_and_session_hash_secrets(): void {
		global $wpdb;

		foreach ( $this->cart_tables() as $table_name ) {
			$this->assertTrue( Schema::table_exists( $table_name ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', DB_NAME, $table_name ) );
			$this->assertSame( 'InnoDB', $engine );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ) );
		}

		$identity = CartSessionService::create_anonymous();
		$this->assertNotWPError( $identity );
		list( $public_id, $secret ) = explode( '.', $identity['credential'], 2 );
		$table                      = Schema::cart_sessions_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s", $public_id ), ARRAY_A );

		$this->assertNotSame( $secret, $row['secret_hash'] );
		$this->assertNotSame( $identity['csrf_token'], $row['csrf_hash'] );
		$this->assertSame( $identity['key'], CartSessionService::resolve_anonymous( $identity['credential'] )['key'] );
		$this->assertWPError( CartSessionService::resolve_anonymous( $public_id . '.' . str_repeat( '0', 64 ) ) );
	}

	/**
	 * Menú equivalente se fusiona y una revisión obsoleta no sobrescribe.
	 *
	 * @return void
	 */
	public function test_menu_merge_and_compare_and_swap(): void {
		$menu_id  = $this->seed_menu_item( 'Pasta', 1250 );
		$identity = $this->anonymous_identity();
		$cart     = CartService::create( $identity );
		$this->assertNotWPError( $cart );

		$first = CartService::add_item( $identity, 1, $this->menu_line( $menu_id, 1, 'Sin cubiertos' ) );
		$this->assertNotWPError( $first );
		$this->assertSame( 2, $first['revision'] );
		$this->assertSame( 1250, $first['totals']['subtotal_minor'] );

		$merged = CartService::add_item( $identity, 2, $this->menu_line( $menu_id, 2, '  Sin   cubiertos ' ) );
		$this->assertNotWPError( $merged );
		$this->assertCount( 1, $merged['items'] );
		$this->assertSame( 3, $merged['items'][0]['quantity'] );
		$this->assertSame( 3750, $merged['totals']['subtotal_minor'] );

		$stale = CartService::remove_item( $identity, 2, $merged['items'][0]['line_id'] );
		$this->assertWPError( $stale );
		$this->assertSame( 'vicu_restaurante_stale_revision', $stale->get_error_code() );
		$this->assertSame( 3, $stale->get_error_data()['current_revision'] );
		$this->assertCount( 1, CartService::get( $identity )['items'] );
	}

	/**
	 * Las pizzas nunca se fusionan y una sustitución fallida conserva la original.
	 *
	 * @return void
	 */
	public function test_pizzas_do_not_merge_and_failed_edit_is_atomic(): void {
		$catalog       = $this->seed_pizza_catalog();
		$configuration = $this->pizza_configuration( $catalog );
		$identity      = $this->anonymous_identity();
		$this->assertNotWPError( CartService::create( $identity ) );

		$first  = CartService::add_item(
			$identity,
			1,
			array(
				'type'          => 'pizza',
				'configuration' => $configuration,
			)
		);
		$second = CartService::add_item(
			$identity,
			2,
			array(
				'type'          => 'pizza',
				'configuration' => $configuration,
			)
		);
		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->assertCount( 2, $second['items'] );
		$this->assertNotSame( $second['items'][0]['line_id'], $second['items'][1]['line_id'] );

		$invalid            = $configuration;
		$invalid['size_id'] = wp_generate_uuid4();
		$replacement        = CartService::replace_item(
			$identity,
			3,
			$second['items'][0]['line_id'],
			array(
				'type'          => 'pizza',
				'configuration' => $invalid,
			)
		);
		$this->assertWPError( $replacement );
		$this->assertSame( 'vicu_restaurante_unavailable', $replacement->get_error_code() );
		$unchanged = CartService::get( $identity );
		$this->assertSame( 3, $unchanged['revision'] );
		$this->assertCount( 2, $unchanged['items'] );
		$this->assertSame( $configuration['size_id'], $unchanged['items'][0]['selection']['configuration']['size_id'] );
	}

	/**
	 * Descuento, propina y delivery se recalculan siempre desde reglas vivas.
	 *
	 * @return void
	 */
	public function test_cart_recalculates_discount_tip_and_delivery(): void {
		$menu_id  = $this->seed_menu_item( 'Cena', 2000 );
		$zone     = DeliveryZoneService::create( $this->zone_input( 'Centro', 150 ) );
		$discount = DiscountService::create( $this->discount_input( 'DIEZ', 1000 ) );
		$this->assertNotWPError( $zone );
		$this->assertNotWPError( $discount );

		$identity = $this->anonymous_identity();
		$this->assertNotWPError( CartService::create( $identity ) );
		$cart = CartService::add_item( $identity, 1, $this->menu_line( $menu_id ) );
		$cart = CartService::set_discount( $identity, 2, ' diez ' );
		$cart = CartService::set_fulfillment( $identity, 3, 'delivery', $zone['public_id'] );
		$cart = CartService::set_tip( $identity, 4, 1500 );

		$this->assertNotWPError( $cart );
		$this->assertSame( 5, $cart['revision'] );
		$this->assertSame( 'DIEZ', $cart['discount_code'] );
		$this->assertSame( 2000, $cart['totals']['subtotal_minor'] );
		$this->assertSame( 200, $cart['totals']['discount_total'] );
		$this->assertSame( 144, $cart['totals']['tax_total'] );
		$this->assertSame( 270, $cart['totals']['tip_total'] );
		$this->assertSame( 150, $cart['totals']['delivery_total'] );
		$this->assertSame( 2364, $cart['totals']['total'] );
		$this->assertSame( 0, $discount['uses_count'], 'Aplicar al carrito no consume el código.' );
	}

	/**
	 * REST exige origen y CSRF anónimos y nunca permite caché privada.
	 *
	 * @return void
	 */
	public function test_anonymous_rest_security_and_projection(): void {
		$menu_id                                    = $this->seed_menu_item( 'Ensalada', 900 );
		$identity                                   = $this->anonymous_identity();
		$_COOKIE[ CartSessionService::COOKIE_NAME ] = $identity['credential'];
		$this->assertNotWPError( CartService::create( $identity ) );

		$read = $this->dispatch( 'GET', '/vicu/v1/restaurante/cart' );
		$this->assertSame( 200, $read->get_status() );
		$this->assertSame( 'no-store, max-age=0', $read->get_headers()['Cache-Control'] );
		$this->assertSame( $identity['csrf_token'], $read->get_data()['csrf_token'] );
		$this->assertArrayNotHasKey( 'session_id', $read->get_data() );
		$this->assertTrue( rest_validate_value_from_schema( $read->get_data(), CartRoutes::cart_schema() ) );

		$payload      = array(
			'expected_revision' => 1,
			'item'              => $this->menu_line( $menu_id ),
		);
		$missing_csrf = $this->dispatch( 'POST', '/vicu/v1/restaurante/cart/items', $payload, array( 'Origin' => home_url( '/' ) ) );
		$this->assertSame( 403, $missing_csrf->get_status() );

		$wrong_origin = $this->dispatch(
			'POST',
			'/vicu/v1/restaurante/cart/items',
			$payload,
			array(
				'Origin'      => 'https://example.invalid',
				'X-Vicu-CSRF' => $identity['csrf_token'],
			)
		);
		$this->assertSame( 403, $wrong_origin->get_status() );

		$created = $this->dispatch(
			'POST',
			'/vicu/v1/restaurante/cart/items',
			$payload,
			array(
				'Origin'      => home_url( '/' ),
				'X-Vicu-CSRF' => $identity['csrf_token'],
			)
		);
		$this->assertSame( 200, $created->get_status() );
		$this->assertSame( 2, $created->get_data()['revision'] );
	}

	/**
	 * Un usuario necesita nonce y nunca accede al carrito de otro usuario.
	 *
	 * @return void
	 */
	public function test_authenticated_cart_requires_nonce_and_ownership(): void {
		$user_a = self::factory()->user->create();
		$user_b = self::factory()->user->create();
		wp_set_current_user( $user_a );
		$nonce_a   = wp_create_nonce( 'wp_rest' );
		$created_a = $this->dispatch( 'POST', '/vicu/v1/restaurante/carts', array(), array( 'X-WP-Nonce' => $nonce_a ) );
		$this->assertSame( 201, $created_a->get_status() );
		$this->assertArrayNotHasKey( 'csrf_token', $created_a->get_data() );

		$without_nonce = $this->dispatch( 'GET', '/vicu/v1/restaurante/cart' );
		$this->assertSame( 403, $without_nonce->get_status() );

		wp_set_current_user( $user_b );
		$other = $this->dispatch( 'GET', '/vicu/v1/restaurante/cart', array(), array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
		$this->assertSame( 404, $other->get_status() );

		wp_set_current_user( $user_a );
		$own = $this->dispatch( 'GET', '/vicu/v1/restaurante/cart', array(), array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
		$this->assertSame( 200, $own->get_status() );
		$this->assertSame( $created_a->get_data()['public_id'], $own->get_data()['public_id'] );
	}

	/**
	 * Login adopta un único carrito anónimo sin mezclarlo con otro carrito existente.
	 *
	 * @return void
	 */
	public function test_login_association_is_idempotent_and_rotates_session(): void {
		$menu_id = $this->seed_menu_item( 'Pan', 500 );
		$session = $this->anonymous_identity();
		$cart    = CartService::create( $session );
		$cart    = CartService::add_item( $session, 1, $this->menu_line( $menu_id ) );
		$this->assertNotWPError( $cart );
		$_COOKIE[ CartSessionService::COOKIE_NAME ] = $session['credential'];

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$headers = array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) );
		$adopted = $this->dispatch( 'POST', '/vicu/v1/restaurante/carts', array(), $headers );
		$this->assertSame( 201, $adopted->get_status() );
		$this->assertSame( $cart['public_id'], $adopted->get_data()['public_id'] );
		$this->assertCount( 1, $adopted->get_data()['items'] );
		$this->assertWPError( CartSessionService::resolve_anonymous( $session['credential'] ) );

		unset( $_COOKIE[ CartSessionService::COOKIE_NAME ] );
		$retry = $this->dispatch( 'POST', '/vicu/v1/restaurante/carts', array(), $headers );
		$this->assertSame( $adopted->get_data()['public_id'], $retry->get_data()['public_id'] );
	}

	/**
	 * La creación anónima exige origen y expone un punto de rate limiting.
	 *
	 * @return void
	 */
	public function test_anonymous_creation_requires_origin_and_rate_limit(): void {
		$forbidden = $this->dispatch( 'POST', '/vicu/v1/restaurante/carts' );
		$this->assertSame( 403, $forbidden->get_status() );

		$created = $this->dispatch( 'POST', '/vicu/v1/restaurante/carts', array(), array( 'Origin' => home_url( '/' ) ) );
		$this->assertSame( 201, $created->get_status() );
		$this->assertTrue( wp_is_uuid( $created->get_data()['public_id'], 4 ) );
		$this->assertNotEmpty( $created->get_data()['csrf_token'] );

		add_filter( 'vicu_restaurante_allow_cart_creation', '__return_false' );
		$limited = $this->dispatch( 'POST', '/vicu/v1/restaurante/carts', array(), array( 'Origin' => home_url( '/' ) ) );
		$this->assertSame( 429, $limited->get_status() );
	}

	/**
	 * La expiración libera ownership sin borrar líneas ni crear pedidos.
	 *
	 * @return void
	 */
	public function test_expiration_is_repeatable_and_non_destructive(): void {
		global $wpdb;

		$menu_id  = $this->seed_menu_item( 'Sopa', 700 );
		$identity = $this->anonymous_identity();
		$this->assertNotWPError( CartService::create( $identity ) );
		$this->assertNotWPError( CartService::add_item( $identity, 1, $this->menu_line( $menu_id ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::carts_table_name(), array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'owner_key' => $identity['key'] ), array( '%s' ), array( '%s' ) );
		$this->assertSame( 1, CartService::expire_due() );
		$this->assertSame( 0, CartService::expire_due() );
		$this->assertWPError( CartService::get( $identity ) );
		$items_table = Schema::cart_items_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items_table}" ) );
	}

	/**
	 * Crea una identidad anónima válida.
	 *
	 * @return array<string, int|string>
	 */
	private function anonymous_identity(): array {
		$identity = CartSessionService::create_anonymous();
		$this->assertNotWPError( $identity );

		return $identity;
	}

	/**
	 * Crea un item público completo.
	 *
	 * @param string $name        Nombre.
	 * @param int    $price_minor Precio.
	 * @return string
	 */
	private function seed_menu_item( string $name, int $price_minor ): string {
		$term = wp_insert_term( 'Platos', MenuCategory::TAXONOMY, array( 'slug' => 'platos-' . wp_generate_password( 6, false ) ) );
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
		update_post_meta( $post_id, MenuMeta::PRICE_MINOR, $price_minor );
		update_post_meta( $post_id, MenuMeta::CURRENCY, 'USD' );
		update_post_meta( $post_id, MenuMeta::AVAILABLE, true );
		update_post_meta( $post_id, MenuMeta::CALORIES_KCAL, 0 );
		update_post_meta( $post_id, MenuMeta::ALLERGENS, array() );
		update_post_meta( $post_id, MenuMeta::DIETARY_TAGS, array() );
		wp_cache_flush();

		return $public_id;
	}

	/**
	 * Payload de línea de menú sin importes.
	 *
	 * @param string $public_id UUID.
	 * @param int    $quantity  Cantidad.
	 * @param string $note      Nota.
	 * @return array<string, mixed>
	 */
	private function menu_line( string $public_id, int $quantity = 1, string $note = '' ): array {
		return array(
			'type'                   => 'menu',
			'menu_item_id'           => $public_id,
			'quantity'               => $quantity,
			'options'                => array(),
			'removed_ingredient_ids' => array(),
			'note'                   => $note,
			'total_minor'            => 1,
		);
	}

	/**
	 * Crea catálogo mínimo del constructor.
	 *
	 * @return array<string, mixed>
	 */
	private function seed_pizza_catalog(): array {
		$catalog = array(
			'size'   => PizzaOptionService::create( $this->option_input( 'Mediana', 'size', 850 ) ),
			'crust'  => PizzaOptionService::create( $this->option_input( 'Clásica', 'crust', 0 ) ),
			'sauce'  => PizzaOptionService::create( $this->option_input( 'Tomate', 'sauce', 0 ) ),
			'cheese' => IngredientService::create( $this->ingredient_input( 'Mozzarella', 'cheese', 75 ) ),
		);

		foreach ( $catalog as $resource ) {
			$this->assertNotWPError( $resource );
		}

		$catalog['revision'] = AvailabilityRevision::current();

		return $catalog;
	}

	/**
	 * Construye una configuración válida.
	 *
	 * @param array<string, mixed> $catalog Catálogo.
	 * @return array<string, mixed>
	 */
	private function pizza_configuration( array $catalog ): array {
		return array(
			'version'              => 1,
			'catalog_revision'     => $catalog['revision'],
			'size_id'              => $catalog['size']['public_id'],
			'crust_id'             => $catalog['crust']['public_id'],
			'sauce_id'             => $catalog['sauce']['public_id'],
			'cheese_ingredient_id' => $catalog['cheese']['public_id'],
			'toppings'             => array(),
			'quantity'             => 1,
		);
	}

	/**
	 * Opción de pizza disponible.
	 *
	 * @param string $name  Nombre.
	 * @param string $type  Tipo.
	 * @param int    $price Modificador.
	 * @return array<string, mixed>
	 */
	private function option_input( string $name, string $type, int $price ): array {
		return array(
			'name'                 => $name,
			'type'                 => $type,
			'price_modifier_minor' => $price,
			'available'            => true,
			'display_order'        => 0,
		);
	}

	/**
	 * Ingrediente disponible.
	 *
	 * @param string $name     Nombre.
	 * @param string $category Categoría.
	 * @param int    $price    Modificador.
	 * @return array<string, mixed>
	 */
	private function ingredient_input( string $name, string $category, int $price ): array {
		return array(
			'name'                 => $name,
			'category'             => $category,
			'price_modifier_minor' => $price,
			'available'            => true,
			'allergens'            => array(),
			'dietary_tags'         => array(),
		);
	}

	/**
	 * Zona activa de entrega.
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
	 * Descuento porcentual activo.
	 *
	 * @param string $code  Código.
	 * @param int    $value Puntos base.
	 * @return array<string, mixed>
	 */
	private function discount_input( string $code, int $value ): array {
		return array(
			'code'                   => $code,
			'type'                   => 'percent',
			'value'                  => $value,
			'active'                 => true,
			'valid_from'             => null,
			'valid_until'            => null,
			'minimum_subtotal_minor' => 0,
			'max_uses'               => null,
		);
	}

	/**
	 * Despacha una solicitud REST privada.
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
	 * Vacía recursos transaccionales en orden seguro.
	 *
	 * @return void
	 */
	private function truncate_domain_tables(): void {
		global $wpdb;

		foreach ( array_merge( $this->cart_tables(), array( Schema::discount_codes_table_name(), Schema::delivery_zones_table_name(), Schema::menu_ingredients_table_name(), Schema::ingredients_table_name(), Schema::pizza_options_table_name() ) ) as $table_name ) {
			// El identificador pertenece al schema fijo del plugin.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertNotFalse( $wpdb->query( "TRUNCATE TABLE {$table_name}" ) );
		}
	}

	/**
	 * Tablas propias de REST-02H.
	 *
	 * @return string[]
	 */
	private function cart_tables(): array {
		return array(
			Schema::idempotency_table_name(),
			Schema::cart_items_table_name(),
			Schema::carts_table_name(),
			Schema::cart_sessions_table_name(),
		);
	}
}
