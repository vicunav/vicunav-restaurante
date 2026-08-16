<?php
/**
 * Pruebas de validación y pricing de pizzas con catálogo real.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Catalog\IngredientService;
use Vicu\Restaurante\Catalog\PizzaOptionService;
use Vicu\Restaurante\Installer;
use Vicu\Restaurante\Pizza\PizzaPricingService;
use Vicu\Restaurante\Rest\PizzaQuoteRoute;
use Vicu\Restaurante\Schema;
use Vicu\Restaurante\Settings\RestaurantSettings;

/**
 * Verifica reglas completas del baseline y fallo cerrado.
 */
final class PizzaPricingTest extends WP_UnitTestCase {
	/**
	 * Instala y vacía únicamente el catálogo propio.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( Installer::install() );
		$this->truncate_catalog();
		update_option( AvailabilityRevision::OPTION_NAME, '1', false );
		AvailabilityRevision::clear_cache();
		update_option( RestaurantSettings::OPTION_NAME, array( 'currency' => 'VES' ), false );
		wp_cache_flush();
	}

	/**
	 * Limpia filtros y ajustes propios.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'vicu_restaurante_allow_public_quote' );
		delete_option( RestaurantSettings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Tamaño fija base y solo masa, queso y toppings modifican el precio.
	 *
	 * @return void
	 */
	public function test_quotes_authoritative_integer_breakdown(): void {
		$catalog                      = $this->seed_catalog();
		$configuration                = $this->configuration(
			$catalog,
			array(
				$catalog['basil']['public_id']    => 'whole',
				$catalog['mushroom']['public_id'] => 'left',
			),
			2
		);
		$configuration['total_minor'] = 1;

		$quote = PizzaPricingService::quote( $configuration );

		$this->assertNotWPError( $quote );
		$this->assertSame( 'VES', $quote['currency'] );
		$this->assertSame( 850, $quote['components']['size']['amount_minor'] );
		$this->assertSame( 150, $quote['components']['crust']['amount_minor'] );
		$this->assertSame( 0, $quote['components']['sauce']['amount_minor'] );
		$this->assertSame( 75, $quote['components']['cheese']['amount_minor'] );
		$this->assertSame( 220, $quote['components']['toppings_modifier_minor'] );
		$this->assertSame( 1295, $quote['unit_total_minor'] );
		$this->assertSame( 2, $quote['quantity'] );
		$this->assertSame( 2590, $quote['total_minor'] );
		$this->assertArrayNotHasKey( 'total_minor', $quote['configuration'] );
	}

	/**
	 * Whole, left y right cobran el modificador completo una sola vez.
	 *
	 * @return void
	 */
	public function test_half_zones_have_full_identical_price(): void {
		$catalog = $this->seed_catalog();
		$totals  = array();

		foreach ( array( 'whole', 'left', 'right' ) as $zone ) {
			$quote = PizzaPricingService::quote(
				$this->configuration( $catalog, array( $catalog['basil']['public_id'] => $zone ) )
			);
			$this->assertNotWPError( $quote );
			$totals[] = $quote['unit_total_minor'];
		}

		$this->assertSame( array( 1175, 1175, 1175 ), $totals );
	}

	/**
	 * Seis toppings globales son válidos y el séptimo falla antes de cotizar.
	 *
	 * @return void
	 */
	public function test_enforces_six_global_toppings(): void {
		$catalog  = $this->seed_catalog();
		$toppings = array(
			$catalog['basil']['public_id']    => 'whole',
			$catalog['mushroom']['public_id'] => 'left',
		);

		for ( $index = 3; $index <= 7; $index++ ) {
			$ingredient = IngredientService::create( $this->ingredient_input( 'Topping ' . $index, 'topping', true, 10 ) );
			$this->assertNotWPError( $ingredient );
			$toppings[ $ingredient['public_id'] ] = 0 === $index % 2 ? 'right' : 'whole';
		}

		$catalog['revision'] = AvailabilityRevision::current();
		$six                 = array_slice( $toppings, 0, 6, true );
		$this->assertNotWPError( PizzaPricingService::quote( $this->configuration( $catalog, $six ) ) );

		$seven = PizzaPricingService::quote( $this->configuration( $catalog, $toppings ) );
		$this->assertWPError( $seven );
		$this->assertSame( 'vicu_restaurante_invalid_request', $seven->get_error_code() );
	}

