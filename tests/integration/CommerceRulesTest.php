<?php
/**
 * Pruebas de zonas, descuentos, ajustes y totales con MySQL real.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Capabilities;
use Vicu\Restaurante\Commerce\DeliveryZoneService;
use Vicu\Restaurante\Commerce\DiscountService;
use Vicu\Restaurante\Commerce\PricingRevision;
use Vicu\Restaurante\Commerce\TotalsService;
use Vicu\Restaurante\Installer;
use Vicu\Restaurante\Rest\DeliveryZonesRoute;
use Vicu\Restaurante\Schema;
use Vicu\Restaurante\Settings\RestaurantSettings;

/**
 * Verifica persistencia transaccional y la fórmula normativa completa.
 */
final class CommerceRulesTest extends WP_UnitTestCase {
	/**
	 * Instala y vacía únicamente recursos de REST-02G.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( Installer::install() );
		$this->truncate_commerce();
		update_option(
			RestaurantSettings::OPTION_NAME,
			array(
				'currency'      => 'USD',
				'tax_rate_bps'  => 800,
				'tip_rates_bps' => array( 0, 1000, 1500, 2000 ),
			),
			false
		);
		update_option( PricingRevision::OPTION_NAME, '1', false );
		PricingRevision::clear_cache();
		Capabilities::grant_to_administrator();
		wp_cache_flush();
	}

	/**
	 * Limpia el ajuste propio.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( RestaurantSettings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Las tablas son InnoDB, indexadas y nacen sin datos de demo.
	 *
	 * @return void
	 */
	public function test_schema_is_transactional_and_empty(): void {
		global $wpdb;

		foreach ( array( Schema::delivery_zones_table_name(), Schema::discount_codes_table_name() ) as $table_name ) {
			$this->assertTrue( Schema::table_exists( $table_name ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', DB_NAME, $table_name ) );
			$this->assertSame( 'InnoDB', $engine );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ) );
		}

		$this->assertSame( 1, PricingRevision::current() );
	}

	/**
	 * Zonas usan CAS y una sola revisión global por escritura confirmada.
	 *
	 * @return void
	 */
	public function test_delivery_zone_compare_and_swap(): void {
		$zone = DeliveryZoneService::create( $this->zone_input( 'Centro', true, 150, 20, 35 ) );
		$this->assertNotWPError( $zone );
		$this->assertSame( 1, $zone['revision'] );
		$this->assertSame( 2, PricingRevision::current() );

		$updated = DeliveryZoneService::update( $zone['public_id'], 1, $this->zone_input( 'Centro', false, 175, 25, 40 ) );
		$this->assertNotWPError( $updated );
		$this->assertSame( 2, $updated['revision'] );
		$this->assertFalse( $updated['active'] );
		$this->assertSame( 3, PricingRevision::current() );

		$stale = DeliveryZoneService::update( $zone['public_id'], 1, $this->zone_input( 'Perdida', true, 1, 1, 2 ) );
		$this->assertWPError( $stale );
		$this->assertSame( 'vicu_restaurante_stale_revision', $stale->get_error_code() );
		$this->assertSame( 3, PricingRevision::current() );

		$invalid = DeliveryZoneService::create( $this->zone_input( 'ETA inválido', true, 100, 50, 20 ) );
		$this->assertWPError( $invalid );
	}

	/**
	 * Descuentos fijos y porcentuales respetan vigencia, mínimo y half-up.
	 *
	 * @return void
	 */
	public function test_discount_resolution_and_rounding(): void {
		$percent = DiscountService::create(
			$this->discount_input( ' DIEZ ', 'percent', 1000, true, 1000, null, '2026-08-01 00:00:00', '2026-09-01 00:00:00' )
		);
		$this->assertNotWPError( $percent );
		$this->assertSame( 'DIEZ', $percent['code'] );

		$resolved = DiscountService::resolve( 'diez', 1005, '2026-08-16 12:00:00' );
		$this->assertNotWPError( $resolved );
		$this->assertSame( 101, $resolved['amount_minor'] );

		$below_minimum = DiscountService::resolve( 'DIEZ', 999, '2026-08-16 12:00:00' );
		$this->assertWPError( $below_minimum );
		$this->assertSame( 'vicu_restaurante_unavailable', $below_minimum->get_error_code() );

		$expired = DiscountService::resolve( 'DIEZ', 2000, '2026-09-01 00:00:00' );
		$this->assertWPError( $expired );

		$fixed = DiscountService::create( $this->discount_input( 'REGALO', 'fixed', 1000, true ) );
		$this->assertNotWPError( $fixed );
		$this->assertSame( 500, DiscountService::resolve( 'REGALO', 500 )['amount_minor'] );

		$updated = DiscountService::update( $fixed['public_id'], 1, $this->discount_input( 'REGALO', 'fixed', 750, true ) );
		$this->assertNotWPError( $updated );
		$this->assertSame( 2, $updated['revision'] );
		$this->assertNull( $updated['max_uses'] );
		$stale = DiscountService::update( $fixed['public_id'], 1, $this->discount_input( 'REGALO', 'fixed', 500, true ) );
		$this->assertWPError( $stale );
		$this->assertSame( 'vicu_restaurante_stale_revision', $stale->get_error_code() );
	}

