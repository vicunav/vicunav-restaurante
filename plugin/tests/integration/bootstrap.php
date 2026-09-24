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

/**
 * Limpia únicamente la tabla interna entre pruebas que ejercen el servicio.
 *
 * @return void
 */
function vicu_restaurante_reset_payment_requests(): void {
	global $wpdb;

	delete_option( 'vicu_pagos_manual_enabled' );

	$post_ids = get_posts(
		array(
			'post_type'      => 'vicu_payment_req',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		)
	);

	foreach ( $post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	$request_table    = \Vicu\Restaurante\Payments\PaymentRequestRepository::table_name();
	$submission_table = \Vicu\Restaurante\Payments\ManualSubmissionRepository::table_name();

	// Las tablas pertenecen a la base aislada y sus nombres provienen del prefijo de pruebas.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "TRUNCATE TABLE {$submission_table}" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "TRUNCATE TABLE {$request_table}" );
}
