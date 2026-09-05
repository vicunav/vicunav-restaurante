<?php
/**
 * Render dinámico del menú.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Blocks\MenuBlock;

echo MenuBlock::render( is_array( $attributes ?? null ) ? $attributes : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