	/**
	 * El último uso disponible se consume una vez bajo bloqueo de fila.
	 *
	 * @return void
	 */
	public function test_discount_consumption_enforces_limit(): void {
		$discount = DiscountService::create( $this->discount_input( 'ULTIMO', 'fixed', 100, true, 0, 1 ) );
		$this->assertNotWPError( $discount );
		$revision_before = PricingRevision::current();

		$consumed = DiscountService::consume( 'ULTIMO', 1000 );
		$this->assertNotWPError( $consumed );
		$this->assertSame( 1, $consumed['uses_count'] );
		$this->assertSame( 2, $consumed['revision'] );
		$this->assertSame( $revision_before + 1, PricingRevision::current() );

		$exhausted = DiscountService::consume( 'ULTIMO', 1000 );
		$this->assertWPError( $exhausted );
		$this->assertSame( 'vicu_restaurante_unavailable', $exhausted->get_error_code() );
		$this->assertSame( 1, DiscountService::find( $discount['public_id'] )['uses_count'] );
	}

	/**
	 * Totales preservan descuento, impuesto, propina y delivery en ese orden.
	 *
	 * @return void
	 */
	public function test_totals_follow_normative_order(): void {
		$zone = DeliveryZoneService::create( $this->zone_input( 'Norte', true, 150, 20, 35 ) );
		$this->assertNotWPError( $zone );
		$this->assertNotWPError( DiscountService::create( $this->discount_input( 'BONASERA10', 'percent', 1000, true ) ) );

		$totals = TotalsService::calculate( 2000, 'bonasera10', 1500, 'delivery', $zone['public_id'] );
		$this->assertNotWPError( $totals );
		$this->assertSame( 2000, $totals['subtotal_minor'] );
		$this->assertSame( 200, $totals['discount_total'] );
		$this->assertSame( 1800, $totals['net_merchandise'] );
		$this->assertSame( 144, $totals['tax_total'] );
		$this->assertSame( 270, $totals['tip_total'] );
		$this->assertSame( 150, $totals['delivery_total'] );
		$this->assertSame( 2364, $totals['total'] );
		$this->assertSame( 'USD', $totals['currency'] );

		$pickup = TotalsService::calculate( 2000, null, 0, 'pickup', null );
		$this->assertNotWPError( $pickup );
		$this->assertSame( 0, $pickup['delivery_total'] );
		$this->assertSame( 2160, $pickup['total'] );
	}

	/**
	 * Totales rechazan propinas libres, zona inactiva y tarifa en pickup.
	 *
	 * @return void
	 */
	public function test_totals_fail_closed_for_invalid_fulfillment(): void {
		$zone = DeliveryZoneService::create( $this->zone_input( 'Cerrada', false, 300, 40, 60 ) );
		$this->assertNotWPError( $zone );

		$invalid_tip = TotalsService::calculate( 1000, null, 1250, 'pickup', null );
		$this->assertWPError( $invalid_tip );
		$this->assertSame( 'vicu_restaurante_invalid_request', $invalid_tip->get_error_code() );

		$pickup_zone = TotalsService::calculate( 1000, null, 0, 'pickup', $zone['public_id'] );
		$this->assertWPError( $pickup_zone );

		$inactive = TotalsService::calculate( 1000, null, 0, 'delivery', $zone['public_id'] );
		$this->assertWPError( $inactive );
		$this->assertSame( 'vicu_restaurante_unavailable', $inactive->get_error_code() );
	}

	/**
	 * REST omite zonas inactivas y su ETag cambia con la revisión.
	 *
	 * @return void
	 */
	public function test_delivery_zones_route_filters_and_revalidates(): void {
		$active = DeliveryZoneService::create( $this->zone_input( 'Centro', true, 150, 20, 35, 2 ) );
		$this->assertNotWPError( $active );
		$this->assertNotWPError( DeliveryZoneService::create( $this->zone_input( 'Oculta', false, 200, 30, 45, 1 ) ) );

		$response = $this->dispatch_zones();
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data()['zones'] );
		$this->assertSame( 'Centro', $response->get_data()['zones'][0]['name'] );
		$this->assertArrayNotHasKey( 'active', $response->get_data()['zones'][0] );
		$this->assertTrue( rest_validate_value_from_schema( $response->get_data(), DeliveryZonesRoute::schema() ) );

