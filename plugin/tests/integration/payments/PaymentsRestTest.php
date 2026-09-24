<?php
/**
 * Pruebas del REST administrativo protegido.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Payments\Capabilities;
use Vicu\Restaurante\Payments\PostTypes\PaymentRequest;

/**
 * Verifica que el CPT no se convierta en una API pública de negocio.
 */
final class PaymentsRestTest extends WP_UnitTestCase {
	/**
	 * Servidor REST aislado por prueba.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Prepara rutas y permisos.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		vicu_restaurante_reset_payment_requests();

		global $wp_rest_server;

		if ( ! post_type_exists( PaymentRequest::SLUG ) ) {
			( new PaymentRequest() )->register();
		}

		PaymentRequest::register_meta();

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
	}

	/**
	 * Verifica rechazo para una llamada anónima.
	 *
	 * @return void
	 */
	public function test_rejects_anonymous_collection_request(): void {
		$request  = new WP_REST_Request( 'GET', '/wp/v2/vicu-payment-requests' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Verifica que un editor sin capabilities tampoco tenga acceso.
	 *
	 * @return void
	 */
	public function test_rejects_editor_without_payment_capabilities(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/vicu-payment-requests' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Verifica creación administrativa y schema de meta.
	 *
	 * @return void
	 */
	public function test_allows_administrator_to_create_request(): void {
		Capabilities::grant_to_administrator();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/vicu-payment-requests' );
		$request->set_body_params(
			array(
				'title'  => 'Solicitud ORD-42',
				'status' => 'publish',
				'meta'   => array(
					PaymentRequest::META_EXTERNAL_TYPE => 'vicu_order',
					PaymentRequest::META_EXTERNAL_ID   => 'ORD-42',
					PaymentRequest::META_AMOUNT_MINOR  => 1234,
					PaymentRequest::META_CURRENCY      => 'USD',
				),
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'vicu_order', $response->get_data()['meta'][ PaymentRequest::META_EXTERNAL_TYPE ] );
		$this->assertSame( 1234, $response->get_data()['meta'][ PaymentRequest::META_AMOUNT_MINOR ] );
		$this->assertSame( 'pendiente', $response->get_data()['meta'][ PaymentRequest::META_STATE ] );
		$this->assertSame( 1, $response->get_data()['meta'][ PaymentRequest::META_REVISION ] );
	}

	/**
	 * Verifica que REST no pueda evadir las transiciones del servicio.
	 *
	 * @return void
	 */
	public function test_rejects_contract_meta_updates(): void {
		Capabilities::grant_to_administrator();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$create = new WP_REST_Request( 'POST', '/wp/v2/vicu-payment-requests' );
		$create->set_body_params(
			array(
				'meta' => array(
					PaymentRequest::META_EXTERNAL_TYPE => 'vicu_order',
					PaymentRequest::META_EXTERNAL_ID   => 'ORD-REST-LOCK',
					PaymentRequest::META_AMOUNT_MINOR  => 1234,
					PaymentRequest::META_CURRENCY      => 'USD',
				),
			)
		);

		$created = $this->server->dispatch( $create );
		$post_id = $created->get_data()['id'];
		$update  = new WP_REST_Request( 'POST', '/wp/v2/vicu-payment-requests/' . $post_id );
		$update->set_body_params(
			array(
				'meta' => array( PaymentRequest::META_STATE => 'confirmado' ),
			)
		);

		$response = $this->server->dispatch( $update );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'vicu_pagos_immutable_meta', $response->get_data()['code'] );
	}
}
