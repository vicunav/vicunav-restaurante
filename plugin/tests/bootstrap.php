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

$GLOBALS['vicu_restaurante_test_actions']            = array();
$GLOBALS['vicu_restaurante_test_activation_hooks']   = array();
$GLOBALS['vicu_restaurante_test_deactivation_hooks'] = array();

/**
 * Sustituto mínimo de add_action() para probar el bootstrap aislado.
 *
 * @param string   $hook          Nombre del action.
 * @param callable $callback      Callback registrado.
 * @param int      $priority      Prioridad del callback.
 * @param int      $accepted_args Cantidad de argumentos aceptados.
 * @return void
 */
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $vicu_restaurante_test_actions;

	$vicu_restaurante_test_actions[ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

/**
 * Sustituto mínimo de add_filter(), equivalente para la inspección de hooks.
 *
 * @param string   $hook          Nombre del filtro.
 * @param callable $callback      Callback registrado.
 * @param int      $priority      Prioridad del callback.
 * @param int      $accepted_args Cantidad de argumentos aceptados.
 * @return void
 */
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	add_action( $hook, $callback, $priority, $accepted_args );
}

/**
 * Sustituto del registro de activación para inspeccionar el entry point.
 *
 * @param string   $file     Archivo principal del plugin.
 * @param callable $callback Callback de activación.
 * @return void
 */
function register_activation_hook( string $file, callable $callback ): void {
	global $vicu_restaurante_test_activation_hooks;

	$vicu_restaurante_test_activation_hooks[] = array(
		'file'     => $file,
		'callback' => $callback,
	);
}

/**
 * Sustituto del registro de desactivación para inspeccionar el entry point.
 *
 * @param string   $file     Archivo principal del plugin.
 * @param callable $callback Callback de desactivación.
 * @return void
 */
function register_deactivation_hook( string $file, callable $callback ): void {
	global $vicu_restaurante_test_deactivation_hooks;

	$vicu_restaurante_test_deactivation_hooks[] = array(
		'file'     => $file,
		'callback' => $callback,
	);
}

require_once $vicu_restaurante_root . '/vicunav-restaurante.php';
