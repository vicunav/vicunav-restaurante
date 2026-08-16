<?php
/**
 * Controlador protegido del CPT para el editor de bloques.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Rest;

use WP_Error;
use WP_REST_Posts_Controller;
use WP_REST_Request;

/**
 * Impide que wp/v2 omita la proyección pública validada del menú.
 */
final class MenuItemController extends WP_REST_Posts_Controller {
	/**
	 * Protege el listado genérico sin afectar el endpoint público contractual.
	 *
	 * @param WP_REST_Request $request Solicitud del editor o API genérica.
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_vicu_restaurant_catalog' ) ) {
			return $this->forbidden();
		}

		return parent::get_items_permissions_check( $request );
	}

	/**
	 * Protege el detalle genérico por la misma capability.
	 *
	 * @param WP_REST_Request $request Solicitud del editor o API genérica.
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_vicu_restaurant_catalog' ) ) {
			return $this->forbidden();
		}

		return parent::get_item_permissions_check( $request );
	}

	/**
	 * Devuelve el error estable del vertical.
	 *
	 * @return WP_Error
	 */
	private function forbidden(): WP_Error {
		return new WP_Error(
			'vicu_restaurante_forbidden',
			__( 'No tienes permisos para consultar esta superficie administrativa.', 'vicunav-restaurante' ),
			array( 'status' => 403 )
		);
	}
}
