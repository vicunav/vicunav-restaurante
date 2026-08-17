<?php
/**
 * Schema transaccional de reservas y ocupación.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Migrations;

use Vicu\Restaurante\Schema;

/**
 * Crea autoridades vacías sin horarios ni reservas Bonasera.
 */
final class CreateReservationStorage extends Migration {
	/**
	 * Tablas nuevas que se pueden compensar ante un fallo.
	 *
	 * @var string[]
	 */
	private array $created_tables = array();

	/** {@inheritDoc} */
	public function version(): int {
		return 8;
	}

	/** {@inheritDoc} */
	public function is_applied(): bool {
		foreach ( self::tables() as $table ) {
			if ( ! Schema::table_exists( $table ) ) {
				return false;
			}
		}

		return true;
	}

	/** {@inheritDoc} */
	public function up(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::tables() as $table ) {
			if ( ! Schema::table_exists( $table ) ) {
				$this->created_tables[] = $table;
			}
		}

		$charset      = $wpdb->get_charset_collate();
		$reservations = Schema::reservations_table_name();
		$occupancy    = Schema::reservation_occupancy_table_name();
		$events       = Schema::reservation_events_table_name();

		dbDelta(
			"CREATE TABLE {$reservations} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				confirmation_code varchar(24) NOT NULL,
				access_token_hash char(64) NOT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				status varchar(16) NOT NULL,
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				guest_name varchar(100) NOT NULL,
				guest_phone varchar(32) NOT NULL,
				guest_email varchar(191) DEFAULT NULL,
				notes varchar(500) DEFAULT NULL,
				zone_preference varchar(100) DEFAULT NULL,
				party_size int(10) unsigned NOT NULL,
				interval_minutes int(10) unsigned NOT NULL,
				local_date date NOT NULL,
				local_time time NOT NULL,
				timezone varchar(64) NOT NULL,
				starts_at_utc datetime NOT NULL,
				ends_at_utc datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				cancelled_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY confirmation_code (confirmation_code),
				KEY user_created (user_id,created_at),
				KEY status_start (status,starts_at_utc),
				KEY local_schedule (local_date,local_time,status)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$occupancy} (
				interval_start_utc datetime NOT NULL,
				occupied int(10) unsigned NOT NULL DEFAULT 0,
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (interval_start_utc)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				reservation_id bigint(20) unsigned NOT NULL,
				from_status varchar(16) DEFAULT NULL,
				to_status varchar(16) NOT NULL,
				actor_type varchar(16) NOT NULL,
				actor_id bigint(20) unsigned DEFAULT NULL,
				reason varchar(500) DEFAULT NULL,
				revision bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY reservation_revision (reservation_id,revision),
				KEY reservation_sequence (reservation_id,id)
			) ENGINE=InnoDB {$charset};"
		);

		return $this->is_applied();
	}

	/** {@inheritDoc} */
	public function down(): void {
		global $wpdb;

		foreach ( array_reverse( $this->created_tables ) as $table ) {
			// El identificador pertenece a la lista fija capturada durante up().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * Devuelve exclusivamente los nombres internos de esta migración.
	 *
	 * @return string[]
	 */
	private static function tables(): array {
		return array(
			Schema::reservations_table_name(),
			Schema::reservation_occupancy_table_name(),
			Schema::reservation_events_table_name(),
		);
	}
}
