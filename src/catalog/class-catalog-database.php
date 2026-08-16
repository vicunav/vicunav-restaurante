<?php
/**
 * Primitivas transaccionales del catálogo.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Catalog;

use WP_Error;

/**
 * Centraliza transacciones y errores estables sin ocultar consultas de dominio.
 */
final class CatalogDatabase {
	/**
	 * Inicia una transacción InnoDB.
	 *
	 * @return bool
	 */
	public static function begin(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Confirma una transacción.
	 *
	 * @return bool
	 */
	public static function commit(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->query( 'COMMIT' );
	}

	/**
	 * Revierte una transacción.
	 *
	 * @return void
	 */
	public static function rollback(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Error de almacenamiento sin detalles privados.
	 *
	 * @return WP_Error
	 */
	public static function storage_error(): WP_Error {
		return new WP_Error(
			'vicu_restaurante_storage_error',
			__( 'No se pudo guardar el catálogo.', 'vicunav-restaurante' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Error de revisión obsoleta.
	 *
	 * @param int $current Revisión confirmada.
	 * @return WP_Error
	 */
	public static function stale_error( int $current ): WP_Error {
		return new WP_Error(
			'vicu_restaurante_stale_revision',
			__( 'El recurso cambió. Actualiza e intenta nuevamente.', 'vicunav-restaurante' ),
			array(
				'status'           => 409,
				'current_revision' => $current,
				'retryable'        => true,
			)
		);
	}

	/**
	 * Error indistinguible para un ID ausente.
	 *
	 * @return WP_Error
	 */
	public static function not_found(): WP_Error {
		return new WP_Error(
			'vicu_restaurante_not_found',
			__( 'No se encontró el recurso solicitado.', 'vicunav-restaurante' ),
			array( 'status' => 404 )
		);
	}
}
