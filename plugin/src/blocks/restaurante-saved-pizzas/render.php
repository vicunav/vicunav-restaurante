<?php
/**
 * Render dinámico de pizzas guardadas.
 *
 * @package Vicunav_Restaurante
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Vicu\Restaurante\Blocks\SavedPizzasBlock::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
