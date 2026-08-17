<?php
/**
 * Render dinámico del menú.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Blocks\MenuBlock;

echo MenuBlock::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
