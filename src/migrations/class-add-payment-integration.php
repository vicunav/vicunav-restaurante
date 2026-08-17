<?php
/**
 * Persistencia privada de integración con pagos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Migrations;

use Vicu\Restaurante\Schema;

/**
 * Añade observación de pagos y evidencia textual sin datos de demostración.
 */
final class AddPaymentIntegration extends Migration {
	/**
	 * Recursos creados durante este intento.
	 *
	 * @var string[]
	 */
	private array $added_columns = array();

	/**
	 * Indica si esta ejecución creó la tabla completa.
	 *
	 * @var bool
	 */
	private bool $created_evidence_table = false;

	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 7;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_applied(): bool {
		$orders = Schema::orders_table_name();

		return Schema::table_exists( Schema::payment_evidence_table_name() ) &&
			Schema::column_exists( $orders, 'payment_state' ) &&
			Schema::column_exists( $orders, 'payment_provider' ) &&
			Schema::column_exists( $orders, 'payment_last_error' ) &&
			Schema::column_exists( $orders, 'payment_last_reconciled_at' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$evidence = Schema::payment_evidence_table_name();
		$charset  = $wpdb->get_charset_collate();

		if ( ! Schema::table_exists( $evidence ) ) {
			$this->created_evidence_table = true;
		}

		dbDelta(
			"CREATE TABLE {$evidence} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				idempotency_hash char(64) NOT NULL,
				request_hash char(64) NOT NULL,
				reference_text varchar(191) NOT NULL,
				payment_submission_id bigint(20) unsigned DEFAULT NULL,
				payment_request_revision bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(16) NOT NULL DEFAULT 'pending',
				last_error varchar(64) DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY order_idempotency (order_id,idempotency_hash),
				KEY order_created (order_id,created_at),
				KEY status_updated (status,updated_at)
			) ENGINE=InnoDB {$charset};"
		);

		$columns = array(
			'payment_state'              => 'varchar(32) DEFAULT NULL AFTER payment_revision',
			'payment_provider'           => 'varchar(32) DEFAULT NULL AFTER payment_state',
			'payment_last_error'         => 'varchar(64) DEFAULT NULL AFTER payment_provider',
			'payment_last_reconciled_at' => 'datetime DEFAULT NULL AFTER payment_last_error',
		);
		$orders  = Schema::orders_table_name();

		foreach ( $columns as $name => $definition ) {
			if ( Schema::column_exists( $orders, $name ) ) {
				continue;
			}

			// Los identificadores y definiciones provienen exclusivamente del mapa fijo anterior.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $wpdb->query( "ALTER TABLE {$orders} ADD COLUMN {$name} {$definition}" ) ) {
				return false;
			}

			$this->added_columns[] = $name;
		}

		return $this->is_applied();
	}

	/**
	 * {@inheritDoc}
	 */
	public function down(): void {
		global $wpdb;

		$orders = Schema::orders_table_name();

		foreach ( array_reverse( $this->added_columns ) as $column ) {
			// Los identificadores pertenecen a la lista fija capturada durante up().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$orders} DROP COLUMN {$column}" );
		}

		if ( $this->created_evidence_table ) {
			$table = Schema::payment_evidence_table_name();
			// El identificador pertenece al schema fijo.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
