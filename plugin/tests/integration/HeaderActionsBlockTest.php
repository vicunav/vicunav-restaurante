<?php
/**
 * Pruebas estructurales de las acciones compactas de cabecera.
 *
 * @package Vicunav_Restaurante
 */

/** Verifica metadata, estados SSR y assets condicionales. */
final class HeaderActionsBlockTest extends WP_UnitTestCase {
	/** Aísla identidad y colas antes de cada comprobación. */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
		wp_script_modules()->dequeue( 'vicu-restaurante-commerce' );
		wp_dequeue_style( 'vicu-restaurante-commerce-style' );
	}

	/** Retira la identidad de la prueba. */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** El bloque usa API 3, render dinámico y el módulo compartido de comercio. */
	public function test_registers_dynamic_block_with_shared_module(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'vicunav/restaurante-header-actions' );

		$this->assertInstanceOf( WP_Block_Type::class, $block );
		$this->assertSame( 3, $block->api_version );
		$this->assertTrue( is_callable( $block->render_callback ) );
		$this->assertTrue( $block->supports['interactivity'] );
		$this->assertFalse( $block->supports['html'] );
		$this->assertContains( 'vicu-restaurante-commerce', $block->view_script_module_ids );
	}

	/** Un visitante recibe carrito vacío y un enlace de inicio de sesión, sin nonce. */
	public function test_anonymous_server_render_offers_login(): void {
		$output = do_blocks( '<!-- wp:vicunav/restaurante-header-actions /-->' );

		$this->assertStringContainsString( 'data-vicu-commerce-role="header"', $output );
		$this->assertStringContainsString( 'data-has-cart-identity="0"', $output );
		$this->assertStringContainsString( 'wp-login.php', $output );
		$this->assertStringContainsString( 'data-header-cart-count', $output );
		$this->assertStringContainsString( 'hidden', $output );
		$this->assertStringContainsString( 'data-rest-nonce=""', $output );
		$this->assertStringNotContainsString( '<h1', $output );
	}

	/** Una cuenta autenticada recibe nonce y el enlace de cuenta configurado. */
	public function test_authenticated_server_render_links_account(): void {
		wp_set_current_user( self::factory()->user->create() );
		$output = do_blocks( '<!-- wp:vicunav/restaurante-header-actions {"accountUrl":"https://example.test/mis-pizzas/"} /-->' );

		$this->assertStringContainsString( 'data-has-cart-identity="1"', $output );
		$this->assertStringContainsString( 'data-rest-nonce="', $output );
		$this->assertStringContainsString( 'https://example.test/mis-pizzas/', $output );
		$this->assertStringNotContainsString( 'wp-login.php', $output );
	}

	/** El atributo cartUrl enlaza la acción de carrito sin invención de rutas. */
	public function test_cart_url_attribute_is_used_verbatim(): void {
		$output = do_blocks( '<!-- wp:vicunav/restaurante-header-actions {"cartUrl":"https://example.test/carrito/"} /-->' );

		$this->assertStringContainsString( 'https://example.test/carrito/', $output );
	}

	/** Los assets compartidos solo se cargan cuando el bloque aparece. */
	public function test_frontend_assets_are_conditional(): void {
		do_blocks( '<!-- wp:paragraph --><p>Sin cabecera.</p><!-- /wp:paragraph -->' );
		$this->assertNotContains( 'vicu-restaurante-commerce', vicu_restaurante_test_script_module_queue() );
		$this->assertFalse( wp_style_is( 'vicu-restaurante-commerce-style', 'enqueued' ) );

		do_blocks( '<!-- wp:vicunav/restaurante-header-actions /-->' );
		$this->assertContains( 'vicu-restaurante-commerce', vicu_restaurante_test_script_module_queue() );
		$this->assertTrue( wp_style_is( 'vicu-restaurante-commerce-style', 'enqueued' ) );
	}
}
