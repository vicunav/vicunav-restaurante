<?php
/**
 * Persistencia interna del ciclo de vida de pagos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Encapsula queries transaccionales sin publicar la tabla a consumidores.
 *
 * @internal
 */
final class PaymentRequestRepository {
	/**
	 * Devuelve el nombre de tabla para el sitio actual.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'vicu_payment_requests';
	}

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
	 * Confirma la transacción actual.
	 *
	 * @return bool
	 */
	public static function commit(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->query( 'COMMIT' );
	}

	/**
	 * Revierte la transacción actual.
	 *
	 * @return void
	 */
	public static function rollback(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Reserva una referencia externa mediante su índice único.
	 *
	 * @param array<string, mixed> $data Datos normalizados.
	 * @return int|false
	 */
	public static function reserve( array $data ): int|false {
		global $wpdb;

		$table           = self::table_name();
		$previous_errors = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'reference_hash' => $data['reference_hash'],
				'external_type'  => $data['external_type'],
				'external_id'    => $data['external_id'],
				'amount_minor'   => $data['amount_minor'],
				'currency'       => $data['currency'],
				'state'          => PaymentRequestState::PENDING,
				'revision'       => 1,
				'expires_at'     => $data['expires_at'],
				'created_at'     => $data['now'],
				'updated_at'     => $data['now'],
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		$wpdb->suppress_errors( $previous_errors );

		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Enlaza la reserva con su post administrativo.
	 *
	 * @param int $row_id  ID interno de fila.
	 * @param int $post_id ID del post.
	 * @return bool
	 */
	public static function attach_post( int $row_id, int $post_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table_name(),
			array( 'post_id' => $post_id ),
			array( 'id' => $row_id ),
			array( '%d' ),
			array( '%d' )
		);

		return 1 === $updated;
	}

	/**
	 * Busca por hash de referencia.
	 *
	 * @param string $reference_hash Hash canónico.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_reference_hash( string $reference_hash ): ?array {
		global $wpdb;

		$table = self::table_name();
		$query = $wpdb->prepare( 'SELECT * FROM %i WHERE reference_hash = %s', $table, $reference_hash );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $query, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Busca una solicitud por su ID público.
	 *
	 * @param int  $post_id    ID del post.
	 * @param bool $for_update Bloquea la fila durante la transacción.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_post_id( int $post_id, bool $for_update = false ): ?array {
		global $wpdb;

		$table  = self::table_name();
		$suffix = $for_update ? ' FOR UPDATE' : '';
		// El sufijo es una constante interna y no contiene entrada externa.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare( "SELECT * FROM %i WHERE post_id = %d{$suffix}", $table, $post_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $query, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Aplica compare-and-swap sobre estado y revisión.
	 *
	 * @param int         $row_id       ID interno de fila.
	 * @param string      $from          Estado esperado.
	 * @param string      $to            Estado nuevo.
	 * @param int         $revision      Revisión esperada.
	 * @param string      $updated_at    Fecha UTC de actualización.
	 * @param string|null $provider Código de proveedor que se asignará, si corresponde.
	 * @return bool
	 */
	public static function transition(
		int $row_id,
		string $from,
		string $to,
		int $revision,
		string $updated_at,
		?string $provider = null
	): bool {
		global $wpdb;

		$table = self::table_name();
		if ( null === $provider ) {
			$query = $wpdb->prepare(
				'UPDATE %i SET state = %s, revision = revision + 1, updated_at = %s WHERE id = %d AND state = %s AND revision = %d',
				$table,
				$to,
				$updated_at,
				$row_id,
				$from,
				$revision
			);
		} else {
			$query = $wpdb->prepare(
				'UPDATE %i SET provider = %s, state = %s, revision = revision + 1, updated_at = %s WHERE id = %d AND state = %s AND revision = %d',
				$table,
				$provider,
				$to,
				$updated_at,
				$row_id,
				$from,
				$revision
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return 1 === $wpdb->query( $query );
	}

	/**
	 * Devuelve IDs de solicitudes vencidas que aún pueden expirar.
	 *
	 * @param string $now   Fecha UTC límite.
	 * @param int    $limit Tamaño máximo del lote.
	 * @return int[]
	 */
	public static function find_due_ids( string $now, int $limit ): array {
		global $wpdb;

		$table = self::table_name();
		$query = $wpdb->prepare(
			'SELECT post_id FROM %i WHERE expires_at IS NOT NULL AND expires_at <= %s AND state IN (%s, %s) ORDER BY expires_at ASC, id ASC LIMIT %d',
			$table,
			$now,
			PaymentRequestState::PENDING,
			PaymentRequestState::REJECTED,
			$limit
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $query );

		return array_map( 'intval', $ids );
	}

	/**
	 * Elimina la fila interna cuando WordPress elimina su post administrativo.
	 *
	 * @param int $post_id ID del post.
	 * @return bool
	 */
	public static function delete_by_post_id( int $post_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			self::table_name(),
			array( 'post_id' => $post_id ),
			array( '%d' )
		);

		return false !== $deleted;
	}
}
