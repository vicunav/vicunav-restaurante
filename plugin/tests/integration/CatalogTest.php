<?php
/**
 * Pruebas del catálogo operativo con WordPress y MySQL reales.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Capabilities;
use Vicu\Restaurante\Admin\MenuRelationsAdmin;
use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Catalog\IngredientService;
use Vicu\Restaurante\Catalog\MenuIngredientService;
use Vicu\Restaurante\Catalog\PizzaOptionService;
use Vicu\Restaurante\Installer;
use Vicu\Restaurante\Menu\CatalogRevision;
use Vicu\Restaurante\Menu\MenuItemPostType;
use Vicu\Restaurante\Rest\CatalogRoutes;
use Vicu\Restaurante\Schema;

/**
 * Verifica schema, validación, concurrencia, relaciones y proyecciones públicas.
 */
final class CatalogTest extends WP_UnitTestCase {
	/**
	 * Instala el schema y vacía únicamente tablas propias.
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
		update_option( CatalogRevision::OPTION_NAME, '1', false );
		CatalogRevision::reset_request();
		Capabilities::grant_to_administrator();
		wp_cache_flush();
		wp_set_current_user( 0 );
		$_POST = array();
	}

	/**
	 * Limpia estado compartido sin eliminar el schema que usa el resto de la suite.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		$_POST = array();
		CatalogRevision::reset_request();
		parent::tearDown();
	}

	/**
	 * Las tres tablas son InnoDB, tienen índices contractuales y nacen vacías.
	 *
	 * @return void
	 */
	public function test_schema_is_transactional_indexed_and_empty(): void {
		global $wpdb;

		foreach ( $this->catalog_tables() as $table_name ) {
			$this->assertTrue( Schema::table_exists( $table_name ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					DB_NAME,
					$table_name
				)
			);
			$this->assertSame( 'InnoDB', $engine );

			// El identificador pertenece a una lista fija del schema.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ) );
		}

		$this->assertSame( 1, AvailabilityRevision::current() );
		$this->assertSame( array( 'PRIMARY', 'category_available', 'public_id' ), $this->index_names( Schema::ingredients_table_name() ) );
		$this->assertSame( array( 'PRIMARY', 'ingredient_id', 'substitution_ingredient_id' ), $this->index_names( Schema::menu_ingredients_table_name() ) );
		$this->assertSame( array( 'PRIMARY', 'public_id', 'type_available_order' ), $this->index_names( Schema::pizza_options_table_name() ) );
	}

	/**
	 * Cada escritura válida incrementa una sola vez revisiones de fila y catálogo.
	 *
	 * @return void
	 */
	public function test_ingredient_compare_and_swap_is_atomic(): void {
		$created = IngredientService::create( $this->ingredient_input( 'Mozzarella', 'cheese', true ) );
		$this->assertNotWPError( $created );
		$this->assertSame( 1, $created['revision'] );
		$this->assertSame( 2, AvailabilityRevision::current() );

		$updated = IngredientService::update(
			$created['public_id'],
			1,
			$this->ingredient_input( 'Mozzarella fior di latte', 'cheese', false, 125 )
		);
		$this->assertNotWPError( $updated );
		$this->assertSame( 2, $updated['revision'] );
		$this->assertFalse( $updated['available'] );
		$this->assertSame( 3, AvailabilityRevision::current() );

		$stale = IngredientService::update(
			$created['public_id'],
			1,
			$this->ingredient_input( 'Escritura perdida', 'cheese', true )
		);
		$this->assertWPError( $stale );
		$this->assertSame( 'vicu_restaurante_stale_revision', $stale->get_error_code() );
		$this->assertSame( 409, $stale->get_error_data()['status'] );
		$this->assertSame( 2, $stale->get_error_data()['current_revision'] );
		$this->assertSame( 3, AvailabilityRevision::current() );
		$this->assertSame( 'Mozzarella fior di latte', IngredientService::find( $created['public_id'] )['name'] );
	}

