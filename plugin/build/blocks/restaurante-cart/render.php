<?php
/**
 * Render dinámico del carrito.
 *
 * @package Vicunav_Restaurante
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Vicu\Restaurante\Blocks\CommerceBlocks::cart( is_array( $attributes ?? null ) ? $attributes : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
