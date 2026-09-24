<?php
/**
 * Persistencia interna de entregas del proveedor manual.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Conserva el historial idempotente sin publicar su tabla a consumidores.
 *
 * @internal
 */
final class ManualSubmissionRepository {
	/**
	 * Devuelve el nombre de tabla para el sitio actual.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_payment_manual_submissions';
	}

	/**
	 * Busca una entrega por la identidad idempotente y bloquea su índice.
	 *
	 * @param int    $request_id      ID público de solicitud.
	 * @param string $idempotency_hash Hash canónico.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_idempotency( int $request_id, string $idempotency_hash ): ?array {
		global $wpdb;

		$table = self::table_name();
		$query = $wpdb->prepare(
			'SELECT * FROM %i WHERE request_id = %d AND idempotency_hash = %s FOR UPDATE',
			$table,
			$request_id,
			$idempotency_hash
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $query, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Busca una entrega pública perteneciente a una solicitud.
	 *
	 * @param int $request_id    ID público de solicitud.
	 * @param int $submission_id ID de entrega.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_id( int $request_id, int $submission_id ): ?array {
		global $wpdb;

		$table = self::table_name();
		$query = $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d AND request_id = %d',
			$table,
			$submission_id,
			$request_id
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $query, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Persiste una nueva entrega dentro de la transacción activa.
	 *
	 * @param array<string, mixed> $data Datos normalizados.
	 * @return int|false
	 */
	public static function insert( array $data ): int|false {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'request_id'       => $data['request_id'],
				'idempotency_hash' => $data['idempotency_hash'],
				'proof_reference'  => $data['proof_reference'],
				'request_revision' => $data['request_revision'],
				'created_at'       => $data['created_at'],
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);

		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Elimina el historial cuando WordPress elimina la solicitud.
	 *
	 * @param int $request_id ID público de solicitud.
	 * @return bool
	 */
	public static function delete_by_request_id( int $request_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			self::table_name(),
			array( 'request_id' => $request_id ),
			array( '%d' )
		);

		return false !== $deleted;
	}
}
