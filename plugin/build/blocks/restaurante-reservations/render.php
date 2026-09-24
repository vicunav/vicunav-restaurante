<?php
/**
 * Render dinámico de reservas.
 *
 * @package Vicunav_Restaurante
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Vicu\Restaurante\Blocks\ReservationBlock::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
