<?php
/**
 * Pruebas estructurales del bloque dinámico de reservas.
 *
 * @package Vicunav_Restaurante
 */

/** Verifica metadata, SSR privado y carga condicional. */
final class ReservationBlockTest extends WP_UnitTestCase {
	/** Aísla las colas antes de cada comprobación. */
	public function setUp(): void {
		parent::setUp();
		wp_script_modules()->dequeue( 'vicunav-restaurante-reservations-view-script-module' );
		wp_dequeue_style( 'vicunav-restaurante-reservations-style' );
	}

	/** Registra API 3, render dinámico, módulo y estilos propios. */
	public function test_registers_interactive_dynamic_block(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'vicunav/restaurante-reservations' );

		$this->assertInstanceOf( WP_Block_Type::class, $block );
		$this->assertSame( 3, $block->api_version );
		$this->assertTrue( is_callable( $block->render_callback ) );
		$this->assertTrue( $block->supports['interactivity'] );
		$this->assertFalse( $block->supports['html'] );
		$this->assertNotEmpty( $block->view_script_module_ids );
		$this->assertNotEmpty( $block->style_handles );
	}

	/** El SSR etiqueta campos y no publica reservas, contacto ni tokens. */
	public function test_server_render_is_safe_and_complete(): void {
		$output = do_blocks( '<!-- wp:vicunav/restaurante-reservations /-->' );

		$this->assertStringContainsString( 'data-vicu-reservations-root', $output );
		$this->assertStringContainsString( 'Ver horarios disponibles', $output );
		$this->assertStringContainsString( 'Confirmar reserva', $output );
		$this->assertStringContainsString( 'Cancelar reserva', $output );
		$this->assertStringContainsString( 'aria-live="polite"', $output );
		$this->assertStringContainsString( '<label for=', $output );
		$this->assertStringNotContainsString( 'access_token', $output );
		$this->assertStringNotContainsString( 'confirmation_code', $output );
		$this->assertStringNotContainsString( 'guest_phone', $output );
		$this->assertStringNotContainsString( '<h1', $output );
	}

	/** El módulo y el stylesheet no se encolan en páginas sin el bloque. */
	public function test_frontend_assets_are_conditional(): void {
		$block  = WP_Block_Type_Registry::get_instance()->get_registered( 'vicunav/restaurante-reservations' );
		$module = $block->view_script_module_ids[0];
		$style  = $block->style_handles[0];

		do_blocks( '<!-- wp:paragraph --><p>Sin reservas.</p><!-- /wp:paragraph -->' );
		$this->assertNotContains( $module, wp_script_modules()->get_queue() );
		$this->assertFalse( wp_style_is( $style, 'enqueued' ) );

		do_blocks( '<!-- wp:vicunav/restaurante-reservations /-->' );
		$this->assertContains( $module, wp_script_modules()->get_queue() );
		$this->assertTrue( wp_style_is( $style, 'enqueued' ) );
	}
}
