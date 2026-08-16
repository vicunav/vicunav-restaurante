<?php
/**
 * Bootstrap de la suite fundacional.
 *
 * @package Vicunav_Restaurante
 */

$vicu_restaurante_root = dirname( __DIR__ );

require_once $vicu_restaurante_root . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $vicu_restaurante_root . '/' );
}