	/**
	 * Opciones comparten el contrato CAS y fallan cerradas ante vocabularios libres.
	 *
	 * @return void
	 */
	public function test_option_compare_and_swap_and_validators(): void {
		$option = PizzaOptionService::create( $this->option_input( 'Mediana', 'size', true, 2, 300 ) );
		$this->assertNotWPError( $option );
		$this->assertSame( 2, AvailabilityRevision::current() );

		$updated = PizzaOptionService::update(
			$option['public_id'],
			1,
			$this->option_input( 'Mediana', 'size', false, 3, 350 )
		);
		$this->assertNotWPError( $updated );
		$this->assertSame( 2, $updated['revision'] );
		$this->assertSame( 3, AvailabilityRevision::current() );

		$stale = PizzaOptionService::update( $option['public_id'], 1, $this->option_input( 'Grande', 'size', true ) );
		$this->assertWPError( $stale );
		$this->assertSame( 'vicu_restaurante_stale_revision', $stale->get_error_code() );
		$this->assertSame( 3, AvailabilityRevision::current() );

		$invalid_option = PizzaOptionService::create( $this->option_input( 'Libre', 'inventado', true ) );
		$this->assertWPError( $invalid_option );
		$this->assertSame( 'vicu_restaurante_invalid_request', $invalid_option->get_error_code() );

		$invalid_ingredient = IngredientService::create(
			array_merge(
				$this->ingredient_input( 'Secreto', 'topping', true ),
				array( 'allergens' => array( 'sin-control' ) )
			)
		);
		$this->assertWPError( $invalid_ingredient );
		$this->assertSame( 3, AvailabilityRevision::current() );
	}

