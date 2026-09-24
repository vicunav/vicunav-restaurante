<?php
/**
 * Instalación y versionado de persistencia.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Mantiene el schema interno en su versión contractual.
 *
 * @internal
 */
final class Installer {
	private const DB_VERSION        = '2';
	private const OPTION_DB_VERSION = 'vicu_pagos_db_version';

	/**
	 * Instala o actualiza el schema cuando cambia su versión.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Crea las tablas InnoDB y guarda su versión solo si ambas existen.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$request_table    = PaymentRequestRepository::table_name();
		$submission_table = ManualSubmissionRepository::table_name();
		$charset_collate  = $wpdb->get_charset_collate();
		$request_sql      = "CREATE TABLE {$request_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned DEFAULT NULL,
			reference_hash char(64) NOT NULL,
			external_type varchar(64) NOT NULL,
			external_id varchar(191) NOT NULL,
			amount_minor bigint(20) unsigned NOT NULL,
			currency char(3) NOT NULL,
			provider varchar(32) DEFAULT NULL,
			state varchar(32) NOT NULL,
			revision bigint(20) unsigned NOT NULL DEFAULT 1,
			expires_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY reference_hash (reference_hash),
			UNIQUE KEY post_id (post_id),
			KEY due_requests (state, expires_at)
		) ENGINE=InnoDB {$charset_collate};";

		$submission_sql = "CREATE TABLE {$submission_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			idempotency_hash char(64) NOT NULL,
			proof_reference varchar(191) NOT NULL,
			request_revision bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_idempotency (request_id, idempotency_hash),
			KEY request_history (request_id, id)
		) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $request_sql );
		dbDelta( $submission_sql );

		$request_query    = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $request_table ) );
		$submission_query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $submission_table ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$request_exists = $request_table === $wpdb->get_var( $request_query );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$submission_exists = $submission_table === $wpdb->get_var( $submission_query );

		if ( $request_exists && $submission_exists ) {
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
		}
	}
}
