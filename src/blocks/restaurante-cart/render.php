<?php
/**
 * Render dinámico del carrito.
 *
 * @package Vicunav_Restaurante
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Vicu\Restaurante\Blocks\CommerceBlocks::cart(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
