<?php
/**
 * Configuración reproducible de WordPress para PHPUnit.
 *
 * @package Vicunav_Restaurante
 */

$vicu_restaurante_db_name     = getenv( 'WP_TESTS_DB_NAME' );
$vicu_restaurante_db_user     = getenv( 'WP_TESTS_DB_USER' );
$vicu_restaurante_db_password = getenv( 'WP_TESTS_DB_PASSWORD' );
$vicu_restaurante_db_host     = getenv( 'WP_TESTS_DB_HOST' );
$vicu_restaurante_prefix      = getenv( 'WP_TESTS_TABLE_PREFIX' );

define( 'ABSPATH', dirname( __DIR__ ) . '/vendor/wordpress/' );
define( 'DB_NAME', false !== $vicu_restaurante_db_name ? $vicu_restaurante_db_name : 'wordpress_test' );
define( 'DB_USER', false !== $vicu_restaurante_db_user ? $vicu_restaurante_db_user : 'root' );
define( 'DB_PASSWORD', false !== $vicu_restaurante_db_password ? $vicu_restaurante_db_password : '' );
define( 'DB_HOST', false !== $vicu_restaurante_db_host ? $vicu_restaurante_db_host : '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// La suite de WordPress exige esta variable en el scope del archivo de configuración.
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$table_prefix = false !== $vicu_restaurante_prefix ? $vicu_restaurante_prefix : 'wptests_vicu_restaurante_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Pruebas de Vicunav Restaurante' );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );
define( 'WP_TESTS_MULTISITE', false );

define( 'AUTH_KEY', 'pruebas' );
define( 'SECURE_AUTH_KEY', 'pruebas' );
define( 'LOGGED_IN_KEY', 'pruebas' );
define( 'NONCE_KEY', 'pruebas' );
define( 'AUTH_SALT', 'pruebas' );
define( 'SECURE_AUTH_SALT', 'pruebas' );
define( 'LOGGED_IN_SALT', 'pruebas' );
define( 'NONCE_SALT', 'pruebas' );
