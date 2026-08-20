<?php
/**
 * Registro de bloques dinámicos del vertical.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Blocks;

/**
 * Registra cada bloque desde metadata compilada compatible con WordPress 6.6.
 */
final class BlockRegistry {
	/**
	 * Evita registrar el hook más de una vez.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/** Enlaza el registro al lifecycle de WordPress. */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'init', array( self::class, 'register_blocks' ) );
		self::$hooks_registered = true;
	}

	/** Registra bloques individuales sin depender de APIs posteriores a 6.6. */
	public static function register_blocks(): void {
		self::register_commerce_assets();

		foreach ( array( 'restaurante-menu', 'restaurante-pizza-builder', 'restaurante-cart', 'restaurante-checkout', 'restaurante-order-status' ) as $block ) {
			$path = VICU_RESTAURANTE_PATH . 'build/blocks/' . $block;

			if ( file_exists( $path . '/block.json' ) ) {
				register_block_type_from_metadata( $path );
			}
		}
	}

	/** Registra una única instancia del store y los estilos coordinados. */
	private static function register_commerce_assets(): void {
		$directory = VICU_RESTAURANTE_PATH . 'build/blocks/restaurante-commerce-assets/';
		$asset     = file_exists( $directory . 'view.asset.php' ) ? require $directory . 'view.asset.php' : null;

		if ( ! is_array( $asset ) || ! file_exists( $directory . 'view.js' ) ) {
			return;
		}

		wp_register_script_module(
			'vicu-restaurante-commerce',
			plugins_url( 'build/blocks/restaurante-commerce-assets/view.js', VICU_RESTAURANTE_PLUGIN_FILE ),
			(array) ( $asset['dependencies'] ?? array() ),
			(string) ( $asset['version'] ?? VICU_RESTAURANTE_VERSION )
		);

		$style_path = VICU_RESTAURANTE_PATH . 'build/blocks/restaurante-cart/style-index.css';
		if ( file_exists( $style_path ) ) {
			wp_register_style(
				'vicu-restaurante-commerce-style',
				plugins_url( 'build/blocks/restaurante-cart/style-index.css', VICU_RESTAURANTE_PLUGIN_FILE ),
				array(),
				VICU_RESTAURANTE_VERSION
			);
		}
	}
}
