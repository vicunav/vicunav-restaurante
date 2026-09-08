<?php
/**
 * Render seguro de la cobertura de entrega de la portada.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Blocks;

defined( 'ABSPATH' ) || exit;

use Vicu\Restaurante\Settings\RestaurantSettings;

/** Publica únicamente estructura y el endpoint de zonas; la tarifa la decide el servidor. */
final class DeliveryCoverageBlock {
	/** Renderiza el formulario de verificación de zona. */
	public static function render(): string {
		$root_id = wp_unique_id( 'vicu-restaurante-delivery-coverage-' );
		$context = array(
			'query'          => '',
			'status'         => 'idle',
			'zone'           => null,
			'zones'          => array(),
			'currency'       => RestaurantSettings::currency(),
			'locale'         => str_replace( '_', '-', determine_locale() ),
			'restUrl'        => esc_url_raw( rest_url( 'vicu/v1/restaurante/delivery-zones' ) ),
			'foundLabel'     => __( 'Sí, entregamos en', 'vicunav-restaurante' ),
			'foundFreeLabel' => __( 'Sí, entrega gratis en', 'vicunav-restaurante' ),
			'notFoundLabel'  => __( 'Esa zona no está en nuestra cobertura actual. Revisa las zonas disponibles al finalizar tu pedido.', 'vicunav-restaurante' ),
			'errorLabel'     => __( 'No pudimos verificar la cobertura. Intenta de nuevo.', 'vicunav-restaurante' ),
		);

		$attributes = get_block_wrapper_attributes(
			array(
				'id'                  => $root_id,
				'data-wp-interactive' => 'vicunav/restaurante-delivery-coverage',
				'data-wp-context'     => wp_json_encode( $context ),
				'data-wp-init'        => 'actions.initialize',
			)
		);

		ob_start();
		?>
		<section <?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php esc_attr_e( 'Cobertura de entrega', 'vicunav-restaurante' ); ?>">
			<form class="vicu-restaurante-delivery-coverage__form" data-wp-on--submit="actions.verify">
				<label for="<?php echo esc_attr( $root_id ); ?>-input"><?php esc_html_e( '¿Entregamos en tu zona?', 'vicunav-restaurante' ); ?></label>
				<div class="vicu-restaurante-delivery-coverage__row">
					<input
						id="<?php echo esc_attr( $root_id ); ?>-input"
						type="text"
						autocomplete="off"
						data-wp-on--input="actions.updateQuery"
						placeholder="<?php esc_attr_e( 'Escribe tu sector o dirección', 'vicunav-restaurante' ); ?>"
					>
					<button type="submit" data-wp-bind--disabled="state.isBusy"><?php esc_html_e( 'Verificar', 'vicunav-restaurante' ); ?></button>
				</div>
			</form>
			<p
				class="vicu-restaurante-delivery-coverage__result"
				data-wp-bind--hidden="state.isResultHidden"
				data-wp-text="state.resultMessage"
				role="status"
				aria-live="polite"
			></p>
			<noscript><p><?php esc_html_e( 'Activa JavaScript para verificar tu zona de entrega.', 'vicunav-restaurante' ); ?></p></noscript>
		</section>
		<?php

		return (string) ob_get_clean();
	}
}