	/**
	 * Estructura, versión, zona y cantidad inválidas fallan cerradas.
	 *
	 * @return void
	 */
	public function test_rejects_malformed_configuration(): void {
		$catalog = $this->seed_catalog();
		$cases   = array();

		$missing = $this->configuration( $catalog );
		unset( $missing['size_id'] );
		$cases[] = $missing;

		$unknown_version            = $this->configuration( $catalog );
		$unknown_version['version'] = 2;
		$cases[]                    = $unknown_version;

		$invalid_zone             = $this->configuration( $catalog );
		$invalid_zone['toppings'] = array( $catalog['basil']['public_id'] => 'both' );
		$cases[]                  = $invalid_zone;

		$fractional             = $this->configuration( $catalog );
		$fractional['quantity'] = 1.5;
		$cases[]                = $fractional;

		foreach ( $cases as $configuration ) {
			$result = PizzaPricingService::quote( $configuration );
			$this->assertWPError( $result );
			$this->assertSame( 'vicu_restaurante_invalid_request', $result->get_error_code() );
		}
	}

	/**
	 * Una revisión antigua se diferencia de una selección agotada o inexistente.
	 *
	 * @return void
	 */
	public function test_rejects_stale_and_unavailable_references(): void {
		$catalog       = $this->seed_catalog();
		$configuration = $this->configuration( $catalog );
		--$configuration['catalog_revision'];

		$stale = PizzaPricingService::quote( $configuration );
		$this->assertWPError( $stale );
		$this->assertSame( 'vicu_restaurante_stale_revision', $stale->get_error_code() );
		$this->assertSame( AvailabilityRevision::current(), $stale->get_error_data()['current_revision'] );

		$unavailable_catalog           = $catalog;
		$unavailable_catalog['cheese'] = IngredientService::update(
			$catalog['cheese']['public_id'],
			1,
			$this->ingredient_input( 'Mozzarella', 'cheese', false, 75 )
		);
		$this->assertNotWPError( $unavailable_catalog['cheese'] );
		$unavailable_catalog['revision'] = AvailabilityRevision::current();

		$unavailable = PizzaPricingService::quote( $this->configuration( $unavailable_catalog ) );
		$this->assertWPError( $unavailable );
		$this->assertSame( 'vicu_restaurante_unavailable', $unavailable->get_error_code() );

		$missing                         = $this->configuration( $unavailable_catalog );
		$missing['cheese_ingredient_id'] = wp_generate_uuid4();
		$not_found                       = PizzaPricingService::quote( $missing );
		$this->assertWPError( $not_found );
		$this->assertSame( 'vicu_restaurante_unavailable', $not_found->get_error_code() );
	}

	/**
	 * La ruta valida schema, evita caché y permite conectar rate limiting.
	 *
	 * @return void
	 */
	public function test_public_quote_route_and_rate_limit_hook(): void {
		$catalog  = $this->seed_catalog();
		$response = $this->dispatch( $this->configuration( $catalog ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] );
		$this->assertTrue( rest_validate_value_from_schema( $response->get_data(), PizzaQuoteRoute::response_schema() ) );

		$invalid_configuration            = $this->configuration( $catalog );
		$invalid_configuration['version'] = 2;
		$invalid                          = $this->dispatch( $invalid_configuration );
		$this->assertSame( 400, $invalid->get_status() );
		$this->assertSame( 'vicu_restaurante_invalid_request', $invalid->get_data()['code'] );

		add_filter( 'vicu_restaurante_allow_public_quote', '__return_false' );
		$limited = $this->dispatch( $this->configuration( $catalog ) );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( 'vicu_restaurante_rate_limited', $limited->get_data()['code'] );
	}

	/**
	 * El ajuste de moneda es propio, estricto y conserva el valor previo ante error.
	 *
	 * @return void
	 */
	public function test_currency_setting_is_strict(): void {
		$this->assertSame( 'VES', RestaurantSettings::currency() );
		$this->assertSame(
			array(
				'currency'                 => 'USD',
				'tax_rate_bps'             => 800,
				'tip_rates_bps'            => array( 0, 1000, 1500, 2000 ),
				'cart_lifetime_hours'      => 72,
				'payment_lifetime_minutes' => 30,
			),
			RestaurantSettings::sanitize( array( 'currency' => ' usd ' ) )
		);
		$this->assertSame(
			array(
				'currency'                 => 'VES',
				'tax_rate_bps'             => 800,
				'tip_rates_bps'            => array( 0, 1000, 1500, 2000 ),
				'cart_lifetime_hours'      => 72,
				'payment_lifetime_minutes' => 30,
			),
			RestaurantSettings::sanitize( array( 'currency' => 'US$' ) )
		);
	}

