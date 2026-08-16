<?php
/**
 * Servicio autoritativo de ingredientes.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Catalog;

use Vicu\Restaurante\Schema;
use WP_Error;

/**
 * Crea y actualiza ingredientes con revisión compare-and-swap.
 */
final class IngredientService {
	/**
	 * Crea un ingrediente y una única revisión global.
	 *
	 * @param array<string, mixed> $input Datos completos.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( array $input ): array|WP_Error {
		global $wpdb;

		$data = CatalogValidator::ingredient( $input );

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
			Schema::ingredients_table_name(),
			array(
				'public_id'            => $public_id,
				'name'                 => $data['name'],
				'category'             => $data['category'],
				'price_modifier_minor' => $data['price_modifier_minor'],
				'available'            => $data['available'] ? 1 : 0,
				'allergens'            => wp_json_encode( $data['allergens'], JSON_UNESCAPED_UNICODE ),
				'dietary_tags'         => wp_json_encode( $data['dietary_tags'], JSON_UNESCAPED_UNICODE ),
				'revision'             => 1,
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted || ! AvailabilityRevision::bump_in_transaction() || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::storage_error();
		}

		AvailabilityRevision::clear_cache();

		return self::find( $public_id ) ?? CatalogDatabase::storage_error();
	}

	/**
	 * Sustituye campos con compare-and-swap.
	 *
	 * @param string               $public_id        UUID público.
	 * @param int                  $expected_revision Revisión esperada.
	 * @param array<string, mixed> $input             Datos completos.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( string $public_id, int $expected_revision, array $input ): array|WP_Error {
		global $wpdb;

		$data    = CatalogValidator::ingredient( $input );
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

		$table = Schema::ingredients_table_name();
		// El identificador de tabla es fijo y todos los valores usan placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET name = %s, category = %s, price_modifier_minor = %d, available = %d,
					allergens = %s, dietary_tags = %s, revision = revision + 1, updated_at = %s
				WHERE public_id = %s AND revision = %d",
				$data['name'],
				$data['category'],
				$data['price_modifier_minor'],
				$data['available'] ? 1 : 0,
				wp_json_encode( $data['allergens'], JSON_UNESCAPED_UNICODE ),
				wp_json_encode( $data['dietary_tags'], JSON_UNESCAPED_UNICODE ),
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
	 * Busca un ingrediente por UUID.
	 *
	 * @param string $public_id UUID público.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $public_id ): ?array {
		global $wpdb;

		$table = Schema::ingredients_table_name();
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
	 * Lista el catálogo en orden estable.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		$table = Schema::ingredients_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY category ASC, name ASC, id ASC", ARRAY_A );

		return array_map( array( self::class, 'format' ), $rows );
	}

	/**
	 * Proyecta una fila sin exponer su ID interno.
	 *
	 * @param array<string, mixed> $row Fila SQL.
	 * @return array<string, mixed>
	 */
	private static function format( array $row ): array {
		$allergens = json_decode( (string) $row['allergens'], true );
		$dietary   = json_decode( (string) $row['dietary_tags'], true );

		return array(
			'public_id'            => (string) $row['public_id'],
			'name'                 => (string) $row['name'],
			'category'             => (string) $row['category'],
			'price_modifier_minor' => (int) $row['price_modifier_minor'],
			'available'            => 1 === (int) $row['available'],
			'allergens'            => is_array( $allergens ) ? $allergens : array(),
			'dietary_tags'         => is_array( $dietary ) ? $dietary : array(),
			'revision'             => (int) $row['revision'],
		);
	}
}
