<?php
/**
 * Pruebas de la base REST compartida.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Shared\Tests;

use Vicu\Restaurante\Shared\Rest;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Verifica namespace, dispatch y política de permisos explícitos.
 */
final class RestTest extends WP_UnitTestCase {
	/**
	 * Libera el servidor REST global después de cada prueba.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_rest_server;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- La suite aísla cada registro de rutas.
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Comprueba la constante propietaria del namespace.
	 *
	 * @return void
	 */
	public function test_exposes_contracted_namespace(): void {
		$this->assertSame( 'vicu/v1', Rest::NAMESPACE );
	}

	/**
	 * Comprueba normalización y permiso público explícito.
	 *
	 * @return void
	 */
	public function test_registers_normalized_route_with_explicit_permission(): void {
		$result = null;
		$hook   = static function () use ( &$result ): void {
			$result = Rest::register_route(
				'example',
				array(
					'methods'             => 'GET',
					'callback'            => static fn (): WP_REST_Response => new WP_REST_Response( array( 'ok' => true ) ),
					'permission_callback' => '__return_true',
				)
			);
		};

		add_action( 'rest_api_init', $hook, 20 );
		$server = $this->initialize_rest_server();
		remove_action( 'rest_api_init', $hook, 20 );

		$response = $server->dispatch( new WP_REST_Request( 'GET', '/vicu/v1/example' ) );

		$this->assertTrue( $result );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'ok' => true ), $response->get_data() );
	}

	/**
	 * Comprueba rechazo sin permission_callback y ausencia de registro parcial.
	 *
	 * @return void
	 */
	public function test_rejects_route_without_permission_callback(): void {
		$result = null;
		$hook   = static function () use ( &$result ): void {
			$result = Rest::register_route(
				'/missing-permission',
				array(
					'methods'  => 'GET',
					'callback' => '__return_empty_array',
				)
			);
		};

		add_action( 'rest_api_init', $hook, 20 );
		$server = $this->initialize_rest_server();
		remove_action( 'rest_api_init', $hook, 20 );

		$this->assertFalse( $result );
		$this->assertArrayNotHasKey( '/vicu/v1/missing-permission', $server->get_routes() );
	}

	/**
	 * Comprueba que una lista incompleta se rechace de forma atómica.
	 *
	 * @return void
	 */
	public function test_rejects_incomplete_multiple_endpoint_definition(): void {
		$result = null;
		$hook   = static function () use ( &$result ): void {
			$result = Rest::register_route(
				'/multiple',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => '__return_empty_array',
						'permission_callback' => '__return_true',
					),
					array(
						'methods'  => 'POST',
						'callback' => '__return_empty_array',
					),
				)
			);
		};

		add_action( 'rest_api_init', $hook, 20 );
		$server = $this->initialize_rest_server();
		remove_action( 'rest_api_init', $hook, 20 );

		$this->assertFalse( $result );
		$this->assertArrayNotHasKey( '/vicu/v1/multiple', $server->get_routes() );
	}

	/**
	 * Comprueba que no se delegue a WordPress fuera del hook correcto.
	 *
	 * @return void
	 */
	public function test_rejects_registration_outside_rest_api_init(): void {
		$result = Rest::register_route(
			'/outside',
			array(
				'methods'             => 'GET',
				'callback'            => '__return_empty_array',
				'permission_callback' => '__return_true',
			)
		);

		$this->assertFalse( $result );
	}

	/**
	 * Crea el servidor y dispara el hook oficial de registro.
	 *
	 * @return WP_REST_Server
	 */
	private function initialize_rest_server(): WP_REST_Server {
		global $wp_rest_server;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- La suite necesita un servidor aislado.
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		return $wp_rest_server;
	}
}
