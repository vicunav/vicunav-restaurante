<?php
/**
 * Ciclo de vida del módulo de pagos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Payments;

use Vicu\Restaurante\Payments\PostTypes\PaymentRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Enlaza el tipo de contenido, la expiración y el almacenamiento de pagos.
 *
 * @internal
 */
final class Module {
	/**
	 * Registra los hooks del módulo y actualiza su schema cuando corresponde.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		Installer::maybe_upgrade();

		$payment_request = new PaymentRequest();
		$payment_request->register_hooks();
		add_action( 'init', array( PaymentRequest::class, 'register_meta' ), 20 );
		add_action( 'before_delete_post', array( self::class, 'delete_payment_request_storage' ), 10, 2 );
		ExpirationScheduler::register();
	}

	/**
	 * Instala el schema, las capacidades y la expiración durante la activación.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Installer::install();
		Capabilities::grant_to_administrator();
		ExpirationScheduler::schedule();
	}

	/**
	 * Retira la recurrencia sin borrar solicitudes al desactivar.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		ExpirationScheduler::unschedule();
	}

	/**
	 * Mantiene sincronizada la persistencia al eliminar el post administrativo.
	 *
	 * @param int      $post_id ID del post eliminado.
	 * @param \WP_Post $post    Post que WordPress eliminará.
	 * @return void
	 */
	public static function delete_payment_request_storage( int $post_id, \WP_Post $post ): void {
		if ( PaymentRequest::SLUG === $post->post_type ) {
			ManualSubmissionRepository::delete_by_request_id( $post_id );
			PaymentRequestRepository::delete_by_post_id( $post_id );
		}
	}
}
