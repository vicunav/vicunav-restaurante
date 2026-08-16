<?php
/**
 * Instalación y migraciones versionadas del vertical.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante;

use Throwable;
use Vicu\Restaurante\Migrations\CreateMigrationLedger;
use Vicu\Restaurante\Migrations\Migration;

/**
 * Ejecuta únicamente migraciones pendientes y compensa fallos nuevos.
 *
 * @internal
 */
final class Installer {
	public const ERROR_INSTALLATION = 'vicu_restaurante_installation_failed';

	private const OPTION_DB_VERSION = 'vicu_restaurante_db_version';

	/**
	 * Actualiza el schema solo cuando no coincide con la versión declarada.
	 *
	 * @return bool
	 */
	public static function maybe_upgrade(): bool {
		if ( (int) VICU_RESTAURANTE_DB_VERSION === self::current_version() ) {
			return true;
		}

		return self::install();
	}

	/**
	 * Ejecuta migraciones en orden y revierte las nuevas ante un fallo.
	 *
	 * @param Migration[]|null $migrations Migraciones inyectadas o lista canónica.
	 * @return bool
	 */
	public static function install( ?array $migrations = null ): bool {
		$migrations       = $migrations ?? self::migrations();
		$previous_option  = get_option( self::OPTION_DB_VERSION, null );
		$previous_version = self::current_version();
		$latest_version   = $previous_version;
		$applied          = array();

		usort(
			$migrations,
			static function ( Migration $left, Migration $right ): int {
				return $left->version() <=> $right->version();
			}
		);

		foreach ( $migrations as $migration ) {
			if ( ! $migration instanceof Migration ) {
				self::restore_version( $previous_option );
				return false;
			}

			if ( $migration->version() <= $previous_version ) {
				continue;
			}

			$existed = $migration->is_applied();

			try {
				if ( ! $migration->up() ) {
					if ( ! $existed ) {
						$migration->down();
					}

					self::rollback( $applied );
					self::restore_version( $previous_option );

					return false;
				}

				if ( ! $existed ) {
					$applied[] = $migration;
				}

				if ( ! self::record_migration( $migration->version() ) ) {
					self::rollback( $applied );
					self::restore_version( $previous_option );

					return false;
				}

				$latest_version = $migration->version();
			} catch ( Throwable $error ) {
				unset( $error );

				if ( ! $existed && ! in_array( $migration, $applied, true ) ) {
					$migration->down();
				}

				self::rollback( $applied );
				self::restore_version( $previous_option );

				return false;
			}
		}

		if ( 1 > $latest_version || ! Schema::table_exists( Schema::migration_table_name() ) ) {
			self::rollback( $applied );
			self::restore_version( $previous_option );
			return false;
		}

		update_option( self::OPTION_DB_VERSION, (string) $latest_version, false );

		return 0 === strcmp( (string) $latest_version, (string) get_option( self::OPTION_DB_VERSION ) );
	}

	/**
	 * Devuelve la versión confirmada por opción y ledger.
	 *
	 * @return int
	 */
	public static function current_version(): int {
		global $wpdb;

		$table_name = Schema::migration_table_name();

		if ( ! Schema::table_exists( $table_name ) ) {
			return 0;
		}

		// El identificador proviene exclusivamente del prefijo de WordPress y un sufijo fijo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ledger_version = (int) $wpdb->get_var( "SELECT MAX(version) FROM {$table_name}" );
		$option_version = (int) get_option( self::OPTION_DB_VERSION, 0 );

		return min( $ledger_version, $option_version );
	}

	/**
	 * Lista canónica de migraciones conocidas.
	 *
	 * @return Migration[]
	 */
	private static function migrations(): array {
		return array(
			new CreateMigrationLedger(),
		);
	}

	/**
	 * Registra una versión después de aplicar su cambio físico.
	 *
	 * @param int $version Versión aplicada.
	 * @return bool
	 */
	private static function record_migration( int $version ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->replace(
			Schema::migration_table_name(),
			array(
				'version'    => $version,
				'applied_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Compensa migraciones recién aplicadas en orden inverso.
	 *
	 * @param Migration[] $migrations Migraciones nuevas.
	 * @return void
	 */
	private static function rollback( array $migrations ): void {
		foreach ( array_reverse( $migrations ) as $migration ) {
			self::delete_migration_record( $migration->version() );
			$migration->down();
		}
	}

	/**
	 * Elimina del ledger una versión compensada.
	 *
	 * @param int $version Versión compensada.
	 * @return void
	 */
	private static function delete_migration_record( int $version ): void {
		global $wpdb;

		if ( ! Schema::table_exists( Schema::migration_table_name() ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			Schema::migration_table_name(),
			array( 'version' => $version ),
			array( '%d' )
		);
	}

	/**
	 * Restaura la opción exactamente a su estado previo.
	 *
	 * @param mixed $previous_value Valor previo o null cuando no existía.
	 * @return void
	 */
	private static function restore_version( mixed $previous_value ): void {
		if ( null === $previous_value ) {
			delete_option( self::OPTION_DB_VERSION );
			return;
		}

		update_option( self::OPTION_DB_VERSION, $previous_value, false );
	}
}
