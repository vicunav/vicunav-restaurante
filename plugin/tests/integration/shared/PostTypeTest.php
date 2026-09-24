<?php
/**
 * Pruebas de la clase base para tipos de contenido.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Shared\Tests;

use WP_Error;
use WP_Post_Type;
use WP_UnitTestCase;

require_once __DIR__ . '/fixtures/PostTypeFixture.php';

/**
 * Verifica el contrato observable de PostType.
 */
final class PostTypeTest extends WP_UnitTestCase {
	/**
	 * Limpia el CPT y los hooks creados por cada prueba.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unregister_post_type( 'vicu_example' );
		parent::tear_down();
	}

	/**
	 * Comprueba que los argumentos lleguen a WordPress.
	 *
	 * @return void
	 */
	public function test_registers_valid_post_type_with_declared_arguments(): void {
		$post_type = new PostTypeFixture(
			'vicu_example',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor' ),
			)
		);

		$registered = $post_type->register();

		$this->assertInstanceOf( WP_Post_Type::class, $registered );
		$this->assertTrue( post_type_exists( 'vicu_example' ) );
		$this->assertTrue( $registered->public );
		$this->assertTrue( $registered->show_in_rest );
		$this->assertTrue( post_type_supports( 'vicu_example', 'title' ) );
		$this->assertTrue( post_type_supports( 'vicu_example', 'editor' ) );
		$this->assertFalse( post_type_supports( 'vicu_example', 'thumbnail' ) );
	}

	/**
	 * Comprueba que un slug fuera del contrato no llegue a WordPress.
	 *
	 * @dataProvider invalid_slug_provider
	 *
	 * @param string $slug Slug inválido.
	 * @return void
	 */
	public function test_rejects_invalid_slug( string $slug ): void {
		$post_type = new PostTypeFixture( $slug );
		$result    = $post_type->register();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'vicu_core_invalid_post_type', $result->get_error_code() );
		$this->assertFalse( post_type_exists( $slug ) );
	}

	/**
	 * Proporciona slugs que incumplen prefijo, formato o longitud.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_slug_provider(): array {
		return array(
			'sin prefijo'   => array( 'example' ),
			'mayúsculas'    => array( 'vicu_Example' ),
			'guion'         => array( 'vicu_example-type' ),
			'más de veinte' => array( 'vicu_1234567890123456' ),
		);
	}

	/**
	 * Comprueba que una instancia no duplique su callback de init.
	 *
	 * @return void
	 */
	public function test_register_hooks_is_idempotent(): void {
		$post_type = new PostTypeFixture( 'vicu_example' );

		$post_type->register_hooks();
		$post_type->register_hooks();

		$this->assertSame( 10, has_action( 'init', array( $post_type, 'register' ) ) );

		remove_action( 'init', array( $post_type, 'register' ) );
	}
}
