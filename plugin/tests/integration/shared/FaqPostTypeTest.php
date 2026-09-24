<?php
/**
 * Pruebas del CPT compartido de preguntas frecuentes.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Shared\Tests;

use WP_Post_Type;
use WP_UnitTestCase;

/**
 * Verifica la configuración contractual de vicu_faq.
 */
final class FaqPostTypeTest extends WP_UnitTestCase {
	/**
	 * Comprueba que el bootstrap registre el slug aprobado.
	 *
	 * @return void
	 */
	public function test_bootstrap_registers_faq_post_type(): void {
		$this->assertTrue( post_type_exists( 'vicu_faq' ) );
		$this->assertInstanceOf( WP_Post_Type::class, get_post_type_object( 'vicu_faq' ) );
	}

	/**
	 * Comprueba la exposición pública y REST.
	 *
	 * @return void
	 */
	public function test_faq_is_public_and_available_in_rest(): void {
		$post_type = get_post_type_object( 'vicu_faq' );

		$this->assertInstanceOf( WP_Post_Type::class, $post_type );
		$this->assertTrue( $post_type->public );
		$this->assertTrue( $post_type->show_in_rest );
	}

	/**
	 * Comprueba las capacidades editoriales sin presentación.
	 *
	 * @return void
	 */
	public function test_faq_supports_contracted_editor_features(): void {
		$this->assertTrue( post_type_supports( 'vicu_faq', 'title' ) );
		$this->assertTrue( post_type_supports( 'vicu_faq', 'editor' ) );
		$this->assertTrue( post_type_supports( 'vicu_faq', 'page-attributes' ) );
		$this->assertFalse( post_type_supports( 'vicu_faq', 'thumbnail' ) );
	}
}
