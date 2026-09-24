<?php
/**
 * Pruebas del bloque dinámico del constructor de pizzas.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Catalog\IngredientService;
use Vicu\Restaurante\Catalog\PizzaOptionService;
use Vicu\Restaurante\Installer;
use Vicu\Restaurante\Schema;

/** Verifica metadata, fallback, disponibilidad y ausencia de precios del cliente. */
final class PizzaBuilderBlockTest extends WP_UnitTestCase {
	/** Instala y aísla las tablas del catálogo. */
	public function setUp(): void {
		parent::setUp();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( Installer::install() );
		$this->truncate_catalog();
		update_option( AvailabilityRevision::OPTION_NAME, '1', false );
		AvailabilityRevision::clear_cache();
	}

	/** Limpia el catálogo propio. */
	public function tearDown(): void {
		$this->truncate_catalog();
		parent::tearDown();
	}

	/** Registra API 3, render dinámico y módulo de vista condicional. */
	public function test_registers_interactive_dynamic_block(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'vicunav/restaurante-pizza-builder' );

		$this->assertInstanceOf( WP_Block_Type::class, $block );
		$this->assertSame( 3, $block->api_version );
		$this->assertTrue( is_callable( $block->render_callback ) );
		$this->assertTrue( $block->supports['interactivity'] );
		$this->assertFalse( $block->supports['html'] );
		$this->assertNotEmpty( $block->view_script_module_ids );
		$this->assertNotEmpty( $block->style_handles );
	}

	/** Sin selecciones completas muestra un estado explícito y no un formulario roto. */
	public function test_empty_catalog_has_explicit_fallback(): void {
		$output = do_blocks( '<!-- wp:vicunav/restaurante-pizza-builder /-->' );

		$this->assertStringContainsString( 'faltan opciones configuradas', $output );
		$this->assertStringContainsString( 'role="status"', $output );
		$this->assertStringNotContainsString( '<form', $output );
	}

	/** El SSR usa opciones vivas, deshabilita agotados y no publica importes. */
	public function test_server_render_exposes_safe_interactive_configuration(): void {
		$this->seed_catalog();

		$output = do_blocks( '<!-- wp:vicunav/restaurante-pizza-builder /-->' );

		$this->assertStringContainsString( 'data-wp-interactive="vicunav/restaurante-pizza-builder"', $output );
		$this->assertStringContainsString( 'data-wp-init="actions.initialize"', $output );
		$this->assertStringContainsString( 'Toppings (máximo 6)', $output );
		$this->assertStringContainsString( 'Mitad izquierda', $output );
		$this->assertStringContainsString( 'Prosciutto', $output );
		$this->assertMatchesRegularExpression( '/disabled[^>]*>\s*<span>Prosciutto/', $output );
		$this->assertStringContainsString( 'Añadir pizza al carrito', $output );
		$this->assertStringNotContainsString( 'total_minor', $output );
		$this->assertStringNotContainsString( '<h1', $output );
	}

	/** Una cuenta obtiene el guardado nominal sin publicar su colección privada. */
	public function test_authenticated_builder_can_save_current_configuration(): void {
		$this->seed_catalog();
		wp_set_current_user( self::factory()->user->create() );

		$output = do_blocks( '<!-- wp:vicunav/restaurante-pizza-builder /-->' );

		$this->assertStringContainsString( 'Nombre para guardar', $output );
		$this->assertStringContainsString( 'Guardar en mi cuenta', $output );
		$this->assertStringContainsString( 'savedPizzasUrl', $output );
		$this->assertStringNotContainsString( 'saved_pizzas', $output );
		wp_set_current_user( 0 );
	}

	/** Crea el catálogo mínimo con una opción agotada visible. */
	private function seed_catalog(): void {
		foreach ( array(
			'size'  => 850,
			'crust' => 0,
			'sauce' => 0,
		) as $type => $price ) {
			$result = PizzaOptionService::create(
				array(
					'name'                 => ucfirst( $type ),
					'type'                 => $type,
					'price_modifier_minor' => $price,
					'available'            => true,
					'display_order'        => 1,
				)
			);
			$this->assertNotWPError( $result );
		}

		foreach ( array( array( 'Mozzarella', 'cheese', true ), array( 'Albahaca', 'topping', true ), array( 'Prosciutto', 'topping', false ) ) as $ingredient ) {
			$result = IngredientService::create(
				array(
					'name'                 => $ingredient[0],
					'category'             => $ingredient[1],
					'price_modifier_minor' => 0,
					'available'            => $ingredient[2],
					'allergens'            => array(),
					'dietary_tags'         => array(),
				)
			);
			$this->assertNotWPError( $result );
		}
	}

	/** Vacía solo tablas propias del catálogo. */
	private function truncate_catalog(): void {
		global $wpdb;

		foreach ( array( Schema::pizza_options_table_name(), Schema::ingredients_table_name() ) as $table ) {
			// El nombre de tabla proviene del schema fijo del plugin.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$table}" );
		}
	}
}
