<?php
/**
 * Pruebas del CPT y su persistencia inicial.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Payments\PostTypes\PaymentRequest;

/**
 * Verifica registro privado, permisos y sanitización.
 */
final class PaymentRequestPostTypeTest extends WP_UnitTestCase {
	/**
	 * Reconstruye los registros globales que wp-phpunit limpia entre pruebas.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! post_type_exists( PaymentRequest::SLUG ) ) {
			( new PaymentRequest() )->register();
		}

		PaymentRequest::register_meta();
	}

	/**
	 * Verifica el contrato estructural del CPT.
	 *
	 * @return void
	 */
	public function test_registers_private_administrative_post_type(): void {
		$post_type = get_post_type_object( PaymentRequest::SLUG );

		$this->assertNotNull( $post_type );
		$this->assertFalse( $post_type->public );
		$this->assertFalse( $post_type->publicly_queryable );
		$this->assertTrue( $post_type->show_ui );
		$this->assertTrue( $post_type->show_in_rest );
		$this->assertSame( 'vicu-payment-requests', $post_type->rest_base );
		$this->assertSame( 'vicunav', $post_type->show_in_menu );
		$this->assertSame( 'create_vicu_payment_requests', $post_type->cap->create_posts );
	}

	/**
	 * Verifica que todas las claves iniciales estén registradas.
	 *
	 * @return void
	 */
	public function test_registers_initial_meta_schema(): void {
		$registered = get_registered_meta_keys( 'post', PaymentRequest::SLUG );

		$this->assertArrayHasKey( PaymentRequest::META_EXTERNAL_TYPE, $registered );
		$this->assertArrayHasKey( PaymentRequest::META_EXTERNAL_ID, $registered );
		$this->assertArrayHasKey( PaymentRequest::META_AMOUNT_MINOR, $registered );
		$this->assertArrayHasKey( PaymentRequest::META_CURRENCY, $registered );
		$this->assertArrayHasKey( PaymentRequest::META_PROVIDER, $registered );
		$this->assertArrayHasKey( PaymentRequest::META_STATE, $registered );
		$this->assertArrayHasKey( PaymentRequest::META_REVISION, $registered );
		$this->assertArrayHasKey( PaymentRequest::META_EXPIRES_AT, $registered );
		$this->assertSame( 'integer', $registered[ PaymentRequest::META_AMOUNT_MINOR ]['type'] );
		$this->assertSame( 'integer', $registered[ PaymentRequest::META_REVISION ]['type'] );
	}

	/**
	 * Verifica sanitización determinista sin datos de otro dominio.
	 *
	 * @return void
	 */
	public function test_sanitizes_contractual_meta_values(): void {
		$this->assertSame( 'vicu_order', PaymentRequest::sanitize_external_type( 'Vicu_Order' ) );
		$this->assertSame( 'vicu_order', PaymentRequest::sanitize_external_type( 'vicu-order' ) );
		$this->assertSame( 'ORD-42', PaymentRequest::sanitize_external_id( ' ORD-42 ' ) );
		$this->assertSame( 1234, PaymentRequest::sanitize_amount_minor( '1234' ) );
		$this->assertSame( 0, PaymentRequest::sanitize_amount_minor( '-1234' ) );
		$this->assertSame( 'USD', PaymentRequest::sanitize_currency( 'usd' ) );
		$this->assertSame( '', PaymentRequest::sanitize_currency( 'USDX' ) );
		$this->assertSame( '', PaymentRequest::sanitize_currency( array( 'USD' ) ) );
	}
}
