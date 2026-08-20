<?php
/**
 * Render dinámico del checkout.
 *
 * @package Vicunav_Restaurante
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Vicu\Restaurante\Blocks\CommerceBlocks::checkout(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