	/**
	 * Las relaciones se reemplazan completas y nunca dejan una escritura parcial.
	 *
	 * @return void
	 */
	public function test_menu_relations_are_explicit_and_transactional(): void {
		$tomato  = IngredientService::create( $this->ingredient_input( 'Tomate', 'base', true ) );
		$cheese  = IngredientService::create( $this->ingredient_input( 'Mozzarella', 'cheese', true ) );
		$vegan   = IngredientService::create( $this->ingredient_input( 'Queso vegetal', 'cheese', true ) );
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => MenuItemPostType::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Pizza configurable',
			)
		);

		$result = MenuIngredientService::replace(
			$post_id,
			array(
				array(
					'ingredient_public_id'   => $tomato['public_id'],
					'role'                   => 'required',
					'display_order'          => 1,
					'substitution_public_id' => '',
				),
				array(
					'ingredient_public_id'   => $cheese['public_id'],
					'role'                   => 'removable',
					'display_order'          => 2,
					'substitution_public_id' => $vegan['public_id'],
				),
			)
		);
		$this->assertTrue( $result );
		$this->assertSame( 2, (int) get_option( CatalogRevision::OPTION_NAME ) );

		$relations = MenuIngredientService::for_menu_item( $post_id );
		$this->assertCount( 2, $relations );
		$this->assertSame( 'required', $relations[0]['role'] );
		$this->assertSame( $vegan['public_id'], $relations[1]['substitution_public_id'] );

		$invalid = MenuIngredientService::replace(
			$post_id,
			array(
				array(
					'ingredient_public_id'   => $tomato['public_id'],
					'role'                   => 'required',
					'display_order'          => 1,
					'substitution_public_id' => '',
				),
				array(
					'ingredient_public_id'   => wp_generate_uuid4(),
					'role'                   => 'optional',
					'display_order'          => 2,
					'substitution_public_id' => '',
				),
			)
		);
		$this->assertWPError( $invalid );
		$this->assertSame( 'vicu_restaurante_invalid_request', $invalid->get_error_code() );
		$this->assertSame( $relations, MenuIngredientService::for_menu_item( $post_id ) );
		$this->assertSame( 2, (int) get_option( CatalogRevision::OPTION_NAME ) );
	}

	/**
	 * Las lecturas públicas exponen schemas válidos, no ocultan agotados y revalidan.
	 *
	 * @return void
	 */
	public function test_public_catalog_routes_validate_and_revalidate(): void {
		$cheese  = IngredientService::create( $this->ingredient_input( 'Mozzarella', 'cheese', false, 100 ) );
		$topping = IngredientService::create( $this->ingredient_input( 'Albahaca', 'topping', true ) );
		PizzaOptionService::create( $this->option_input( 'Mediana', 'size', true, 1, 300 ) );
		PizzaOptionService::create( $this->option_input( 'Fina', 'crust', true, 1 ) );
		PizzaOptionService::create( $this->option_input( 'Tomate', 'sauce', false, 1 ) );

		$availability = $this->dispatch( '/vicu/v1/restaurante/ingredients/availability' );
		$this->assertSame( 200, $availability->get_status() );
		$this->assertSame( 'no-cache, max-age=0, must-revalidate', $availability->get_headers()['Cache-Control'] );
		$this->assertCount( 2, $availability->get_data()['ingredients'] );
		$this->assertFalse( $availability->get_data()['ingredients'][0]['available'] );
		$this->assertTrue( rest_validate_value_from_schema( $availability->get_data(), CatalogRoutes::availability_schema() ) );

		$options = $this->dispatch( '/vicu/v1/restaurante/pizza/options' );
		$this->assertSame( 200, $options->get_status() );
		$this->assertSame( 'public, max-age=60, stale-while-revalidate=300', $options->get_headers()['Cache-Control'] );
		$this->assertCount( 1, $options->get_data()['sizes'] );
		$this->assertCount( 1, $options->get_data()['crusts'] );
		$this->assertCount( 1, $options->get_data()['sauces'] );
		$this->assertCount( 1, $options->get_data()['cheeses'] );
		$this->assertCount( 1, $options->get_data()['toppings'] );
		$this->assertFalse( $options->get_data()['sauces'][0]['available'] );
		$this->assertTrue( rest_validate_value_from_schema( $options->get_data(), CatalogRoutes::pizza_options_schema() ) );

		$conditional_request = new WP_REST_Request( 'GET', '/vicu/v1/restaurante/pizza/options' );
		$conditional_request->set_header( 'If-None-Match', $options->get_headers()['ETag'] );
		$not_modified = rest_get_server()->dispatch( $conditional_request );
		$this->assertSame( 304, $not_modified->get_status() );
		$this->assertNull( $not_modified->get_data() );

		$changed = IngredientService::update(
			$topping['public_id'],
			1,
			$this->ingredient_input( 'Albahaca', 'topping', false )
		);
		$this->assertNotWPError( $changed );
		$refreshed = $this->dispatch( '/vicu/v1/restaurante/pizza/options' );
		$this->assertNotSame( $options->get_headers()['ETag'], $refreshed->get_headers()['ETag'] );
		$this->assertSame( $options->get_data()['revision'] + 1, $refreshed->get_data()['revision'] );
		$this->assertSame( $cheese['public_id'], $refreshed->get_data()['cheeses'][0]['public_id'] );
	}

	/**
	 * Las capabilities operativas no se conceden al rol editorial.
	 *
	 * @return void
	 */
	public function test_catalog_capabilities_remain_separated(): void {
		$administrator = get_role( 'administrator' );
		$editor        = get_role( 'editor' );

		$this->assertTrue( $administrator->has_cap( 'manage_vicu_restaurant_catalog' ) );
		$this->assertTrue( $administrator->has_cap( 'manage_vicu_restaurant_availability' ) );
		$this->assertFalse( $editor->has_cap( 'manage_vicu_restaurant_catalog' ) );
		$this->assertFalse( $editor->has_cap( 'manage_vicu_restaurant_availability' ) );
	}

	/**
	 * El metabox no persiste relaciones sin capability y nonce válidos.
	 *
	 * @return void
	 */
	public function test_relation_admin_requires_capability_and_nonce(): void {
		$ingredient = IngredientService::create( $this->ingredient_input( 'Tomate', 'base', true ) );
		$post_id    = self::factory()->post->create(
			array(
				'post_type'   => MenuItemPostType::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Marinara',
			)
		);
		$post       = get_post( $post_id );
		$editor_id  = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $editor_id );
		$_POST = $this->relation_form( $ingredient['public_id'], wp_create_nonce( 'vicu_restaurante_save_menu_relations' ) );
		MenuRelationsAdmin::save( $post_id, $post );
		$this->assertSame( array(), MenuIngredientService::for_menu_item( $post_id ) );

		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		$_POST = $this->relation_form( $ingredient['public_id'], '' );
		MenuRelationsAdmin::save( $post_id, $post );
		$this->assertSame( array(), MenuIngredientService::for_menu_item( $post_id ) );

		$_POST = $this->relation_form( $ingredient['public_id'], wp_create_nonce( 'vicu_restaurante_save_menu_relations' ) );
		MenuRelationsAdmin::save( $post_id, $post );
		$this->assertCount( 1, MenuIngredientService::for_menu_item( $post_id ) );
	}

	/**
	 * Payload válido de ingrediente.
	 *
	 * @param string $name      Nombre.
	 * @param string $category  Categoría contractual.
	 * @param bool   $available Disponibilidad.
	 * @param int    $price     Modificador en minor units.
	 * @return array<string, mixed>
	 */
	private function ingredient_input( string $name, string $category, bool $available, int $price = 0 ): array {
		return array(
			'name'                 => $name,
			'category'             => $category,
			'price_modifier_minor' => $price,
			'available'            => $available,
			'allergens'            => 'cheese' === $category ? array( 'milk' ) : array(),
			'dietary_tags'         => array( 'vegetarian' ),
		);
	}

	/**
	 * Payload válido de opción.
	 *
	 * @param string $name      Nombre.
	 * @param string $type      Tipo contractual.
	 * @param bool   $available Disponibilidad.
	 * @param int    $order     Orden.
	 * @param int    $price     Modificador en minor units.
	 * @return array<string, mixed>
	 */
	private function option_input( string $name, string $type, bool $available, int $order = 0, int $price = 0 ): array {
		return array(
			'name'                 => $name,
			'type'                 => $type,
			'price_modifier_minor' => $price,
			'available'            => $available,
			'display_order'        => $order,
		);
	}

	/**
	 * Construye un formulario de relación para las pruebas administrativas.
	 *
	 * @param string $public_id UUID del ingrediente.
	 * @param string $nonce     Nonce candidato.
	 * @return array<string, mixed>
	 */
	private function relation_form( string $public_id, string $nonce ): array {
		return array(
			'vicu_restaurante_menu_relations_nonce' => $nonce,
			'vicu_rest_ingredient_relations'        => array(
				$public_id => array(
					'role'                   => 'required',
					'display_order'          => '1',
					'substitution_public_id' => '',
				),
			),
		);
	}

	/**
	 * Despacha una lectura contra el servidor REST real.
	 *
	 * @param string $route Ruta pública.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $route ): WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );
	}

	/**
	 * Devuelve tablas propias en orden de dependencia.
	 *
	 * @return string[]
	 */
	private function catalog_tables(): array {
		return array(
			Schema::menu_ingredients_table_name(),
			Schema::ingredients_table_name(),
			Schema::pizza_options_table_name(),
		);
	}

	/**
	 * Vacía datos operativos entre casos.
	 *
	 * @return void
	 */
	private function truncate_catalog(): void {
		global $wpdb;

		foreach ( $this->catalog_tables() as $table_name ) {
			// El identificador pertenece a una lista fija del schema.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertNotFalse( $wpdb->query( "TRUNCATE TABLE {$table_name}" ) );
		}
	}

	/**
	 * Lista nombres de índices sin repetir columnas.
	 *
	 * @param string $table_name Tabla propia.
	 * @return string[]
	 */
	private function index_names( string $table_name ): array {
		global $wpdb;

		// El identificador pertenece a una lista fija del schema.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A );
		$names = array_values( array_unique( wp_list_pluck( $rows, 'Key_name' ) ) );

		sort( $names, SORT_STRING );

		return $names;
	}
}
