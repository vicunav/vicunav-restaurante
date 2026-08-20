<?php
/**
 * Render seguro de pizzas guardadas para cuenta.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Blocks;

/** Publica estructura sin incorporar recursos privados al HTML cacheable. */
final class SavedPizzasBlock {
	/** Renderiza el estado de cuenta y las regiones que hidrata REST. */
	public static function render(): string {
		$authenticated    = is_user_logged_in();
		$redirect         = get_permalink();
		$redirect         = is_string( $redirect ) && '' !== $redirect ? $redirect : home_url( '/' );
		$block_attributes = array(
			'data-wp-interactive'         => 'vicunav/restaurante-saved-pizzas',
			'data-wp-context'             => wp_json_encode( new \stdClass() ),
			'data-wp-init'                => 'actions.initialize',
			'data-wp-on--click'           => 'actions.handleClick',
			'data-wp-on--submit'          => 'actions.handleSubmit',
			'data-vicu-saved-pizzas-root' => '',
			'data-authenticated'          => $authenticated ? '1' : '0',
			'data-rest-saved-pizzas'      => esc_url_raw( rest_url( 'vicu/v1/restaurante/saved-pizzas' ) ),
			'data-rest-pizza-options'     => esc_url_raw( rest_url( 'vicu/v1/restaurante/pizza/options' ) ),
			'data-rest-pizza-quote'       => esc_url_raw( rest_url( 'vicu/v1/restaurante/pizza/quote' ) ),
			'data-rest-cart'              => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart' ) ),
			'data-rest-carts'             => esc_url_raw( rest_url( 'vicu/v1/restaurante/carts' ) ),
			'data-rest-cart-items'        => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart/items' ) ),
			'data-locale'                 => str_replace( '_', '-', determine_locale() ),
			'data-loading-message'        => __( 'Cargando tus pizzas guardadas.', 'vicunav-restaurante' ),
			'data-error-message'          => __( 'No pudimos completar la operación.', 'vicunav-restaurante' ),
			'data-empty-message'          => __( 'Todavía no tienes pizzas guardadas.', 'vicunav-restaurante' ),
			'data-conflict-message'       => __( 'La colección cambió. Mostramos su versión más reciente.', 'vicunav-restaurante' ),
			'data-added-message'          => __( 'La pizza se añadió al carrito.', 'vicunav-restaurante' ),
			'data-renamed-message'        => __( 'La pizza fue renombrada.', 'vicunav-restaurante' ),
			'data-deleted-message'        => __( 'La pizza fue eliminada.', 'vicunav-restaurante' ),
			'data-share-message'          => __( 'Se creó un enlace nuevo y el anterior dejó de ser válido.', 'vicunav-restaurante' ),
		);

		if ( $authenticated ) {
			$block_attributes['data-rest-nonce'] = wp_create_nonce( 'wp_rest' );
		}

		$attributes = get_block_wrapper_attributes( $block_attributes );

		ob_start();
		?>
		<section <?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php esc_attr_e( 'Pizzas guardadas', 'vicunav-restaurante' ); ?>">
			<?php if ( ! $authenticated ) : ?>
				<div class="vicu-restaurante-saved-pizzas__authentication">
					<p><?php esc_html_e( 'Inicia sesión para ver y gestionar tus pizzas guardadas.', 'vicunav-restaurante' ); ?></p>
					<a href="<?php echo esc_url( wp_login_url( $redirect ) ); ?>"><?php esc_html_e( 'Iniciar sesión', 'vicunav-restaurante' ); ?></a>
				</div>
			<?php else : ?>
				<p data-saved-pizzas-status role="status" aria-live="polite" aria-atomic="true"><?php esc_html_e( 'Cargando tus pizzas guardadas.', 'vicunav-restaurante' ); ?></p>
				<p data-saved-pizzas-error role="alert" tabindex="-1" hidden></p>
				<p data-saved-pizzas-empty hidden><?php esc_html_e( 'Todavía no tienes pizzas guardadas.', 'vicunav-restaurante' ); ?></p>
				<ul class="vicu-restaurante-saved-pizzas__list" data-saved-pizzas-list aria-label="<?php esc_attr_e( 'Pizzas de la cuenta', 'vicunav-restaurante' ); ?>"></ul>
			<?php endif; ?>
			<noscript><p><?php esc_html_e( 'Activa JavaScript para gestionar pizzas guardadas.', 'vicunav-restaurante' ); ?></p></noscript>
		</section>
		<?php

		return (string) ob_get_clean();
	}
}
