<?php
/**
 * Servicio autoritativo de zonas de entrega.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Commerce;

use Vicu\Restaurante\Catalog\CatalogDatabase;
use Vicu\Restaurante\Schema;
use WP_Error;

/**
 * Gestiona tarifas explícitas con revisión compare-and-swap.
 */
final class DeliveryZoneService {
	/**
	 * Crea una zona.
	 *
	 * @param array<string, mixed> $input Datos completos.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( array $input ): array|WP_Error {
		global $wpdb;

		$data = self::validate( $input );

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
			Schema::delivery_zones_table_name(),
			array_merge(
				$data,
				array(
					'public_id'  => $public_id,
					'revision'   => 1,
					'created_at' => $now,
					'updated_at' => $now,
				)
			),
			array( '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted || ! PricingRevision::bump_in_transaction() || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::storage_error();
		}

		PricingRevision::clear_cache();

		return self::find( $public_id ) ?? CatalogDatabase::storage_error();
	}

	/**
	 * Sustituye una zona con revisión esperada.
	 *
	 * @param string               $public_id         UUID.
	 * @param int                  $expected_revision Revisión esperada.
	 * @param array<string, mixed> $input             Datos completos.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( string $public_id, int $expected_revision, array $input ): array|WP_Error {
		global $wpdb;

		$data    = self::validate( $input );
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

		$table = Schema::delivery_zones_table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET name = %s, active = %d, fee_minor = %d,
					eta_min_minutes = %d, eta_max_minutes = %d, display_order = %d,
					revision = revision + 1, updated_at = %s
				WHERE public_id = %s AND revision = %d",
				$data['name'],
				$data['active'],
				$data['fee_minor'],
				$data['eta_min_minutes'],
				$data['eta_max_minutes'],
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

		if ( ! PricingRevision::bump_in_transaction() || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::storage_error();
		}

		PricingRevision::clear_cache();

		return self::find( $public_id ) ?? CatalogDatabase::storage_error();
	}

	/**
	 * Busca una zona por UUID.
	 *
	 * @param string $public_id UUID.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $public_id ): ?array {
		global $wpdb;

		$table = Schema::delivery_zones_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s", $public_id ), ARRAY_A );

		return is_array( $row ) ? self::format( $row ) : null;
	}

	/**
	 * Lista zonas en orden estable.
	 *
	 * @param bool $active_only Si excluye inactivas.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( bool $active_only = false ): array {
		global $wpdb;

		$table = Schema::delivery_zones_table_name();
		$where = $active_only ? ' WHERE active = 1' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY display_order ASC, name ASC, id ASC", ARRAY_A );

		return array_map( array( self::class, 'format' ), $rows );
	}

	/**
	 * Valida reglas estructurales de la zona.
	 *
	 * @param array<string, mixed> $input Datos candidatos.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function validate( array $input ): array|WP_Error {
		$name    = sanitize_text_field( wp_unslash( is_scalar( $input['name'] ?? null ) ? (string) $input['name'] : '' ) );
		$fee     = self::non_negative_integer( $input['fee_minor'] ?? null, 100000000 );
		$eta_min = self::non_negative_integer( $input['eta_min_minutes'] ?? null, 10080 );
		$eta_max = self::non_negative_integer( $input['eta_max_minutes'] ?? null, 10080 );
		$order   = self::non_negative_integer( $input['display_order'] ?? 0, 9999 );

		if ( '' === $name || 191 < mb_strlen( $name ) || null === $fee || null === $eta_min || null === $eta_max || $eta_max < $eta_min || null === $order ) {
			return self::invalid();
		}

		return array(
			'name'            => $name,
			'active'          => rest_sanitize_boolean( $input['active'] ?? false ) ? 1 : 0,
			'fee_minor'       => $fee,
			'eta_min_minutes' => $eta_min,
			'eta_max_minutes' => $eta_max,
			'display_order'   => $order,
		);
	}

	/**
	 * Valida un entero acotado.
	 *
	 * @param mixed $value Valor.
	 * @param int   $max   Máximo.
	 * @return int|null
	 */
	private static function non_negative_integer( mixed $value, int $max ): ?int {
		if ( ! is_scalar( $value ) || is_float( $value ) || ! is_numeric( $value ) || trim( (string) $value ) !== (string) (int) $value ) {
			return null;
		}

		$number = (int) $value;

		return 0 <= $number && $max >= $number ? $number : null;
	}

	/**
	 * Proyecta una fila sin ID interno.
	 *
	 * @param array<string, mixed> $row Fila SQL.
	 * @return array<string, mixed>
	 */
	private static function format( array $row ): array {
		return array(
			'public_id'       => (string) $row['public_id'],
			'name'            => (string) $row['name'],
			'active'          => 1 === (int) $row['active'],
			'fee_minor'       => (int) $row['fee_minor'],
			'eta_min_minutes' => (int) $row['eta_min_minutes'],
			'eta_max_minutes' => (int) $row['eta_max_minutes'],
			'display_order'   => (int) $row['display_order'],
			'revision'        => (int) $row['revision'],
		);
	}

	/**
	 * Error común de validación.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error(
			'vicu_restaurante_invalid_request',
			__( 'Los datos de la zona no son válidos.', 'vicunav-restaurante' ),
			array( 'status' => 400 )
		);
	}
}
