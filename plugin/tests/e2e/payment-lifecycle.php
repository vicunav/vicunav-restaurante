<?php
/**
 * Flujo E2E ejecutado dentro de un sitio WordPress real.
 *
 * @package Vicunav_Restaurante
 */

// El script de consola escribe resultados en stdout y errores en stderr.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

$vicu_wp_load = getenv( 'VICU_E2E_WP_LOAD' );

if ( false === $vicu_wp_load || ! is_readable( $vicu_wp_load ) ) {
	fwrite( STDERR, "VICU_E2E_WP_LOAD debe apuntar a un wp-load.php legible.\n" );
	exit( 1 );
}

require $vicu_wp_load;
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use Vicu\Restaurante\Payments\ExpirationScheduler;
use Vicu\Restaurante\Payments\ManualPaymentProvider;
use Vicu\Restaurante\Payments\ManualSubmissionRepository;
use Vicu\Restaurante\Payments\PaymentRequests;
use Vicu\Restaurante\Payments\PaymentRequestState;

$vicu_plugin = 'vicunav-restaurante/vicunav-restaurante.php';

if ( ! is_plugin_active( $vicu_plugin ) ) {
	fwrite( STDERR, "Vicunav Pagos debe estar activo antes del E2E.\n" );
	exit( 1 );
}

$vicu_pagos_events = array();
$vicu_pagos_record = static function ( array $payload ) use ( &$vicu_pagos_events ): void {
	$vicu_pagos_events[] = $payload;
};

add_action( 'vicu_pagos_creado', $vicu_pagos_record );
add_action( 'vicu_pagos_comprobante_recibido', $vicu_pagos_record );
add_action( 'vicu_pagos_confirmado', $vicu_pagos_record );

$vicu_pagos_previous_manual = ManualPaymentProvider::get_configuration();
$vicu_pagos_cleanup_needed  = true;
$vicu_pagos_id              = 0;

register_shutdown_function(
	static function () use ( &$vicu_pagos_cleanup_needed, &$vicu_pagos_id, $vicu_pagos_previous_manual ): void {
		if ( ! $vicu_pagos_cleanup_needed ) {
			return;
		}

		if ( 0 < $vicu_pagos_id ) {
			wp_delete_post( $vicu_pagos_id, true );
		}

		ManualPaymentProvider::configure(
			array( 'enabled' => $vicu_pagos_previous_manual['enabled'] )
		);
	}
);

$vicu_pagos_manual_configuration = ManualPaymentProvider::configure( array( 'enabled' => true ) );

if ( is_wp_error( $vicu_pagos_manual_configuration ) ) {
	fwrite( STDERR, "No se pudo habilitar el proveedor manual para el E2E.\n" );
	exit( 1 );
}

$vicu_pagos_external_id = 'E2E-' . gmdate( 'YmdHis' ) . '-' . wp_rand( 1000, 9999 );
$vicu_pagos_request     = PaymentRequests::create(
	array(
		'external_type' => 'vicu_e2e',
		'external_id'   => $vicu_pagos_external_id,
		'amount_minor'  => 4321,
		'currency'      => 'USD',
		'expires_at'    => gmdate( DATE_RFC3339, time() + HOUR_IN_SECONDS ),
	)
);

if ( is_wp_error( $vicu_pagos_request ) ) {
	fwrite( STDERR, $vicu_pagos_request->get_error_code() . "\n" );
	exit( 1 );
}

$vicu_pagos_id      = $vicu_pagos_request['id'];
$vicu_pagos_request = PaymentRequests::create(
	array(
		'external_type' => 'vicu_e2e',
		'external_id'   => $vicu_pagos_external_id,
		'amount_minor'  => 4321,
		'currency'      => 'USD',
		'expires_at'    => $vicu_pagos_request['expires_at'],
	)
);

if ( is_wp_error( $vicu_pagos_request ) || $vicu_pagos_id !== $vicu_pagos_request['id'] ) {
	fwrite( STDERR, "La creación E2E no fue idempotente.\n" );
	exit( 1 );
}

$vicu_pagos_submission = array(
	'proof_reference' => 'evidence:' . $vicu_pagos_external_id . ':1',
	'idempotency_key' => 'manual:' . $vicu_pagos_external_id . ':1',
);
$vicu_pagos_manual     = ManualPaymentProvider::submit_proof(
	$vicu_pagos_id,
	$vicu_pagos_submission,
	1
);
$vicu_pagos_retry      = ManualPaymentProvider::submit_proof(
	$vicu_pagos_id,
	$vicu_pagos_submission,
	1
);
$vicu_pagos_request    = is_wp_error( $vicu_pagos_manual )
	? $vicu_pagos_manual
	: PaymentRequests::transition( $vicu_pagos_id, PaymentRequestState::CONFIRMED, 2 );

global $wpdb;

$vicu_pagos_submission_table = ManualSubmissionRepository::table_name();
$vicu_pagos_submission_query = $wpdb->prepare(
	'SELECT COUNT(*) FROM %i WHERE request_id = %d',
	$vicu_pagos_submission_table,
	$vicu_pagos_id
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
$vicu_pagos_submission_count = (int) $wpdb->get_var( $vicu_pagos_submission_query );

if (
	is_wp_error( $vicu_pagos_manual ) ||
	is_wp_error( $vicu_pagos_retry ) ||
	$vicu_pagos_manual !== $vicu_pagos_retry ||
	is_wp_error( $vicu_pagos_request ) ||
	PaymentRequestState::CONFIRMED !== $vicu_pagos_request['state'] ||
	'manual' !== $vicu_pagos_request['provider'] ||
	3 !== $vicu_pagos_request['revision'] ||
	1 !== $vicu_pagos_submission_count ||
	3 !== count( $vicu_pagos_events ) ||
	'1.0.0' !== $vicu_pagos_events[0]['payload_version'] ||
	'1.0.0' !== $vicu_pagos_events[1]['payload_version'] ||
	'1.0.0' !== $vicu_pagos_events[2]['payload_version'] ||
	'comprobante_recibido' !== $vicu_pagos_events[1]['event']
) {
	fwrite( STDERR, "El flujo o los eventos E2E no coinciden con el contrato.\n" );
	exit( 1 );
}

$vicu_pagos_was_active = is_plugin_active( $vicu_plugin );
deactivate_plugins( $vicu_plugin, true );
$vicu_pagos_activation_error = activate_plugin( $vicu_plugin, '', false, true );

if (
	is_wp_error( $vicu_pagos_activation_error ) ||
	false === wp_next_scheduled( ExpirationScheduler::HOOK )
) {
	fwrite( STDERR, "La desactivación o reactivación E2E produjo un error.\n" );
	exit( 1 );
}

if ( ! $vicu_pagos_was_active ) {
	deactivate_plugins( $vicu_plugin, true );
}

wp_delete_post( $vicu_pagos_id, true );
ManualPaymentProvider::configure( array( 'enabled' => $vicu_pagos_previous_manual['enabled'] ) );
$vicu_pagos_cleanup_needed = false;

fwrite(
	STDOUT,
	wp_json_encode(
		array(
			'request_id'  => $vicu_pagos_id,
			'state'       => PaymentRequestState::CONFIRMED,
			'revision'    => 3,
			'events'      => array( 'creado', 'comprobante_recibido', 'confirmado' ),
			'submissions' => 1,
			'activation'  => 'ok',
		),
		JSON_PRETTY_PRINT
	) . "\n"
);

// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
