<?php
/**
 * Pruebas estructurales de los bloques coordinados de comercio.
 *
 * @package Vicunav_Restaurante
 */

/** Verifica metadata, SSR privado y carga compartida de assets. */
final class CommerceBlocksTest extends WP_UnitTestCase {
	/** Aísla la cola compartida antes de cada comprobación. */
	public function setUp(): void {
		parent::setUp();
		wp_script_modules()->dequeue( 'vicu-restaurante-commerce' );
		wp_dequeue_style( 'vicu-restaurante-commerce-style' );
	}

	/** Los tres bloques usan API 3, render dinámico y un único módulo compartido. */
	public function test_registers_three_dynamic_blocks_with_shared_module(): void {
		foreach ( array( 'cart', 'checkout', 'order-status' ) as $name ) {
			$block = WP_Block_Type_Registry::get_instance()->get_registered( 'vicunav/restaurante-' . $name );

			$this->assertInstanceOf( WP_Block_Type::class, $block );
			$this->assertSame( 3, $block->api_version );
			$this->assertTrue( is_callable( $block->render_callback ) );
			$this->assertTrue( $block->supports['interactivity'] );
			$this->assertFalse( $block->supports['html'] );
			$this->assertContains( 'vicu-restaurante-commerce', $block->view_script_module_ids );
		}
	}

	/** El SSR contiene formularios accesibles sin datos de carrito, contacto o token. */
	public function test_server_render_is_safe_and_complete(): void {
		$output = do_blocks(
			'<!-- wp:vicunav/restaurante-cart /-->' .
			'<!-- wp:vicunav/restaurante-checkout /-->' .
			'<!-- wp:vicunav/restaurante-order-status /-->'
		);

		$this->assertStringContainsString( 'data-vicu-commerce-role="cart"', $output );
		$this->assertStringContainsString( 'data-vicu-commerce-role="checkout"', $output );
		$this->assertStringContainsString( 'data-vicu-commerce-role="order"', $output );
		$this->assertStringContainsString( 'data-has-cart-identity="0"', $output );
		$this->assertStringContainsString( 'Código de descuento', $output );
		$this->assertStringContainsString( 'Crear pedido y continuar al pago manual', $output );
		$this->assertStringContainsString( 'Referencia del pago manual', $output );
		$this->assertStringNotContainsString( 'access_token', $output );
		$this->assertStringNotContainsString( 'customer_phone', $output );
		$this->assertStringNotContainsString( '<h1', $output );
		$this->assertContains( 'vicu-restaurante-commerce', wp_script_modules()->get_queue() );
	}

	/** Los assets compartidos solo se cargan cuando aparece una superficie de comercio. */
	public function test_shared_frontend_assets_are_conditional(): void {
		do_blocks( '<!-- wp:paragraph --><p>Sin comercio.</p><!-- /wp:paragraph -->' );
		$this->assertNotContains( 'vicu-restaurante-commerce', wp_script_modules()->get_queue() );
		$this->assertFalse( wp_style_is( 'vicu-restaurante-commerce-style', 'enqueued' ) );

		do_blocks( '<!-- wp:vicunav/restaurante-cart /-->' );
		$this->assertContains( 'vicu-restaurante-commerce', wp_script_modules()->get_queue() );
		$this->assertTrue( wp_style_is( 'vicu-restaurante-commerce-style', 'enqueued' ) );
	}
}