		$conditional = new WP_REST_Request( 'GET', '/vicu/v1/restaurante/delivery-zones' );
		$conditional->set_header( 'If-None-Match', $response->get_headers()['ETag'] );
		$this->assertSame( 304, rest_get_server()->dispatch( $conditional )->get_status() );

		$updated = DeliveryZoneService::update( $active['public_id'], 1, $this->zone_input( 'Centro', true, 175, 20, 35, 2 ) );
		$this->assertNotWPError( $updated );
		$refreshed = $this->dispatch_zones();
		$this->assertNotSame( $response->get_headers()['ETag'], $refreshed->get_headers()['ETag'] );
		$this->assertSame( 175, $refreshed->get_data()['zones'][0]['fee_minor'] );
	}

	/**
	 * Ajustes y capabilities se mantienen controlados.
	 *
	 * @return void
	 */
	public function test_settings_and_capabilities_are_strict(): void {
		$sanitized = RestaurantSettings::sanitize(
			array(
				'currency'      => ' ves ',
				'tax_rate_bps'  => '800',
				'tip_rates_bps' => '2000,0,1000,1000',
			)
		);
		$this->assertSame(
			array(
				'currency'                    => 'VES',
				'tax_rate_bps'                => 800,
				'tip_rates_bps'               => array( 0, 1000, 2000 ),
				'cart_lifetime_hours'         => 72,
				'payment_lifetime_minutes'    => 30,
				'manual_payment_instructions' => '',
			),
			$sanitized
		);

		$revision = PricingRevision::current();
		update_option( RestaurantSettings::OPTION_NAME, $sanitized, false );
		$this->assertSame( $revision + 1, PricingRevision::current() );

		$administrator = get_role( 'administrator' );
		$editor        = get_role( 'editor' );
		$this->assertTrue( $administrator->has_cap( 'manage_vicu_restaurant_delivery' ) );
		$this->assertTrue( $administrator->has_cap( 'manage_vicu_restaurant_discounts' ) );
		$this->assertFalse( $editor->has_cap( 'manage_vicu_restaurant_delivery' ) );
		$this->assertFalse( $editor->has_cap( 'manage_vicu_restaurant_discounts' ) );
		$this->assertNotFalse( has_action( 'admin_post_vicu_restaurante_save_delivery_zone' ) );
		$this->assertNotFalse( has_action( 'admin_post_vicu_restaurante_save_discount' ) );
	}

	/**
	 * Payload de zona.
	 *
	 * @param string $name    Nombre.
	 * @param bool   $active  Estado.
	 * @param int    $fee     Tarifa.
	 * @param int    $eta_min ETA mínimo.
	 * @param int    $eta_max ETA máximo.
	 * @param int    $order   Orden.
	 * @return array<string, mixed>
	 */
	private function zone_input( string $name, bool $active, int $fee, int $eta_min, int $eta_max, int $order = 0 ): array {
		return array(
			'name'            => $name,
			'active'          => $active,
			'fee_minor'       => $fee,
			'eta_min_minutes' => $eta_min,
			'eta_max_minutes' => $eta_max,
			'display_order'   => $order,
		);
	}

	/**
	 * Payload de descuento.
	 *
	 * @param string      $code      Código.
	 * @param string      $type      Tipo.
	 * @param int         $value     Valor.
	 * @param bool        $active    Estado.
	 * @param int         $minimum   Subtotal mínimo.
	 * @param int|null    $max_uses  Límite de usos.
	 * @param string|null $from      Inicio UTC.
	 * @param string|null $until     Fin UTC.
	 * @return array<string, mixed>
	 */
	private function discount_input( string $code, string $type, int $value, bool $active, int $minimum = 0, ?int $max_uses = null, ?string $from = null, ?string $until = null ): array {
		return array(
			'code'                   => $code,
			'type'                   => $type,
			'value'                  => $value,
			'active'                 => $active,
			'valid_from'             => $from,
			'valid_until'            => $until,
			'minimum_subtotal_minor' => $minimum,
			'max_uses'               => $max_uses,
		);
	}

	/**
	 * Despacha zonas públicas.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch_zones(): WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/vicu/v1/restaurante/delivery-zones' ) );
	}

	/**
	 * Vacía tablas propias en orden seguro.
	 *
	 * @return void
	 */
	private function truncate_commerce(): void {
		global $wpdb;

		foreach ( array( Schema::discount_codes_table_name(), Schema::delivery_zones_table_name() ) as $table_name ) {
			// El identificador pertenece al schema fijo del plugin.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertNotFalse( $wpdb->query( "TRUNCATE TABLE {$table_name}" ) );
		}
	}
}
