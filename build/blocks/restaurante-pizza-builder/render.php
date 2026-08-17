<?php
/**
 * Render dinámico del constructor de pizzas.
 *
 * @package Vicunav_Restaurante
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Vicu\Restaurante\Blocks\PizzaBuilderBlock::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