	/**
	 * Crea el conjunto mínimo para cotizar.
	 *
	 * @return array<string, mixed>
	 */
	private function seed_catalog(): array {
		$catalog = array(
			'size'     => PizzaOptionService::create( $this->option_input( 'Mediana', 'size', true, 850 ) ),
			'crust'    => PizzaOptionService::create( $this->option_input( 'Sin gluten', 'crust', true, 150 ) ),
			'sauce'    => PizzaOptionService::create( $this->option_input( 'Tomate', 'sauce', true, 999 ) ),
			'cheese'   => IngredientService::create( $this->ingredient_input( 'Mozzarella', 'cheese', true, 75 ) ),
			'basil'    => IngredientService::create( $this->ingredient_input( 'Albahaca', 'topping', true, 100 ) ),
			'mushroom' => IngredientService::create( $this->ingredient_input( 'Champiñón', 'topping', true, 120 ) ),
		);

		foreach ( $catalog as $resource ) {
			$this->assertNotWPError( $resource );
		}

		$catalog['revision'] = AvailabilityRevision::current();

		return $catalog;
	}

	/**
	 * Configuración válida contra el catálogo sembrado.
	 *
	 * @param array<string, mixed>  $catalog  Recursos.
	 * @param array<string, string> $toppings Toppings y zonas.
	 * @param int                   $quantity Cantidad.
	 * @return array<string, mixed>
	 */
	private function configuration( array $catalog, array $toppings = array(), int $quantity = 1 ): array {
		return array(
			'version'              => 1,
			'catalog_revision'     => $catalog['revision'],
			'size_id'              => $catalog['size']['public_id'],
			'crust_id'             => $catalog['crust']['public_id'],
			'sauce_id'             => $catalog['sauce']['public_id'],
			'cheese_ingredient_id' => $catalog['cheese']['public_id'],
			'toppings'             => $toppings,
			'quantity'             => $quantity,
		);
	}

	/**
	 * Datos de opción.
	 *
	 * @param string $name  Nombre.
	 * @param string $type  Tipo.
	 * @param bool   $active Disponibilidad.
	 * @param int    $price Importe.
	 * @return array<string, mixed>
	 */
	private function option_input( string $name, string $type, bool $active, int $price ): array {
		return array(
			'name'                 => $name,
			'type'                 => $type,
			'price_modifier_minor' => $price,
			'available'            => $active,
			'display_order'        => 0,
		);
	}

	/**
	 * Datos de ingrediente.
	 *
	 * @param string $name     Nombre.
	 * @param string $category Categoría.
	 * @param bool   $active   Disponibilidad.
	 * @param int    $price    Modificador.
	 * @return array<string, mixed>
	 */
	private function ingredient_input( string $name, string $category, bool $active, int $price ): array {
		return array(
			'name'                 => $name,
			'category'             => $category,
			'price_modifier_minor' => $price,
			'available'            => $active,
			'allergens'            => array(),
			'dietary_tags'         => array(),
		);
	}

	/**
	 * Ejecuta el endpoint con JSON normalizado.
	 *
	 * @param array<string, mixed> $configuration Configuración.
	 * @return WP_REST_Response
	 */
	private function dispatch( array $configuration ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/vicu/v1/restaurante/pizza/quote' );
		$request->set_body_params( array( 'configuration' => $configuration ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Vacía tablas del catálogo en orden seguro.
	 *
	 * @return void
	 */
	private function truncate_catalog(): void {
		global $wpdb;

		foreach ( array( Schema::menu_ingredients_table_name(), Schema::ingredients_table_name(), Schema::pizza_options_table_name() ) as $table_name ) {
			// El identificador pertenece al schema fijo del plugin.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertNotFalse( $wpdb->query( "TRUNCATE TABLE {$table_name}" ) );
		}
	}
}
