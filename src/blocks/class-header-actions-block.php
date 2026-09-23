<?php
/**
 * Render seguro de las acciones compactas de cabecera.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Blocks;

defined( 'ABSPATH' ) || exit;

/** Publica carrito y acceso de cuenta sin datos privados embebidos en HTML cacheable. */
final class HeaderActionsBlock {
	/**
	 * Renderiza los accesos de carrito y cuenta/pizzas guardadas.
	 *
	 * @param array $attributes Atributos del bloque (cartUrl, accountUrl).
	 * @return string Markup del bloque.
	 */
	public static function render( array $attributes ): string {
		$cart_url    = isset( $attributes['cartUrl'] ) && is_string( $attributes['cartUrl'] ) ? trim( $attributes['cartUrl'] ) : '';
		$account_url = isset( $attributes['accountUrl'] ) && is_string( $attributes['accountUrl'] ) ? trim( $attributes['accountUrl'] ) : '';
		$logged_in   = is_user_logged_in();

		$account_redirect = '' !== $account_url ? $account_url : self::current_url();
		$account_href     = $logged_in ? ( '' !== $account_url ? $account_url : '#' ) : wp_login_url( $account_redirect );
		$account_label    = $logged_in
			? __( 'Mi cuenta y pizzas guardadas', 'vicunav-restaurante' )
			: __( 'Iniciar sesión para ver tu cuenta', 'vicunav-restaurante' );

		$wrapper_attributes = CommerceBlocks::attributes(
			'header',
			array(
				'data-cart-empty-label'      => __( 'Ver carrito, vacío', 'vicunav-restaurante' ),
				/* translators: %d: número de artículos en el carrito. */
				'data-cart-with-items-label' => __( 'Ver carrito, %d artículos', 'vicunav-restaurante' ),
			)
		);

		ob_start();
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<a
				href="<?php echo esc_url( '' !== $cart_url ? $cart_url : '#' ); ?>"
				class="vicu-restaurante-header-actions__action vicu-restaurante-header-actions__cart"
				data-header-cart-link
				aria-label="<?php esc_attr_e( 'Ver carrito, vacío', 'vicunav-restaurante' ); ?>"
			>
				<?php echo self::icon_cart(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="vicu-restaurante-header-actions__badge" data-header-cart-count hidden>0</span>
			</a>
			<a
				href="<?php echo esc_url( $account_href ); ?>"
				class="vicu-restaurante-header-actions__action vicu-restaurante-header-actions__account"
				aria-label="<?php echo esc_attr( $account_label ); ?>"
			>
				<?php echo self::icon_user(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
			<span
				class="screen-reader-text"
				role="status"
				aria-live="polite"
				aria-atomic="true"
				data-header-cart-announce
			></span>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/** URL actual para redirigir tras iniciar sesión, sin exponer parámetros privados. */
	private static function current_url(): string {
		$permalink = get_permalink();
		return is_string( $permalink ) && '' !== $permalink ? $permalink : home_url( '/' );
	}

	/** Ícono de carrito, 18×18, trazo heredado de currentColor. */
	private static function icon_cart(): string {
		return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h3l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>';
	}

	/** Ícono de cuenta, 18×18, trazo heredado de currentColor. */
	private static function icon_user(): string {
		return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>';
	}
}
