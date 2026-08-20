<?php
/**
 * Relaciones estructuradas entre menú e ingredientes.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Catalog;

use Vicu\Restaurante\Menu\CatalogRevision;
use Vicu\Restaurante\Menu\MenuItemPostType;
use Vicu\Restaurante\Schema;
use WP_Error;

/**
 * Reemplaza relaciones de un item dentro de una transacción.
 */
final class MenuIngredientService {
	/**
	 * Sustituye el conjunto completo de relaciones de un item.
	 *
	 * @param int                              $menu_item_id ID interno del CPT propietario.
	 * @param array<int, array<string, mixed>> $relations Relaciones candidatas.
	 * @return bool|WP_Error
	 */
	public static function replace( int $menu_item_id, array $relations ): bool|WP_Error {
		global $wpdb;

		if ( MenuItemPostType::POST_TYPE !== get_post_type( $menu_item_id ) ) {
			return CatalogDatabase::not_found();
		}

		$normalized = self::normalize( $relations );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$ids = self::resolve_internal_ids( $normalized );

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		if ( ! CatalogDatabase::begin() ) {
			return CatalogDatabase::storage_error();
		}

		$table = Schema::menu_ingredients_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $table, array( 'menu_item_id' => $menu_item_id ), array( '%d' ) );

		if ( false === $deleted ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::storage_error();
		}

		foreach ( $normalized as $relation ) {
			$substitution_id = '' === $relation['substitution_public_id']
				? null
				: $ids[ $relation['substitution_public_id'] ];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert(
				$table,
				array(
					'menu_item_id'               => $menu_item_id,
					'ingredient_id'              => $ids[ $relation['ingredient_public_id'] ],
					'role'                       => $relation['role'],
					'display_order'              => $relation['display_order'],
					'substitution_ingredient_id' => $substitution_id,
				),
				array( '%d', '%d', '%s', '%d', '%d' )
			);

			if ( false === $inserted ) {
				CatalogDatabase::rollback();
				return CatalogDatabase::storage_error();
			}
		}

		if ( ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::storage_error();
		}

		CatalogRevision::bump();

		return true;
	}

	/**
	 * Lista relaciones con IDs públicos, sin filtrar no disponibles.
	 *
	 * @param int $menu_item_id ID interno del CPT.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_menu_item( int $menu_item_id ): array {
		global $wpdb;

		$relations   = Schema::menu_ingredients_table_name();
		$ingredients = Schema::ingredients_table_name();

		// Los identificadores de tabla pertenecen al schema fijo; el ID usa placeholder.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ingredient.public_id AS ingredient_public_id,
					relation.role, relation.display_order,
					substitution.public_id AS substitution_public_id
				FROM {$relations} relation
				INNER JOIN {$ingredients} ingredient ON ingredient.id = relation.ingredient_id
				LEFT JOIN {$ingredients} substitution ON substitution.id = relation.substitution_ingredient_id
				WHERE relation.menu_item_id = %d
				ORDER BY relation.display_order ASC, ingredient.name ASC",
				$menu_item_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static function ( array $row ): array {
				return array(
					'ingredient_public_id'   => (string) $row['ingredient_public_id'],
					'role'                   => (string) $row['role'],
					'display_order'          => (int) $row['display_order'],
					'substitution_public_id' => null === $row['substitution_public_id'] ? '' : (string) $row['substitution_public_id'],
				);
			},
			$rows
		);
	}

	/**
	 * Normaliza relaciones y rechaza duplicados o referencias propias.
	 *
	 * @param array<int, array<string, mixed>> $relations Relaciones candidatas.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function normalize( array $relations ): array|WP_Error {
		$normalized = array();
		$seen       = array();

		foreach ( $relations as $relation ) {
			if ( ! is_array( $relation ) ) {
				return self::invalid();
			}

			$ingredient   = sanitize_text_field( (string) ( $relation['ingredient_public_id'] ?? '' ) );
			$substitution = sanitize_text_field( (string) ( $relation['substitution_public_id'] ?? '' ) );
			$role         = sanitize_key( (string) ( $relation['role'] ?? '' ) );
			$order        = $relation['display_order'] ?? 0;

			if (
				! wp_is_uuid( $ingredient, 4 ) ||
				( '' !== $substitution && ! wp_is_uuid( $substitution, 4 ) ) ||
				$ingredient === $substitution ||
				! in_array( $role, CatalogValidator::RELATION_ROLES, true ) ||
				! is_scalar( $order ) ||
				! is_numeric( $order ) ||
				0 > (int) $order ||
				isset( $seen[ $ingredient ] )
			) {
				return self::invalid();
			}

			$seen[ $ingredient ] = true;
			$normalized[]        = array(
				'ingredient_public_id'   => $ingredient,
				'role'                   => $role,
				'display_order'          => min( 9999, (int) $order ),
				'substitution_public_id' => $substitution,
			);
		}

		return $normalized;
	}

	/**
	 * Resuelve todas las referencias en una sola consulta.
	 *
	 * @param array<int, array<string, mixed>> $relations Relaciones normalizadas.
	 * @return array<string, int>|WP_Error
	 */
	private static function resolve_internal_ids( array $relations ): array|WP_Error {
		global $wpdb;

		$public_ids = array();

		foreach ( $relations as $relation ) {
			$public_ids[] = $relation['ingredient_public_id'];

			if ( '' !== $relation['substitution_public_id'] ) {
				$public_ids[] = $relation['substitution_public_id'];
			}
		}

		$public_ids = array_values( array_unique( $public_ids ) );

		if ( array() === $public_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $public_ids ), '%s' ) );
		$table        = Schema::ingredients_table_name();
		// Los placeholders se generan por cantidad y los valores se entregan por separado.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, public_id FROM {$table} WHERE public_id IN ({$placeholders})", $public_ids ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$resolved = array();

		foreach ( $rows as $row ) {
			$resolved[ $row['public_id'] ] = (int) $row['id'];
		}

		return count( $resolved ) === count( $public_ids ) ? $resolved : self::invalid();
	}

	/**
	 * Error de relación inválida.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error(
			'vicu_restaurante_invalid_request',
			__( 'Las relaciones de ingredientes no son válidas.', 'vicunav-restaurante' ),
			array( 'status' => 400 )
		);
	}
}
