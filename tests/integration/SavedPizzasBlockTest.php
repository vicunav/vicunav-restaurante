<?php
/**
 * Pruebas estructurales del bloque de pizzas guardadas.
 *
 * @package Vicunav_Restaurante
 */

/** Verifica metadata, estados SSR, privacidad y assets condicionales. */
final class SavedPizzasBlockTest extends WP_UnitTestCase {
	/** Aísla identidad y colas. */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
		wp_script_modules()->dequeue( 'vicunav-restaurante-saved-pizzas-view-script-module' );
		wp_dequeue_style( 'vicunav-restaurante-saved-pizzas-style' );
	}

	/** Retira la identidad de la prueba. */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Registra API 3, render dinámico, módulo y estilos propios. */
	public function test_registers_interactive_dynamic_block(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'vicunav/restaurante-saved-pizzas' );

		$this->assertInstanceOf( WP_Block_Type::class, $block );
		$this->assertSame( 3, $block->api_version );
		$this->assertTrue( is_callable( $block->render_callback ) );
		$this->assertTrue( $block->supports['interactivity'] );
		$this->assertFalse( $block->supports['html'] );
		$this->assertNotEmpty( $block->view_script_module_ids );
		$this->assertNotEmpty( $block->style_handles );
	}

	/** Un visitante recibe un estado seguro sin nonce ni lectura privada embebida. */
	public function test_anonymous_server_render_requires_login(): void {
		$output = do_blocks( '<!-- wp:vicunav/restaurante-saved-pizzas /-->' );

		$this->assertStringContainsString( 'Inicia sesión para ver y gestionar', $output );
		$this->assertStringContainsString( 'data-authenticated="0"', $output );
		$this->assertStringNotContainsString( 'data-rest-nonce=', $output );
		$this->assertStringNotContainsString( 'public_id', $output );
		$this->assertStringNotContainsString( 'share_token', $output );
		$this->assertStringNotContainsString( '<h1', $output );
	}

	/** Una cuenta obtiene regiones vacías; los recursos continúan solo en REST. */
	public function test_authenticated_server_render_stays_private(): void {
		wp_set_current_user( self::factory()->user->create() );
		$output = do_blocks( '<!-- wp:vicunav/restaurante-saved-pizzas /-->' );

		$this->assertStringContainsString( 'data-authenticated="1"', $output );
		$this->assertStringContainsString( 'data-rest-nonce=', $output );
		$this->assertStringContainsString( 'data-saved-pizzas-list', $output );
		$this->assertStringNotContainsString( 'configuration_json', $output );
		$this->assertStringNotContainsString( 'share_token', $output );
		$this->assertStringNotContainsString( 'public_id', $output );
	}

	/** Los assets se cargan únicamente cuando el bloque se renderiza. */
	public function test_frontend_assets_are_conditional(): void {
		$block  = WP_Block_Type_Registry::get_instance()->get_registered( 'vicunav/restaurante-saved-pizzas' );
		$module = $block->view_script_module_ids[0];
		$style  = $block->style_handles[0];

		do_blocks( '<!-- wp:paragraph --><p>Sin cuenta.</p><!-- /wp:paragraph -->' );
		$this->assertNotContains( $module, wp_script_modules()->get_queue() );
		$this->assertFalse( wp_style_is( $style, 'enqueued' ) );

		do_blocks( '<!-- wp:vicunav/restaurante-saved-pizzas /-->' );
		$this->assertContains( $module, wp_script_modules()->get_queue() );
		$this->assertTrue( wp_style_is( $style, 'enqueued' ) );
	}
}
