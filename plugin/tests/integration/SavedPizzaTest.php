<?php
/**
 * Pruebas de pizzas guardadas y credenciales compartibles.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Catalog\IngredientService;
use Vicu\Restaurante\Catalog\PizzaOptionService;
use Vicu\Restaurante\Installer;
use Vicu\Restaurante\Rest\SavedPizzaRoutes;
use Vicu\Restaurante\SavedPizza\SavedPizzaService;
use Vicu\Restaurante\Schema;

/**
 * Verifica ownership, CAS, versiones, rotación y recotización autoritativa.
 */
final class SavedPizzaTest extends WP_UnitTestCase {
	/** Instala schema y vacía exclusivamente el dominio usado. */
	public function setUp(): void {
		parent::setUp();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( Installer::install() );
		$this->truncate_tables();
		update_option( AvailabilityRevision::OPTION_NAME, '1', false );
		AvailabilityRevision::clear_cache();
		wp_set_current_user( 0 );
		wp_cache_flush();
	}

	/** Retira identidad y filtros compartidos. */
	public function tearDown(): void {
		remove_all_filters( 'vicu_restaurante_allow_shared_pizza' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Una cuenta nunca lista ni muta recursos de otra y CAS evita cambios parciales. */
	public function test_crud_is_owner_scoped_and_revisioned(): void {
		$catalog = $this->seed_catalog();
		$user_a  = self::factory()->user->create();
		$user_b  = self::factory()->user->create();
		$saved   = SavedPizzaService::create( $user_a, ' Favorita ', $this->configuration( $catalog ) );
		$this->assertNotWPError( $saved );
		$this->assertSame( 'Favorita', $saved['name'] );
		$this->assertCount( 1, SavedPizzaService::list_for_user( $user_a ) );
		$this->assertCount( 0, SavedPizzaService::list_for_user( $user_b ) );

		$foreign = SavedPizzaService::update( $user_b, $saved['public_id'], 1, 'Intrusa', null );
		$this->assertWPError( $foreign );
		$this->assertSame( 'vicu_restaurante_not_found', $foreign->get_error_code() );
		$this->assertWPError( SavedPizzaService::delete( $user_b, $saved['public_id'], 1 ) );

		$renamed = SavedPizzaService::update( $user_a, $saved['public_id'], 1, 'Cena', null );
		$this->assertNotWPError( $renamed );
		$this->assertSame( 2, $renamed['revision'] );
		$stale = SavedPizzaService::update( $user_a, $saved['public_id'], 1, 'No aplicada', null );
		$this->assertWPError( $stale );
		$this->assertSame( 'vicu_restaurante_stale_revision', $stale->get_error_code() );
		$this->assertSame( 'Cena', SavedPizzaService::list_for_user( $user_a )[0]['name'] );

		$deleted = SavedPizzaService::delete( $user_a, $saved['public_id'], 2 );
		$this->assertNotWPError( $deleted );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertCount( 0, SavedPizzaService::list_for_user( $user_a ) );
	}

	/** Configuraciones incompletas o de versión desconocida fallan cerradas. */
	public function test_rejects_unknown_or_incomplete_configuration_versions(): void {
		$catalog            = $this->seed_catalog();
		$user               = self::factory()->user->create();
		$unknown            = $this->configuration( $catalog );
		$unknown['version'] = 2;
		$result             = SavedPizzaService::create( $user, 'Desconocida', $unknown );
		$this->assertWPError( $result );
		$this->assertSame( 'vicu_restaurante_invalid_request', $result->get_error_code() );

		$incomplete = $this->configuration( $catalog );
		unset( $incomplete['size_id'] );
		$result = SavedPizzaService::create( $user, 'Incompleta', $incomplete );
		$this->assertWPError( $result );
		$this->assertSame( 'vicu_restaurante_invalid_request', $result->get_error_code() );
		$this->assertSame( 0, $this->count_saved() );
	}

	/** El token rota, no autoriza CRUD y siempre usa precios vigentes del servidor. */
	public function test_shared_token_rotates_and_requotes_current_catalog(): void {
		$catalog = $this->seed_catalog();
		$user    = self::factory()->user->create();
		$saved   = SavedPizzaService::create( $user, 'Secreta', $this->configuration( $catalog ) );
		$shared  = SavedPizzaService::rotate_share( $user, $saved['public_id'], 1 );
		$this->assertNotWPError( $shared );
		$this->assertSame( 43, strlen( $shared['share_token'] ) );
		$this->assertStringNotContainsString( 'Secreta', $shared['share_token'] );
		$first = SavedPizzaService::shared( $shared['share_token'] );
		$this->assertNotWPError( $first );
		$this->assertSame( 1075, $first['authoritative_quote']['total_minor'] );
		$this->assertArrayNotHasKey( 'name', $first );
		$this->assertArrayNotHasKey( 'user_id', $first );

		$updated_size = PizzaOptionService::update( $catalog['size']['public_id'], 1, $this->option_input( 'Mediana', 'size', true, 950 ) );
		$this->assertNotWPError( $updated_size );
		$repriced = SavedPizzaService::shared( $shared['share_token'] );
		$this->assertNotWPError( $repriced );
		$this->assertSame( 1175, $repriced['authoritative_quote']['total_minor'] );
		$this->assertSame( AvailabilityRevision::current(), $repriced['configuration']['catalog_revision'] );

		$rotated = SavedPizzaService::rotate_share( $user, $saved['public_id'], 2 );
		$this->assertNotWPError( $rotated );
		$this->assertNotSame( $shared['share_token'], $rotated['share_token'] );
		$this->assertWPError( SavedPizzaService::shared( $shared['share_token'] ) );
		$this->assertNotWPError( SavedPizzaService::shared( $rotated['share_token'] ) );
		$this->assertWPError( SavedPizzaService::update( 0, $saved['public_id'], 3, 'Token no autoriza', null ) );
	}

	/** REST exige cuenta y nonce, preserva schemas y permite limitar lecturas públicas. */
	public function test_rest_crud_and_shared_read_enforce_authentication(): void {
		$catalog = $this->seed_catalog();
		$user_a  = self::factory()->user->create();
		$user_b  = self::factory()->user->create();
		$missing = $this->dispatch( 'GET', '/vicu/v1/restaurante/saved-pizzas' );
		$this->assertSame( 401, $missing->get_status() );

		wp_set_current_user( $user_a );
		$headers = array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) );
		$created = $this->dispatch(
			'POST',
			'/vicu/v1/restaurante/saved-pizzas',
			array(
				'name'          => 'Cuenta A',
				'configuration' => $this->configuration( $catalog ),
			),
			$headers
		);
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 'no-store, max-age=0', $created->get_headers()['Cache-Control'] );
		$this->assertTrue( rest_validate_value_from_schema( $created->get_data(), SavedPizzaRoutes::item_schema() ) );

