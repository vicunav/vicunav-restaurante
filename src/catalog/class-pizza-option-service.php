<?php
/**
 * Servicio autoritativo de opciones de pizza.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Catalog;

use Vicu\Restaurante\Schema;
use WP_Error;

/**
 * Gestiona tamaños, masas y salsas con compare-and-swap.
 */
final class PizzaOptionService {
	/**
	 * Crea una opción y una revisión global.
	 *
	 * @param array<string, mixed> $input Datos completos.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( array $input ): array|WP_Error {
		global $wpdb;

		$data = CatalogValidator::option( $input );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! CatalogDatabase::begin() ) {
			return CatalogDatabase::storage_error();
		}

		$public_id = wp_generate_uuid4();
		$now       = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			Schema::pizza_options_table_name(),
			array(
				'public_id'            => $public_id,
				'type'                 => $data['type'],
				'name'                 => $data['name'],
				'price_modifier_minor' => $data['price_modifier_minor'],
				'available'            => $data['available'] ? 1 : 0,
				'display_order'        => $data['display_order'],
				'revision'             => 1,
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted || ! AvailabilityRevision::bump_in_transaction() || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::storage_error();
		}

		AvailabilityRevision::clear_cache();

		return self::find( $public_id ) ?? CatalogDatabase::storage_error();
	}

	/**
	 * Sustituye una opción con revisión esperada.
	 *
	 * @param string               $public_id         UUID público.
	 * @param int                  $expected_revision Revisión esperada.
	 * @param array<string, mixed> $input             Datos completos.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( string $public_id, int $expected_revision, array $input ): array|WP_Error {
		global $wpdb;

		$data    = CatalogValidator::option( $input );
		$current = self::find( $public_id );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( null === $current ) {
			return CatalogDatabase::not_found();
		}

		if ( $current['revision'] !== $expected_revision ) {
			return CatalogDatabase::stale_error( $current['revision'] );
		}

		if ( ! CatalogDatabase::begin() ) {
			return CatalogDatabase::storage_error();
		}

		$table = Schema::pizza_options_table_name();
		// El identificador de tabla es fijo y todos los valores usan placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET type = %s, name = %s, price_modifier_minor = %d, available = %d,
					display_order = %d, revision = revision + 1, updated_at = %s
				WHERE public_id = %s AND revision = %d",
				$data['type'],
				$data['name'],
				$data['price_modifier_minor'],
				$data['available'] ? 1 : 0,
				$data['display_order'],
				current_time( 'mysql', true ),
				$public_id,
				$expected_revision
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( 1 !== $updated ) {
			CatalogDatabase::rollback();
			$latest = self::find( $public_id );

			return null === $latest ? CatalogDatabase::not_found() : CatalogDatabase::stale_error( $latest['revision'] );
		}

		if ( ! AvailabilityRevision::bump_in_transaction() || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::storage_error();
		}

		AvailabilityRevision::clear_cache();

		return self::find( $public_id ) ?? CatalogDatabase::storage_error();
	}

	/**
	 * Busca una opción por UUID.
	 *
	 * @param string $public_id UUID público.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $public_id ): ?array {
		global $wpdb;

		$table = Schema::pizza_options_table_name();
		// El identificador de tabla proviene del schema fijo.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s", $public_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? self::format( $row ) : null;
	}

	/**
	 * Lista opciones ordenadas por tipo y posición.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		$table = Schema::pizza_options_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY type ASC, display_order ASC, name ASC, id ASC", ARRAY_A );

		return array_map( array( self::class, 'format' ), $rows );
	}

	/**
	 * Proyecta una fila sin ID interno.
	 *
	 * @param array<string, mixed> $row Fila SQL.
	 * @return array<string, mixed>
	 */
	private static function format( array $row ): array {
		return array(
			'public_id'            => (string) $row['public_id'],
			'type'                 => (string) $row['type'],
			'name'                 => (string) $row['name'],
			'price_modifier_minor' => (int) $row['price_modifier_minor'],
			'available'            => 1 === (int) $row['available'],
			'display_order'        => (int) $row['display_order'],
			'revision'             => (int) $row['revision'],
		);
	}
}
