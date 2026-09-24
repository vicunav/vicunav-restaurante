<?php
/**
 * Pruebas del CPT compartido de testimonios.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Shared\Tests;

use WP_Post_Type;
use WP_UnitTestCase;

/**
 * Verifica la configuración contractual de vicu_testimonial.
 */
final class TestimonialPostTypeTest extends WP_UnitTestCase {
	/**
	 * Comprueba que el bootstrap registre el slug aprobado.
	 *
	 * @return void
	 */
	public function test_bootstrap_registers_testimonial_post_type(): void {
		$this->assertTrue( post_type_exists( 'vicu_testimonial' ) );
		$this->assertInstanceOf( WP_Post_Type::class, get_post_type_object( 'vicu_testimonial' ) );
	}

	/**
	 * Comprueba la exposición pública y REST.
	 *
	 * @return void
	 */
	public function test_testimonial_is_public_and_available_in_rest(): void {
		$post_type = get_post_type_object( 'vicu_testimonial' );

		$this->assertInstanceOf( WP_Post_Type::class, $post_type );
		$this->assertTrue( $post_type->public );
		$this->assertTrue( $post_type->show_in_rest );
	}

	/**
	 * Comprueba los soportes editoriales acordados.
	 *
	 * @return void
	 */
	public function test_testimonial_supports_contracted_editor_features(): void {
		$this->assertTrue( post_type_supports( 'vicu_testimonial', 'title' ) );
		$this->assertTrue( post_type_supports( 'vicu_testimonial', 'editor' ) );
		$this->assertTrue( post_type_supports( 'vicu_testimonial', 'excerpt' ) );
		$this->assertTrue( post_type_supports( 'vicu_testimonial', 'thumbnail' ) );
		$this->assertFalse( post_type_supports( 'vicu_testimonial', 'page-attributes' ) );
	}
}
