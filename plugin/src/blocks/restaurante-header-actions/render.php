<?php
/**
 * Render dinámico de las acciones compactas de cabecera.
 *
 * @package Vicunav_Restaurante
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Vicu\Restaurante\Blocks\HeaderActionsBlock::render( is_array( $attributes ?? null ) ? $attributes : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
