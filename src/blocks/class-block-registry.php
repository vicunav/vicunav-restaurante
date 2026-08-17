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
		foreach ( array( 'restaurante-menu', 'restaurante-pizza-builder' ) as $block ) {
			$path = VICU_RESTAURANTE_PATH . 'build/blocks/' . $block;

			if ( file_exists( $path . '/block.json' ) ) {
				register_block_type_from_metadata( $path );
			}
		}
	}
}
