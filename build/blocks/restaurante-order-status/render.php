<?php
/**
 * Render dinámico del estado de pedido.
 *
 * @package Vicunav_Restaurante
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Vicu\Restaurante\Blocks\CommerceBlocks::order_status(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
