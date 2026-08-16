<?php
/**
 * Pruebas del catálogo con WordPress real.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Admin\MenuAdmin;
use Vicu\Restaurante\Capabilities;
use Vicu\Restaurante\Menu\CatalogRevision;
use Vicu\Restaurante\Menu\MenuCategory;
use Vicu\Restaurante\Menu\MenuItemPostType;
use Vicu\Restaurante\Menu\MenuMeta;
use Vicu\Restaurante\Rest\MenuRoutes;

/**
 * Verifica registro, administración, proyección REST y caché.
 */
final class MenuCatalogTest extends WP_UnitTestCase {
	/**
	 * Aísla revisión, usuario y formularios por caso.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		MenuCategory::register();
		MenuMeta::register_meta();
		Capabilities::grant_to_administrator();
		update_option( CatalogRevision::OPTION_NAME, '1', false );
		CatalogRevision::reset_request();
		wp_set_current_user( 0 );
		$_POST = array();
	}

	/**
	 * Limpia globals editados por los casos administrativos.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$_POST = array();
		wp_set_current_user( 0 );
		CatalogRevision::reset_request();
		parent::tearDown();
	}

	/**
	 * El CPT, taxonomía y meta quedan bajo la capability exclusiva.
	 *
	 * @return void
	 */
	public function test_registers_menu_contract_and_permissions(): void {
		$post_type = get_post_type_object( MenuItemPostType::POST_TYPE );
		$taxonomy  = get_taxonomy( MenuCategory::TAXONOMY );

		$this->assertNotNull( $post_type );
		$this->assertTrue( $post_type->public );
		$this->assertTrue( $post_type->show_in_rest );
		$this->assertSame( 'vicunav', $post_type->show_in_menu );
		$this->assertSame( 'manage_vicu_restaurant_catalog', $post_type->cap->edit_posts );
		$this->assertNotNull( $taxonomy );
		$this->assertTrue( $taxonomy->hierarchical );
		$this->assertFalse( $taxonomy->show_in_rest );
		$this->assertSame( 'manage_vicu_restaurant_catalog', $taxonomy->cap->manage_terms );

		$registered_meta = get_registered_meta_keys( 'post', MenuItemPostType::POST_TYPE );

		foreach ( MenuMeta::all_keys() as $meta_key ) {
			$this->assertArrayHasKey( $meta_key, $registered_meta );
			$this->assertFalse( $registered_meta[ $meta_key ]['show_in_rest'] );
		}

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );
		$this->assertFalse( current_user_can( 'manage_vicu_restaurant_catalog' ) );
		$generic_response = $this->dispatch( '/wp/v2/restaurant-menu-items' );
		$this->assertSame( 403, $generic_response->get_status() );
		$this->assertSame( 'vicu_restaurante_forbidden', $generic_response->get_data()['code'] );

		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		$this->assertSame( 200, $this->dispatch( '/wp/v2/restaurant-menu-items' )->get_status() );

