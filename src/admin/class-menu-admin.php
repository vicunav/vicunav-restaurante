<?php
/**
 * Columnas administrativas del menú.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Admin;

use Vicu\Restaurante\Menu\MenuItemPostType;
use Vicu\Restaurante\Menu\MenuMeta;

/**
 * Añade señales operativas a la lista nativa y paginada de WordPress.
 */
final class MenuAdmin {
	/**
	 * Evita filtros duplicados.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Enlaza columnas del CPT.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_filter( 'manage_' . MenuItemPostType::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . MenuItemPostType::POST_TYPE . '_posts_custom_column', array( self::class, 'render_column' ), 10, 2 );
		self::$hooks_registered = true;
	}

	/**
	 * Incorpora precio y disponibilidad sin retirar columnas nativas.
	 *
	 * @param array<string, string> $columns Columnas existentes.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$columns['vicu_rest_price']     = __( 'Precio', 'vicunav-restaurante' );
		$columns['vicu_rest_available'] = __( 'Disponibilidad', 'vicunav-restaurante' );

		return $columns;
	}

	/**
	 * Renderiza valores operativos escapados.
	 *
	 * @param string $column  Columna solicitada.
	 * @param int    $post_id Item listado.
	 * @return void
	 */
	public static function render_column( string $column, int $post_id ): void {
		if ( 'vicu_rest_price' === $column ) {
			$price    = MenuMeta::sanitize_non_negative_int( get_post_meta( $post_id, MenuMeta::PRICE_MINOR, true ) );
			$currency = MenuMeta::sanitize_currency( get_post_meta( $post_id, MenuMeta::CURRENCY, true ) );
			echo esc_html( (string) $price . ' ' . $currency );
			return;
		}

		if ( 'vicu_rest_available' === $column ) {
			$available = rest_sanitize_boolean( get_post_meta( $post_id, MenuMeta::AVAILABLE, true ) );
			echo $available
				? esc_html__( 'Disponible', 'vicunav-restaurante' )
				: esc_html__( 'No disponible', 'vicunav-restaurante' );
		}
	}
}
