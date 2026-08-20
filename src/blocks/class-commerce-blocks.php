<?php
/**
 * Render seguro de carrito, checkout y estado de pedido.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Blocks;

use Vicu\Restaurante\Settings\RestaurantSettings;

/** Publica estructuras vacías; los datos privados llegan por REST sin caché. */
final class CommerceBlocks {
	/** Renderiza la superficie coordinada del carrito. */
	public static function cart(): string {
		$attributes = self::attributes( 'cart' );
		$id         = wp_unique_id( 'vicu-restaurante-discount-' );

		ob_start();
		?>
		<section <?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php esc_attr_e( 'Carrito', 'vicunav-restaurante' ); ?>">
			<p data-commerce-status role="status" aria-live="polite" aria-atomic="true"><?php esc_html_e( 'Cargando el carrito.', 'vicunav-restaurante' ); ?></p>
			<p data-commerce-error role="alert" hidden></p>
			<p data-cart-empty hidden><?php esc_html_e( 'Tu carrito está vacío.', 'vicunav-restaurante' ); ?></p>
			<ul class="vicu-restaurante-cart__items" data-cart-items aria-label="<?php esc_attr_e( 'Productos del carrito', 'vicunav-restaurante' ); ?>"></ul>
			<div class="vicu-restaurante-cart__controls" data-cart-controls hidden>
				<label><?php esc_html_e( 'Entrega', 'vicunav-restaurante' ); ?>
					<select data-cart-fulfillment>
						<option value="pickup"><?php esc_html_e( 'Retiro en el restaurante', 'vicunav-restaurante' ); ?></option>
						<option value="delivery"><?php esc_html_e( 'Delivery', 'vicunav-restaurante' ); ?></option>
					</select>
				</label>
				<label data-cart-zone-field hidden><?php esc_html_e( 'Zona de delivery', 'vicunav-restaurante' ); ?>
					<select data-cart-zone></select>
				</label>
				<label><?php esc_html_e( 'Propina', 'vicunav-restaurante' ); ?>
					<select data-cart-tip>
						<?php foreach ( RestaurantSettings::tip_rates_bps() as $rate ) : ?>
							<option value="<?php echo esc_attr( (string) $rate ); ?>"><?php echo esc_html( number_format_i18n( $rate / 100, 2 ) . '%' ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<form data-commerce-form="discount" class="vicu-restaurante-cart__discount">
					<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Código de descuento', 'vicunav-restaurante' ); ?></label>
					<input id="<?php echo esc_attr( $id ); ?>" name="code" maxlength="64" autocomplete="off">
					<button type="submit"><?php esc_html_e( 'Aplicar', 'vicunav-restaurante' ); ?></button>
					<button type="button" data-commerce-action="remove-discount"><?php esc_html_e( 'Quitar descuento', 'vicunav-restaurante' ); ?></button>
				</form>
			</div>
			<dl class="vicu-restaurante-cart__totals" data-cart-totals hidden></dl>
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
	 * @param string $role Rol coordinado del bloque.
	 * @return string Atributos escapados por WordPress.
	 */
	private static function attributes( string $role ): string {
		return get_block_wrapper_attributes(
			array(
				'data-wp-interactive'      => 'vicunav/restaurante-commerce',
				'data-wp-context'          => wp_json_encode( array( 'role' => $role ) ),
				'data-wp-init'             => 'actions.initialize',
				'data-wp-on--click'        => 'actions.handleClick',
				'data-wp-on--change'       => 'actions.handleChange',
				'data-wp-on--submit'       => 'actions.handleSubmit',
				'data-vicu-commerce-role'  => $role,
				'data-rest-cart'           => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart' ) ),
				'data-rest-carts'          => esc_url_raw( rest_url( 'vicu/v1/restaurante/carts' ) ),
				'data-rest-cart-items'     => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart/items' ) ),
				'data-rest-cart-discount'  => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart/discount' ) ),
				'data-rest-fulfillment'    => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart/fulfillment' ) ),
				'data-rest-tip'            => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart/tip' ) ),
				'data-rest-zones'          => esc_url_raw( rest_url( 'vicu/v1/restaurante/delivery-zones' ) ),
				'data-rest-orders'         => esc_url_raw( rest_url( 'vicu/v1/restaurante/orders' ) ),
				'data-rest-nonce'          => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'data-locale'              => str_replace( '_', '-', determine_locale() ),
				'data-loading-message'     => __( 'Actualizando.', 'vicunav-restaurante' ),
				'data-error-message'       => __( 'No pudimos completar la operación.', 'vicunav-restaurante' ),
				'data-conflict-message'    => __( 'Los datos cambiaron. Mostramos la versión más reciente.', 'vicunav-restaurante' ),
				'data-empty-message'       => __( 'Tu carrito está vacío.', 'vicunav-restaurante' ),
				'data-order-saved-message' => __( 'Pedido creado. Guarda su identificador para consultarlo.', 'vicunav-restaurante' ),
			)
		);
	}
}