		$columns = MenuAdmin::columns( array( 'title' => 'Título' ) );
		$this->assertArrayHasKey( 'vicu_rest_price', $columns );
		$this->assertArrayHasKey( 'vicu_rest_available', $columns );
	}

	/**
	 * Sanitización falla cerrada para moneda, importes y vocabularios.
	 *
	 * @return void
	 */
	public function test_sanitizes_operational_fields(): void {
		$this->assertSame( 0, MenuMeta::sanitize_non_negative_int( -25 ) );
		$this->assertSame( 1250, MenuMeta::sanitize_non_negative_int( '1250' ) );
		$this->assertSame( 'USD', MenuMeta::sanitize_currency( ' usd ' ) );
		$this->assertSame( '', MenuMeta::sanitize_currency( 'US$' ) );
		$this->assertSame( array( 'gluten', 'milk' ), MenuMeta::sanitize_allergens( array( 'milk', 'inventado', 'gluten', 'milk' ) ) );
		$this->assertSame( array( 'spicy', 'vegan' ), MenuMeta::sanitize_dietary_tags( array( 'vegan', 'spicy', 'free-text' ) ) );
		$this->assertSame( '', MenuMeta::sanitize_public_id( '7' ) );
	}

	/**
	 * El panel exige nonce y capability antes de persistir.
	 *
	 * @return void
	 */
	public function test_admin_meta_box_requires_nonce_and_capability(): void {
		$post_id   = self::factory()->post->create( array( 'post_type' => MenuItemPostType::POST_TYPE ) );
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $editor_id );
		$_POST = $this->valid_meta_form( wp_create_nonce( 'vicu_restaurante_save_menu_item' ) );
		MenuMeta::save_meta_box( $post_id, get_post( $post_id ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, MenuMeta::CURRENCY ) );

		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		$_POST = $this->valid_meta_form( wp_create_nonce( 'vicu_restaurante_save_menu_item' ) );
		MenuMeta::save_meta_box( $post_id, get_post( $post_id ) );

		$this->assertSame( 1250, (int) get_post_meta( $post_id, MenuMeta::PRICE_MINOR, true ) );
		$this->assertSame( 'USD', get_post_meta( $post_id, MenuMeta::CURRENCY, true ) );
		$this->assertTrue( rest_sanitize_boolean( get_post_meta( $post_id, MenuMeta::AVAILABLE, true ) ) );
		$this->assertSame( array( 'gluten', 'milk' ), get_post_meta( $post_id, MenuMeta::ALLERGENS, true ) );
	}

	/**
	 * Los campos de categoría también exigen nonce y capability.
	 *
	 * @return void
	 */
	public function test_category_fields_require_nonce_and_capability(): void {
		$term_id   = $this->create_category( 'Pizzas', 'pizzas', false, 0 );
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $editor_id );
		$_POST = array(
			'vicu_restaurante_menu_category_nonce' => wp_create_nonce( 'vicu_restaurante_save_menu_category' ),
			'vicu_rest_menu_category_order'        => '8',
			'vicu_rest_menu_category_visible'      => '1',
		);
		MenuCategory::save_fields( $term_id );
		$this->assertSame( '0', get_term_meta( $term_id, MenuCategory::META_ORDER, true ) );
		$this->assertFalse( rest_sanitize_boolean( get_term_meta( $term_id, MenuCategory::META_VISIBLE, true ) ) );

		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		$_POST['vicu_restaurante_menu_category_nonce'] = wp_create_nonce( 'vicu_restaurante_save_menu_category' );
		MenuCategory::save_fields( $term_id );
		$this->assertSame( '8', get_term_meta( $term_id, MenuCategory::META_ORDER, true ) );
		$this->assertTrue( rest_sanitize_boolean( get_term_meta( $term_id, MenuCategory::META_VISIBLE, true ) ) );
	}

	/**
	 * La colección expone solo items completos de categorías visibles.
	 *
	 * @return void
	 */
	public function test_public_routes_filter_hidden_and_incomplete_content(): void {
		$visible_category = $this->create_category( 'Pizzas', 'pizzas', true, 2 );
		$hidden_category  = $this->create_category( 'Interno', 'interno', false, 1 );
		$public_item      = $this->create_item( 'Margherita', $visible_category, true );

		$this->create_item( 'Borrador', $visible_category, true, 'draft' );
		$this->create_item( 'Oculto', $hidden_category, true );
		$this->create_item( 'Incompleto', $visible_category, true, 'publish', false );

		$response = $this->dispatch( '/vicu/v1/restaurante/menu' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'public, max-age=60, stale-while-revalidate=300', $response->get_headers()['Cache-Control'] );
		$this->assertNotEmpty( $response->get_headers()['ETag'] );
		$this->assertCount( 1, $data['categories'] );
		$this->assertSame( 'pizzas', $data['categories'][0]['slug'] );
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( 'Margherita', $data['items'][0]['name'] );
		$this->assertSame( 'USD', $data['items'][0]['currency'] );
		$this->assertSame( 1250, $data['items'][0]['price_minor'] );
		$this->assertArrayNotHasKey( 'post_id', $data['items'][0] );
		$this->assertTrue( wp_is_uuid( $data['items'][0]['public_id'], 4 ) );
		$this->assertTrue( rest_validate_value_from_schema( $data, MenuRoutes::get_collection_schema() ) );

		$filter = $this->dispatch( '/vicu/v1/restaurante/menu?category=pizzas' );
		$this->assertSame( 200, $filter->get_status() );
		$this->assertCount( 1, $filter->get_data()['items'] );

		$invalid_filter = $this->dispatch( '/vicu/v1/restaurante/menu?category=no-existe' );
		$this->assertSame( 400, $invalid_filter->get_status() );
		$this->assertSame( 'vicu_restaurante_invalid_request', $invalid_filter->get_data()['code'] );

		$public_id = get_post_meta( $public_item, MenuMeta::PUBLIC_ID, true );
		$detail    = $this->dispatch( '/vicu/v1/restaurante/menu/' . $public_id );
		$this->assertSame( 200, $detail->get_status() );
		$this->assertSame( $public_id, $detail->get_data()['item']['public_id'] );
		$this->assertTrue( rest_validate_value_from_schema( $detail->get_data(), MenuRoutes::get_item_schema() ) );

		$missing = $this->dispatch( '/vicu/v1/restaurante/menu/' . wp_generate_uuid4() );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'vicu_restaurante_not_found', $missing->get_data()['code'] );
	}

	/**
	 * Un ETag vigente devuelve 304 y una escritura relevante lo invalida.
	 *
	 * @return void
	 */
	public function test_etag_and_revision_change_after_catalog_write(): void {
		$category_id = $this->create_category( 'Pizzas', 'pizzas', true, 0 );
		$post_id     = $this->create_item( 'Marinara', $category_id, false );
		$first       = $this->dispatch( '/vicu/v1/restaurante/menu' );
		$first_etag  = $first->get_headers()['ETag'];

		$conditional = new WP_REST_Request( 'GET', '/vicu/v1/restaurante/menu' );
		$conditional->set_header( 'If-None-Match', $first_etag );
		$not_modified = rest_get_server()->dispatch( $conditional );

		$this->assertSame( 304, $not_modified->get_status() );
		$this->assertNull( $not_modified->get_data() );

		$first_revision = $first->get_data()['revision'];
		CatalogRevision::reset_request();
		update_post_meta( $post_id, MenuMeta::PRICE_MINOR, 1500 );

		$second = $this->dispatch( '/vicu/v1/restaurante/menu' );
		$this->assertSame( $first_revision + 1, $second->get_data()['revision'] );
		$this->assertNotSame( $first_etag, $second->get_headers()['ETag'] );
		$this->assertSame( 1500, $second->get_data()['items'][0]['price_minor'] );
		$this->assertFalse( $second->get_data()['items'][0]['available'] );
	}

	/**
	 * Crea una categoría sin depender del formulario administrativo.
	 *
	 * @param string $name    Nombre.
	 * @param string $slug    Slug.
	 * @param bool   $visible Visibilidad.
	 * @param int    $order   Orden.
	 * @return int
	 */
	private function create_category( string $name, string $slug, bool $visible, int $order ): int {
		$result = wp_insert_term( $name, MenuCategory::TAXONOMY, array( 'slug' => $slug ) );
		$this->assertNotWPError( $result );
		$term_id = (int) $result['term_id'];
		update_term_meta( $term_id, MenuCategory::META_ORDER, $order );
		update_term_meta( $term_id, MenuCategory::META_VISIBLE, $visible );

		return $term_id;
	}

	/**
	 * Crea un item contractual o deliberadamente incompleto.
	 *
	 * @param string $title       Nombre.
	 * @param int    $category_id Categoría.
	 * @param bool   $available   Disponibilidad.
	 * @param string $status      Estado WordPress.
	 * @param bool   $complete    Si añade meta requerido.
	 * @return int
	 */
	private function create_item( string $title, int $category_id, bool $available, string $status = 'publish', bool $complete = true ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => MenuItemPostType::POST_TYPE,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_excerpt' => 'Descripción breve.',
				'post_content' => 'Historia editorial.',
				'menu_order'   => 3,
			)
		);

		wp_set_object_terms( $post_id, array( $category_id ), MenuCategory::TAXONOMY );

		if ( $complete ) {
			update_post_meta( $post_id, MenuMeta::PRICE_MINOR, 1250 );
			update_post_meta( $post_id, MenuMeta::CURRENCY, 'USD' );
			update_post_meta( $post_id, MenuMeta::AVAILABLE, $available );
			update_post_meta( $post_id, MenuMeta::CALORIES_KCAL, 720 );
			update_post_meta( $post_id, MenuMeta::ALLERGENS, array( 'gluten', 'milk' ) );
			update_post_meta( $post_id, MenuMeta::DIETARY_TAGS, array( 'vegetarian' ) );
		}

		return $post_id;
	}

	/**
	 * Despacha una lectura contra el servidor REST real.
	 *
	 * @param string $route Ruta con query opcional.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $route ): WP_REST_Response {
		$parts   = wp_parse_url( $route );
		$request = new WP_REST_Request( 'GET', $parts['path'] );

		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $params );
			$request->set_query_params( $params );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Payload administrativo válido.
	 *
	 * @param string $nonce Nonce del usuario actual.
	 * @return array<string, mixed>
	 */
	private function valid_meta_form( string $nonce ): array {
		return array(
			'vicu_restaurante_menu_item_nonce' => $nonce,
			'vicu_rest_price_minor'            => '1250',
			'vicu_rest_currency'               => 'usd',
			'vicu_rest_available'              => '1',
			'vicu_rest_calories_kcal'          => '720',
			'vicu_rest_allergens'              => array( 'milk', 'gluten', 'inventado' ),
			'vicu_rest_dietary_tags'           => array( 'vegetarian' ),
		);
	}
}
