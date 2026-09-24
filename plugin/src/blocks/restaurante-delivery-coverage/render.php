<?php
/**
 * Render dinámico de la cobertura de entrega.
 *
 * @package Vicunav_Restaurante
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Vicu\Restaurante\Blocks\DeliveryCoverageBlock::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
