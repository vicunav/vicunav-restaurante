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
		remove_action( 'plugins_loaded', 'Vicu\\Restaurante\\bootstrap', 20 );
	}
);

require $vicu_restaurante_tests_dir . '/includes/bootstrap.php';
