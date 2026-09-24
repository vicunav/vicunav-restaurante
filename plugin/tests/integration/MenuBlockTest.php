<?php
/**
 * Pruebas del bloque dinámico de menú.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Menu\CatalogRevision;
use Vicu\Restaurante\Menu\MenuCategory;
use Vicu\Restaurante\Menu\MenuItemPostType;
use Vicu\Restaurante\Menu\MenuMeta;

/**
 * Verifica metadata, render de servidor, disponibilidad y assets condicionales.
 */
final class MenuBlockTest extends WP_UnitTestCase {
	/** Prepara catálogo vacío y revisión aislada. */
	public function setUp(): void {
		parent::setUp();
		MenuCategory::register();
		MenuMeta::register_meta();
		update_option( CatalogRevision::OPTION_NAME, '1', false );
		CatalogRevision::reset_request();
		wp_cache_flush();
	}

	/** Limpia caches de revisión entre casos. */
	public function tearDown(): void {
		CatalogRevision::reset_request();
		parent::tearDown();
	}

	/** La metadata registra un bloque API 3, dinámico y con assets separados. */
	public function test_registers_dynamic_block_from_metadata(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'vicunav/restaurante-menu' );

		$this->assertInstanceOf( WP_Block_Type::class, $block );
		$this->assertSame( 3, $block->api_version );
		$this->assertTrue( is_callable( $block->render_callback ) );
		$this->assertNotEmpty( $block->editor_script_handles );
		$this->assertNotEmpty( $block->view_script_handles );
		$this->assertNotEmpty( $block->style_handles );
		$this->assertFalse( $block->supports['html'] );
	}

	/** El render usa catálogo real, un solo nivel H3 y disponibilidad textual. */
	public function test_server_render_exposes_filters_and_availability(): void {
		$category_id = $this->create_category();
		$this->create_item( 'Margherita <script>', $category_id, true );
		$this->create_item( 'Diavola', $category_id, false );

		$output = do_blocks( '<!-- wp:vicunav/restaurante-menu /-->' );

		$this->assertStringContainsString( 'data-vicu-menu-root', $output );
		$this->assertStringContainsString( 'data-menu-search', $output );
		$this->assertStringContainsString( 'data-menu-category="pizzas"', $output );
		$this->assertStringContainsString( 'Margherita', $output );
		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( 'is-unavailable', $output );
		$this->assertStringContainsString( 'Agotado', $output );
		$this->assertStringContainsString( 'Alérgenos:', $output );
		$this->assertSame( 2, substr_count( $output, '<h3>' ) );
		$this->assertStringNotContainsString( '<h1', $output );
		$this->assertStringNotContainsString( 'data-user', $output );
	}

	/** Un catálogo vacío conserva controles y comunica el estado sin falsos errores. */
	public function test_empty_catalog_has_explicit_fallback(): void {
		$output = do_blocks( '<!-- wp:vicunav/restaurante-menu /-->' );

		$this->assertStringContainsString( 'El menú todavía no tiene platos publicados.', $output );
		$this->assertStringContainsString( 'data-menu-empty', $output );
		$this->assertStringContainsString( 'data-menu-error hidden', $output );
		$this->assertStringContainsString( '<ul', $output );
		$this->assertStringNotContainsString( '<li', $output );
	}

	/** WordPress encola assets de vista únicamente al renderizar el bloque. */
	public function test_frontend_assets_are_conditional(): void {
		$block        = WP_Block_Type_Registry::get_instance()->get_registered( 'vicunav/restaurante-menu' );
		$view_handle  = $block->view_script_handles[0];
		$style_handle = $block->style_handles[0];

		wp_dequeue_script( $view_handle );
		wp_dequeue_style( $style_handle );
		$this->assertFalse( wp_script_is( $view_handle, 'enqueued' ) );
		$this->assertFalse( wp_style_is( $style_handle, 'enqueued' ) );

		do_blocks( '<!-- wp:paragraph --><p>Sin menú.</p><!-- /wp:paragraph -->' );
		$this->assertFalse( wp_script_is( $view_handle, 'enqueued' ) );
		$this->assertFalse( wp_style_is( $style_handle, 'enqueued' ) );

		do_blocks( '<!-- wp:vicunav/restaurante-menu /-->' );
		$this->assertTrue( wp_script_is( $view_handle, 'enqueued' ) );
		$this->assertTrue( wp_style_is( $style_handle, 'enqueued' ) );
	}

	/** Crea una categoría visible del menú. */
	private function create_category(): int {
		$result = wp_insert_term( 'Pizzas', MenuCategory::TAXONOMY, array( 'slug' => 'pizzas' ) );
		$this->assertNotWPError( $result );
		$term_id = (int) $result['term_id'];
		update_term_meta( $term_id, MenuCategory::META_ORDER, 1 );
		update_term_meta( $term_id, MenuCategory::META_VISIBLE, true );

		return $term_id;
	}

	/**
	 * Crea un item contractual.
	 *
	 * @param string $title       Nombre.
	 * @param int    $category_id Categoría.
	 * @param bool   $available   Disponibilidad.
	 */
	private function create_item( string $title, int $category_id, bool $available ): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => MenuItemPostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => 'Descripción breve.',
				'post_content' => 'Historia editorial.',
			)
		);
		wp_set_object_terms( $post_id, array( $category_id ), MenuCategory::TAXONOMY );
		update_post_meta( $post_id, MenuMeta::PRICE_MINOR, 1250 );
		update_post_meta( $post_id, MenuMeta::CURRENCY, 'USD' );
		update_post_meta( $post_id, MenuMeta::AVAILABLE, $available );
		update_post_meta( $post_id, MenuMeta::CALORIES_KCAL, 720 );
		update_post_meta( $post_id, MenuMeta::ALLERGENS, array( 'gluten', 'milk' ) );
		update_post_meta( $post_id, MenuMeta::DIETARY_TAGS, array( 'vegetarian' ) );
	}
}
