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

require_once __DIR__ . '/fixtures/core/class-post-type.php';
require_once __DIR__ . '/fixtures/core/class-rest.php';
require_once __DIR__ . '/fixtures/core/class-security.php';
require_once __DIR__ . '/fixtures/core/class-settings.php';
require_once __DIR__ . '/fixtures/pagos/class-manual-payment-provider.php';
require_once __DIR__ . '/fixtures/pagos/class-payment-request-state.php';
require_once __DIR__ . '/fixtures/pagos/class-payment-requests.php';

define( 'VICU_CORE_CONTRACT_VERSION', '1.0.0' );
define( 'VICU_PAGOS_CONTRACT_VERSION', '0.3.0' );

$GLOBALS['vicu_restaurante_test_actions']              = array();
$GLOBALS['vicu_restaurante_test_activation_hooks']     = array();
$GLOBALS['vicu_restaurante_test_fired_actions']        = array();
$GLOBALS['vicu_restaurante_test_can_activate_plugins'] = true;

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
 * Sustituto mínimo de do_action() que conserva la evidencia de publicación.
 *
 * @param string $hook Nombre del action.
 * @param mixed  ...$args Argumentos publicados.
 * @return void
 */
function do_action( string $hook, mixed ...$args ): void {
	global $vicu_restaurante_test_fired_actions;

	$vicu_restaurante_test_fired_actions[ $hook ][] = $args;
}

/**
 * Informa cuántas veces se publicó un action durante la prueba.
 *
 * @param string $hook Nombre del action.
 * @return int
 */
function did_action( string $hook ): int {
	global $vicu_restaurante_test_fired_actions;

	return count( $vicu_restaurante_test_fired_actions[ $hook ] ?? array() );
}

/**
 * Sustituto de autorización para el aviso administrativo.
 *
 * @param string $capability Capability comprobada.
 * @return bool
 */
function current_user_can( string $capability ): bool {
	global $vicu_restaurante_test_can_activate_plugins;

	return 'activate_plugins' === $capability && $vicu_restaurante_test_can_activate_plugins;
}

/**
 * Sustituto de traducción y escape para mensajes estáticos.
 *
 * @param string $text Texto original.
 * @param string $domain Dominio de traducción.
 * @return string
 */
function esc_html__( string $text, string $domain ): string {
	unset( $domain );

	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

require_once $vicu_restaurante_root . '/vicunav-restaurante.php';
