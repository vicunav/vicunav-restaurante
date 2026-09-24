<?php
/**
 * Controlador REST administrativo para solicitudes de pago.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Payments\Rest;

defined( 'ABSPATH' ) || exit;

use Vicu\Restaurante\Payments\PaymentRequests;
use Vicu\Restaurante\Payments\PostTypes\PaymentRequest;
use WP_Error;
use WP_REST_Posts_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Evita que la colección privada se exponga como lectura anónima.
 */
final class PaymentRequestController extends WP_REST_Posts_Controller {
	/**
	 * Delega la creación administrativa en el servicio idempotente.
	 *
	 * @param WP_REST_Request $request Solicitud REST actual.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$meta   = (array) $request->get_param( 'meta' );
		$result = PaymentRequests::create(
			array(
				'external_type' => $meta[ PaymentRequest::META_EXTERNAL_TYPE ] ?? '',
				'external_id'   => $meta[ PaymentRequest::META_EXTERNAL_ID ] ?? '',
				'amount_minor'  => $meta[ PaymentRequest::META_AMOUNT_MINOR ] ?? 0,
				'currency'      => $meta[ PaymentRequest::META_CURRENCY ] ?? '',
				'expires_at'    => $meta[ PaymentRequest::META_EXPIRES_AT ] ?? null,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post = get_post( $result['id'] );

		if ( null === $post ) {
			return new WP_Error(
				'vicu_pagos_storage_error',
				__( 'No fue posible cargar la solicitud persistida.', 'vicunav-restaurante' ),
				array( 'status' => 500 )
			);
		}

		$response = $this->prepare_item_for_response( $post, $request );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Impide evadir el servicio mediante actualizaciones genéricas de meta.
	 *
	 * @param WP_REST_Request $request Solicitud REST actual.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$meta = (array) $request->get_param( 'meta' );

		if ( array_intersect( array_keys( $meta ), PaymentRequest::contract_meta_keys() ) ) {
			return new WP_Error(
				'vicu_pagos_immutable_meta',
				__( 'Los datos contractuales se modifican únicamente mediante el servicio de pagos.', 'vicunav-restaurante' ),
				array( 'status' => 409 )
			);
		}

		return parent::update_item( $request );
	}

	/**
	 * Restringe el listado a operadores con permiso de edición.
	 *
	 * @param WP_REST_Request $request Solicitud REST actual.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( 'edit_vicu_payment_requests' ) ) {
			return new WP_Error(
				'rest_cannot_view',
				__( 'No tienes permisos para ver solicitudes de pago.', 'vicunav-restaurante' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return parent::get_items_permissions_check( $request );
	}
}
