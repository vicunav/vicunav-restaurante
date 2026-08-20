<?php
/**
 * Bootstrap de la suite con WordPress y MySQL reales.
 *
 * @package Vicunav_Restaurante
 */

$vicu_restaurante_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $vicu_restaurante_tests_dir || '' === $vicu_restaurante_tests_dir ) {
	$vicu_restaurante_tests_dir = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

define( 'WP_TESTS_CONFIG_FILE_PATH', dirname( __DIR__ ) . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

require_once $vicu_restaurante_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require_once dirname( __DIR__ ) . '/fixtures/core/class-post-type.php';
		require_once dirname( __DIR__ ) . '/fixtures/core/class-rest.php';
		require_once dirname( __DIR__ ) . '/fixtures/core/class-security.php';
		require_once dirname( __DIR__ ) . '/fixtures/core/class-settings.php';
		require_once dirname( __DIR__ ) . '/fixtures/pagos/class-manual-payment-provider.php';
		require_once dirname( __DIR__ ) . '/fixtures/pagos/class-payment-request-state.php';
		require_once dirname( __DIR__ ) . '/fixtures/pagos/class-payment-requests.php';

		define( 'VICU_CORE_CONTRACT_VERSION', '1.0.0' );
		define( 'VICU_PAGOS_CONTRACT_VERSION', '0.3.0' );

		require dirname( __DIR__, 2 ) . '/vicunav-restaurante.php';
	}
);

require $vicu_restaurante_tests_dir . '/includes/bootstrap.php';

/**
 * Devuelve la cola de módulos con una adaptación exclusiva para WordPress 6.6 a 6.8.
 *
 * `WP_Script_Modules::get_queue()` es público desde WordPress 6.9. En versiones
 * anteriores la suite inspecciona la propiedad privada para comprobar el mismo efecto
 * sin introducir esa compatibilidad de pruebas en el runtime.
 *
 * @return string[] IDs encolados.
 */
function vicu_restaurante_test_script_module_queue(): array {
	$modules = wp_script_modules();

	if ( method_exists( $modules, 'get_queue' ) ) {
		return $modules->get_queue();
	}

	$property   = new ReflectionProperty( $modules, 'registered' );
	$registered = $property->getValue( $modules );
	$queue      = array();

	foreach ( is_array( $registered ) ? $registered : array() as $id => $module ) {
		if ( true === ( $module['enqueue'] ?? false ) ) {
			$queue[] = (string) $id;
		}
	}

	return $queue;
}