		$id      = $created->get_data()['public_id'];
		$updated = $this->dispatch(
			'PATCH',
			'/vicu/v1/restaurante/saved-pizzas/' . $id,
			array(
				'expected_revision' => 1,
				'name'              => 'Renombrada',
			),
			$headers
		);
		$this->assertSame( 200, $updated->get_status() );
		$share = $this->dispatch( 'POST', '/vicu/v1/restaurante/saved-pizzas/' . $id . '/share', array( 'expected_revision' => 2 ), $headers );
		$this->assertSame( 200, $share->get_status() );

		wp_set_current_user( $user_b );
		$foreign = $this->dispatch( 'DELETE', '/vicu/v1/restaurante/saved-pizzas/' . $id, array( 'expected_revision' => 3 ), array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
		$this->assertSame( 404, $foreign->get_status() );

		wp_set_current_user( 0 );
		$shared_route = substr( $share->get_data()['share_path'], strlen( '/wp-json' ) );
		$public       = $this->dispatch( 'GET', $shared_route );
		$this->assertSame( 200, $public->get_status() );
		$this->assertArrayHasKey( 'authoritative_quote', $public->get_data() );
		add_filter( 'vicu_restaurante_allow_shared_pizza', '__return_false' );
		$this->assertSame( 429, $this->dispatch( 'GET', $shared_route )->get_status() );
	}

	/**
	 * Crea un catálogo mínimo cotizable.
	 *
	 * @return array<string, mixed>
	 */
	private function seed_catalog(): array {
		$catalog = array(
			'size'   => PizzaOptionService::create( $this->option_input( 'Mediana', 'size', true, 850 ) ),
			'crust'  => PizzaOptionService::create( $this->option_input( 'Clásica', 'crust', true, 150 ) ),
			'sauce'  => PizzaOptionService::create( $this->option_input( 'Tomate', 'sauce', true, 999 ) ),
			'cheese' => IngredientService::create( $this->ingredient_input( 'Mozzarella', 'cheese', true, 75 ) ),
		);
		foreach ( $catalog as $resource ) {
			$this->assertNotWPError( $resource );
		}
		$catalog['revision'] = AvailabilityRevision::current();
		return $catalog;
	}

	/**
	 * Construye una configuración v1.
	 *
	 * @param array<string, mixed> $catalog Catálogo.
	 * @return array<string, mixed>
	 */
	private function configuration( array $catalog ): array {
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
	 * Construye datos de opción.
	 *
	 * @param string $name      Nombre.
	 * @param string $type      Tipo.
	 * @param bool   $available Disponibilidad.
	 * @param int    $price     Importe.
	 * @return array<string, mixed>
	 */
	private function option_input( string $name, string $type, bool $available, int $price ): array {
		return array(
			'name'                 => $name,
			'type'                 => $type,
			'price_modifier_minor' => $price,
			'available'            => $available,
			'display_order'        => 0,
		);
	}

	/**
	 * Construye datos de ingrediente.
	 *
	 * @param string $name      Nombre.
	 * @param string $category  Categoría.
	 * @param bool   $available Disponibilidad.
	 * @param int    $price     Importe.
	 * @return array<string, mixed>
	 */
	private function ingredient_input( string $name, string $category, bool $available, int $price ): array {
		return array(
			'name'                 => $name,
			'category'             => $category,
			'price_modifier_minor' => $price,
			'available'            => $available,
			'allergens'            => array(),
			'dietary_tags'         => array(),
		);
	}

	/**
	 * Cuenta pizzas persistidas.
	 *
	 * @return int
	 */
	private function count_saved(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::saved_pizzas_table_name() );
	}

	/** Vacía tablas en orden seguro. */
	private function truncate_tables(): void {
		global $wpdb;
		foreach ( array( Schema::saved_pizzas_table_name(), Schema::menu_ingredients_table_name(), Schema::ingredients_table_name(), Schema::pizza_options_table_name() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
	}

	/**
	 * Despacha una solicitud REST en proceso.
	 *
	 * @param string                $method  Método.
	 * @param string                $route   Ruta.
	 * @param array<string, mixed>  $params  Parámetros.
	 * @param array<string, string> $headers Headers.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, string $route, array $params = array(), array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_body_params( $params );
		$request->set_query_params( $params );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return rest_do_request( $request );
	}
}
