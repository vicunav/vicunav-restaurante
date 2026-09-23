<?php
/**
 * Render seguro de carrito, checkout y estado de pedido.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Blocks;

defined( 'ABSPATH' ) || exit;

use Vicu\Restaurante\Cart\CartSessionService;
use Vicu\Restaurante\Settings\RestaurantSettings;

/** Publica estructuras vacías; los datos privados llegan por REST sin caché. */
final class CommerceBlocks {
	/**
	 * Renderiza la superficie coordinada del carrito.
	 *
	 * @param array $attributes Atributos del bloque (menuUrl, checkoutUrl).
	 * @return string Markup del bloque.
	 */
	public static function cart( array $attributes = array() ): string {
		$wrapper_attributes = self::attributes( 'cart' );
		$id                 = wp_unique_id( 'vicu-restaurante-cart-' );
		$menu_url           = isset( $attributes['menuUrl'] ) && is_string( $attributes['menuUrl'] ) ? trim( $attributes['menuUrl'] ) : '';
		$checkout_url       = isset( $attributes['checkoutUrl'] ) && is_string( $attributes['checkoutUrl'] ) ? trim( $attributes['checkoutUrl'] ) : '';

		ob_start();
		?>
		<section <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php esc_attr_e( 'Carrito', 'vicunav-restaurante' ); ?>">
			<p data-commerce-status role="status" aria-live="polite" aria-atomic="true"><?php esc_html_e( 'Cargando el carrito.', 'vicunav-restaurante' ); ?></p>
			<p data-commerce-error role="alert" hidden></p>
			<div class="vicu-restaurante-cart__empty" data-cart-empty hidden>
				<p><?php esc_html_e( 'Tu carrito está vacío.', 'vicunav-restaurante' ); ?></p>
				<a class="vicu-restaurante-cart__empty-cta" href="<?php echo esc_url( '' !== $menu_url ? $menu_url : '#' ); ?>"><?php esc_html_e( 'Ver el menú', 'vicunav-restaurante' ); ?></a>
			</div>
			<div class="vicu-restaurante-cart__layout" data-cart-layout hidden>
				<ul class="vicu-restaurante-cart__items" data-cart-items aria-label="<?php esc_attr_e( 'Productos del carrito', 'vicunav-restaurante' ); ?>"></ul>
				<div class="vicu-restaurante-cart__sidebar">
					<div class="vicu-restaurante-cart__field">
						<span class="vicu-restaurante-cart__field-label" id="<?php echo esc_attr( $id ); ?>-fulfillment-label"><?php esc_html_e( 'Tipo de pedido', 'vicunav-restaurante' ); ?></span>
						<div class="vicu-restaurante-cart__order-type" role="radiogroup" aria-labelledby="<?php echo esc_attr( $id ); ?>-fulfillment-label">
							<button type="button" data-commerce-action="set-fulfillment" data-fulfillment="pickup" role="radio" aria-checked="true"><?php esc_html_e( 'Retiro', 'vicunav-restaurante' ); ?></button>
							<button type="button" data-commerce-action="set-fulfillment" data-fulfillment="delivery" role="radio" aria-checked="false"><?php esc_html_e( 'Delivery', 'vicunav-restaurante' ); ?></button>
						</div>
					</div>
					<div class="vicu-restaurante-cart__field" data-cart-zone-field hidden>
						<label for="<?php echo esc_attr( $id ); ?>-zone"><?php esc_html_e( 'Zona de delivery', 'vicunav-restaurante' ); ?></label>
						<select id="<?php echo esc_attr( $id ); ?>-zone" data-cart-zone></select>
					</div>
					<div class="vicu-restaurante-cart__field">
						<span class="vicu-restaurante-cart__field-label" id="<?php echo esc_attr( $id ); ?>-tip-label"><?php esc_html_e( 'Propina', 'vicunav-restaurante' ); ?></span>
						<div class="vicu-restaurante-cart__tip" role="radiogroup" aria-labelledby="<?php echo esc_attr( $id ); ?>-tip-label" data-cart-tip-group>
							<?php foreach ( RestaurantSettings::tip_rates_bps() as $rate ) : ?>
								<button type="button" data-commerce-action="set-tip" data-tip-rate="<?php echo esc_attr( (string) $rate ); ?>" aria-pressed="<?php echo 0 === $rate ? 'true' : 'false'; ?>">
									<?php echo 0 === $rate ? esc_html__( 'Sin propina', 'vicunav-restaurante' ) : esc_html( number_format_i18n( $rate / 100, 2 ) . '%' ); ?>
								</button>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="vicu-restaurante-cart__field">
						<form data-commerce-form="discount" class="vicu-restaurante-cart__discount">
							<label for="<?php echo esc_attr( $id ); ?>-discount"><?php esc_html_e( 'Código de descuento', 'vicunav-restaurante' ); ?></label>
							<div class="vicu-restaurante-cart__discount-row">
								<input id="<?php echo esc_attr( $id ); ?>-discount" name="code" maxlength="64" autocomplete="off">
								<button type="submit"><?php esc_html_e( 'Aplicar', 'vicunav-restaurante' ); ?></button>
							</div>
						</form>
						<p class="vicu-restaurante-cart__discount-applied" data-cart-discount-applied hidden>
							<span data-cart-discount-code></span>
							<button type="button" data-commerce-action="remove-discount"><?php esc_html_e( 'Quitar', 'vicunav-restaurante' ); ?></button>
						</p>
					</div>
					<div class="vicu-restaurante-cart__totals" data-cart-totals></div>
					<a class="vicu-restaurante-cart__checkout-cta" data-cart-checkout-cta href="<?php echo esc_url( '' !== $checkout_url ? $checkout_url : '#' ); ?>"><?php esc_html_e( 'Proceder al pago', 'vicunav-restaurante' ); ?></a>
				</div>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/** Renderiza checkout editorialmente neutro y ligado al carrito. */
	public static function checkout(): string {
		$attributes = self::attributes( 'checkout' );
		$id         = wp_unique_id( 'vicu-restaurante-checkout-' );

		ob_start();
		?>
		<section <?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php esc_attr_e( 'Checkout', 'vicunav-restaurante' ); ?>">
			<p data-commerce-status role="status" aria-live="polite" aria-atomic="true"><?php esc_html_e( 'Comprobando el carrito.', 'vicunav-restaurante' ); ?></p>
			<p data-commerce-error role="alert" hidden></p>
			<div data-checkout-summary></div>
			<form data-commerce-form="checkout" class="vicu-restaurante-checkout__form" hidden>
				<label for="<?php echo esc_attr( $id ); ?>-name"><?php esc_html_e( 'Nombre', 'vicunav-restaurante' ); ?></label>
				<input id="<?php echo esc_attr( $id ); ?>-name" name="name" maxlength="100" autocomplete="name" required>
				<label for="<?php echo esc_attr( $id ); ?>-phone"><?php esc_html_e( 'Teléfono', 'vicunav-restaurante' ); ?></label>
				<input id="<?php echo esc_attr( $id ); ?>-phone" name="phone" maxlength="32" autocomplete="tel" required>
				<label for="<?php echo esc_attr( $id ); ?>-email"><?php esc_html_e( 'Correo electrónico (opcional)', 'vicunav-restaurante' ); ?></label>
				<input id="<?php echo esc_attr( $id ); ?>-email" name="email" type="email" maxlength="191" autocomplete="email">
				<div data-delivery-fields hidden>
					<label for="<?php echo esc_attr( $id ); ?>-address"><?php esc_html_e( 'Dirección de entrega', 'vicunav-restaurante' ); ?></label>
					<textarea id="<?php echo esc_attr( $id ); ?>-address" name="delivery_address" maxlength="500" autocomplete="street-address"></textarea>
					<label for="<?php echo esc_attr( $id ); ?>-instructions"><?php esc_html_e( 'Instrucciones de entrega (opcional)', 'vicunav-restaurante' ); ?></label>
					<textarea id="<?php echo esc_attr( $id ); ?>-instructions" name="delivery_instructions" maxlength="500"></textarea>
				</div>
				<label for="<?php echo esc_attr( $id ); ?>-note"><?php esc_html_e( 'Nota para el restaurante (opcional)', 'vicunav-restaurante' ); ?></label>
				<textarea id="<?php echo esc_attr( $id ); ?>-note" name="customer_note" maxlength="500"></textarea>
				<button type="submit"><?php esc_html_e( 'Crear pedido y continuar al pago manual', 'vicunav-restaurante' ); ?></button>
			</form>
			<div data-checkout-result hidden></div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/** Renderiza consulta privada y evidencia del proveedor manual. */
	public static function order_status(): string {
		$attributes = self::attributes( 'order' );
		$id         = wp_unique_id( 'vicu-restaurante-order-' );

		ob_start();
		?>
		<section <?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php esc_attr_e( 'Estado del pedido', 'vicunav-restaurante' ); ?>">
			<form data-commerce-form="order-lookup" class="vicu-restaurante-order-status__lookup">
				<label for="<?php echo esc_attr( $id ); ?>-public-id"><?php esc_html_e( 'Identificador del pedido', 'vicunav-restaurante' ); ?></label>
				<input id="<?php echo esc_attr( $id ); ?>-public-id" name="public_id" pattern="[a-f0-9-]{36}" autocomplete="off" required>
				<button type="submit"><?php esc_html_e( 'Consultar pedido', 'vicunav-restaurante' ); ?></button>
			</form>
			<p data-commerce-status role="status" aria-live="polite" aria-atomic="true"></p>
			<p data-commerce-error role="alert" hidden></p>
			<ol class="vicu-restaurante-order-status__timeline" data-order-timeline aria-label="<?php esc_attr_e( 'Línea de tiempo del pedido', 'vicunav-restaurante' ); ?>" hidden></ol>
			<div data-order-detail hidden></div>
			<div class="vicu-restaurante-order-status__actions" data-order-actions hidden>
				<button type="button" data-commerce-action="refresh-order"><?php esc_html_e( 'Actualizar estado', 'vicunav-restaurante' ); ?></button>
				<form data-commerce-form="payment-evidence" hidden>
					<label for="<?php echo esc_attr( $id ); ?>-reference"><?php esc_html_e( 'Referencia del pago manual', 'vicunav-restaurante' ); ?></label>
					<input id="<?php echo esc_attr( $id ); ?>-reference" name="reference" maxlength="191" autocomplete="off" required>
					<button type="submit"><?php esc_html_e( 'Enviar referencia', 'vicunav-restaurante' ); ?></button>
				</form>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Atributos compartidos sin datos privados de carrito, pedido o contacto.
	 *
	 * @param string $role  Rol coordinado del bloque.
	 * @param array  $extra Atributos adicionales propios del bloque llamante.
	 * @return string Atributos escapados por WordPress.
	 */
	public static function attributes( string $role, array $extra = array() ): string {
		// Solo se publica la existencia de una identidad, nunca la cookie opaca.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- La presencia no se usa como credencial.
		$has_cart_identity = is_user_logged_in() || isset( $_COOKIE[ CartSessionService::COOKIE_NAME ] );

		return get_block_wrapper_attributes(
			array_merge(
				array(
					'data-wp-interactive'           => 'vicunav/restaurante-commerce',
					'data-wp-context'               => wp_json_encode( array( 'role' => $role ) ),
					'data-wp-init'                  => 'actions.initialize',
					'data-wp-on--click'             => 'actions.handleClick',
					'data-wp-on--change'            => 'actions.handleChange',
					'data-wp-on--submit'            => 'actions.handleSubmit',
					'data-vicu-commerce-role'       => $role,
					'data-has-cart-identity'        => $has_cart_identity ? '1' : '0',
					'data-rest-cart'                => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart' ) ),
					'data-rest-carts'               => esc_url_raw( rest_url( 'vicu/v1/restaurante/carts' ) ),
					'data-rest-cart-items'          => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart/items' ) ),
					'data-rest-cart-discount'       => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart/discount' ) ),
					'data-rest-fulfillment'         => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart/fulfillment' ) ),
					'data-rest-tip'                 => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart/tip' ) ),
					'data-rest-zones'               => esc_url_raw( rest_url( 'vicu/v1/restaurante/delivery-zones' ) ),
					'data-rest-orders'              => esc_url_raw( rest_url( 'vicu/v1/restaurante/orders' ) ),
					'data-rest-nonce'               => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
					'data-locale'                   => str_replace( '_', '-', determine_locale() ),
					'data-loading-message'          => __( 'Actualizando.', 'vicunav-restaurante' ),
					'data-error-message'            => __( 'No pudimos completar la operación.', 'vicunav-restaurante' ),
					'data-conflict-message'         => __( 'Los datos cambiaron. Mostramos la versión más reciente.', 'vicunav-restaurante' ),
					'data-empty-message'            => __( 'Tu carrito está vacío.', 'vicunav-restaurante' ),
					'data-order-saved-message'      => __( 'Pedido creado. Guarda su identificador para consultarlo.', 'vicunav-restaurante' ),
					'data-invalid-discount-message' => __( 'Código inválido o expirado.', 'vicunav-restaurante' ),
				),
				$extra
			)
		);
	}
}
