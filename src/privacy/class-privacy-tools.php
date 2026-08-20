<?php
/**
 * Integración del dominio restaurante con las herramientas de privacidad.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Privacy;

use Vicu\Restaurante\Order\OrderStateMachine;
use Vicu\Restaurante\Reservation\ReservationStateMachine;
use Vicu\Restaurante\Schema;

/**
 * Exporta datos vinculados a un correo y anonimiza autoridades terminales.
 */
final class PrivacyTools {
	private const PAGE_SIZE = 50;

	/** Registra las superficies nativas de privacidad de WordPress. */
	public static function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}

	/**
	 * Añade el exportador del vertical.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exportadores registrados.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['vicunav-restaurante'] = array(
			'exporter_friendly_name' => __( 'Vicunav Restaurante', 'vicunav-restaurante' ),
			'callback'               => array( self::class, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Añade el borrador del vertical.
	 *
	 * @param array<string, array<string, mixed>> $erasers Borradores registrados.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['vicunav-restaurante'] = array(
			'eraser_friendly_name' => __( 'Vicunav Restaurante', 'vicunav-restaurante' ),
			'callback'             => array( self::class, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Exporta pedidos, reservas, carritos y pizzas guardadas sin secretos internos.
	 *
	 * @param string $email_address Correo verificado por WordPress.
	 * @param int    $page          Página solicitada.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export_personal_data( string $email_address, int $page = 1 ): array {
		$email = sanitize_email( $email_address );

		if ( '' === $email ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$page    = max( 1, $page );
		$user_id = self::user_id_for_email( $email );
		$offset  = ( $page - 1 ) * self::PAGE_SIZE;
		$rows    = array_merge(
			self::orders_for_export( $email, $user_id ),
			self::reservations_for_export( $email, $user_id ),
			self::saved_pizzas_for_export( $user_id ),
			self::carts_for_export( $user_id )
		);
		$slice   = array_slice( $rows, $offset, self::PAGE_SIZE );

		return array(
			'data' => array_values( $slice ),
			'done' => count( $slice ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Elimina datos efímeros y anonimiza registros terminales conservados.
	 *
	 * Los pedidos y reservas activos se retienen para no romper su prestación. WordPress
	 * mostrará esos motivos al administrador que procesa la solicitud.
	 *
	 * @param string $email_address Correo verificado por WordPress.
	 * @param int    $page          Página solicitada; el proceso se completa en una pasada.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		unset( $page );
		$email = sanitize_email( $email_address );

		if ( '' === $email ) {
			return self::erase_result( false, false, array() );
		}

		$user_id            = self::user_id_for_email( $email );
		$removed            = false;
		$retained           = false;
		$messages           = array();
		$order_result       = self::erase_orders( $email, $user_id );
		$reservation_result = self::erase_reservations( $email, $user_id );

		$removed  = $order_result['removed'] || $reservation_result['removed'];
		$retained = $order_result['retained'] || $reservation_result['retained'];
		$messages = array_merge( $order_result['messages'], $reservation_result['messages'] );

		if ( 0 < $user_id ) {
			$removed = self::delete_saved_pizzas( $user_id ) || $removed;
			$removed = self::delete_user_carts( $user_id ) || $removed;
		}

		return self::erase_result( $removed, $retained, $messages );
	}

	/**
	 * Resuelve la cuenta local asociada sin crear identidad nueva.
	 *
	 * @param string $email Correo normalizado.
	 */
	private static function user_id_for_email( string $email ): int {
		$user = get_user_by( 'email', $email );

		return false === $user ? 0 : (int) $user->ID;
	}

	/**
	 * Busca pedidos vinculados al correo o a su cuenta.
	 *
	 * @param string $email   Correo normalizado.
	 * @param int    $user_id Cuenta local, si existe.
	 * @return array<int, array<string, mixed>>
	 */
	private static function orders_for_export( string $email, int $user_id ): array {
		global $wpdb;

		$table = Schema::orders_table_name();
		$where = self::identity_where( 'customer_email', $email, $user_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla y columnas pertenecen al schema interno; los valores llegan preparados.
		$rows = $wpdb->get_results( "SELECT public_id, order_number, status, fulfillment, customer_name, customer_email, customer_phone, delivery_address, delivery_instructions, customer_note, currency, subtotal_minor, discount_total, tax_total, tip_total, delivery_total, total_minor, created_at FROM {$table} WHERE {$where['sql']} ORDER BY id ASC", ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				return self::export_item(
					'vicunav-restaurante-orders',
					__( 'Pedidos del restaurante', 'vicunav-restaurante' ),
					'order-' . $row['public_id'],
					array(
						__( 'Número', 'vicunav-restaurante' )                => $row['order_number'],
						__( 'Estado', 'vicunav-restaurante' )                => $row['status'],
						__( 'Entrega', 'vicunav-restaurante' )               => $row['fulfillment'],
						__( 'Nombre', 'vicunav-restaurante' )                => $row['customer_name'],
						__( 'Correo', 'vicunav-restaurante' )                => $row['customer_email'],
						__( 'Teléfono', 'vicunav-restaurante' )              => $row['customer_phone'],
						__( 'Dirección', 'vicunav-restaurante' )             => $row['delivery_address'],
						__( 'Instrucciones', 'vicunav-restaurante' )         => $row['delivery_instructions'],
						__( 'Nota', 'vicunav-restaurante' )                  => $row['customer_note'],
						__( 'Moneda', 'vicunav-restaurante' )                => $row['currency'],
						__( 'Subtotal en unidad menor', 'vicunav-restaurante' ) => $row['subtotal_minor'],
						__( 'Descuento en unidad menor', 'vicunav-restaurante' ) => $row['discount_total'],
						__( 'Impuesto en unidad menor', 'vicunav-restaurante' ) => $row['tax_total'],
						__( 'Propina en unidad menor', 'vicunav-restaurante' ) => $row['tip_total'],
						__( 'Delivery en unidad menor', 'vicunav-restaurante' ) => $row['delivery_total'],
						__( 'Total en unidad menor', 'vicunav-restaurante' ) => $row['total_minor'],
						__( 'Creado', 'vicunav-restaurante' )                => $row['created_at'],
					)
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Busca reservas vinculadas al correo o a su cuenta.
	 *
	 * @param string $email   Correo normalizado.
	 * @param int    $user_id Cuenta local, si existe.
	 * @return array<int, array<string, mixed>>
	 */
	private static function reservations_for_export( string $email, int $user_id ): array {
		global $wpdb;

		$table = Schema::reservations_table_name();
		$where = self::identity_where( 'guest_email', $email, $user_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla y columnas pertenecen al schema interno; los valores llegan preparados.
		$rows = $wpdb->get_results( "SELECT public_id, confirmation_code, status, guest_name, guest_phone, guest_email, notes, zone_preference, party_size, local_date, local_time, timezone, created_at FROM {$table} WHERE {$where['sql']} ORDER BY id ASC", ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				return self::export_item(
					'vicunav-restaurante-reservations',
					__( 'Reservas del restaurante', 'vicunav-restaurante' ),
					'reservation-' . $row['public_id'],
					array(
						__( 'Confirmación', 'vicunav-restaurante' ) => $row['confirmation_code'],
						__( 'Estado', 'vicunav-restaurante' )       => $row['status'],
						__( 'Nombre', 'vicunav-restaurante' )       => $row['guest_name'],
						__( 'Teléfono', 'vicunav-restaurante' )     => $row['guest_phone'],
						__( 'Correo', 'vicunav-restaurante' )       => $row['guest_email'],
						__( 'Notas', 'vicunav-restaurante' )        => $row['notes'],
						__( 'Zona preferida', 'vicunav-restaurante' ) => $row['zone_preference'],
						__( 'Personas', 'vicunav-restaurante' )     => $row['party_size'],
						__( 'Fecha local', 'vicunav-restaurante' )  => $row['local_date'],
						__( 'Hora local', 'vicunav-restaurante' )   => $row['local_time'],
						__( 'Zona horaria', 'vicunav-restaurante' ) => $row['timezone'],
						__( 'Creada', 'vicunav-restaurante' )       => $row['created_at'],
					)
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Exporta pizzas guardadas de una cuenta.
	 *
	 * @param int $user_id Cuenta local.
	 * @return array<int, array<string, mixed>>
	 */
	private static function saved_pizzas_for_export( int $user_id ): array {
		global $wpdb;

		if ( 1 > $user_id ) {
			return array();
		}

		$table = Schema::saved_pizzas_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla interna fija y valor preparado.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT public_id, name, configuration_json, created_at, updated_at FROM {$table} WHERE user_id = %d ORDER BY id ASC", $user_id ), ARRAY_A );

		return array_map(
			static fn( array $row ): array => self::export_item(
				'vicunav-restaurante-saved-pizzas',
				__( 'Pizzas guardadas', 'vicunav-restaurante' ),
				'saved-pizza-' . $row['public_id'],
				array(
					__( 'Nombre', 'vicunav-restaurante' ) => $row['name'],
					__( 'Configuración', 'vicunav-restaurante' ) => $row['configuration_json'],
					__( 'Creada', 'vicunav-restaurante' ) => $row['created_at'],
					__( 'Actualizada', 'vicunav-restaurante' ) => $row['updated_at'],
				)
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Exporta carritos de una cuenta sin owner keys ni secretos de sesión.
	 *
	 * @param int $user_id Cuenta local.
	 * @return array<int, array<string, mixed>>
	 */
	private static function carts_for_export( int $user_id ): array {
		global $wpdb;

		if ( 1 > $user_id ) {
			return array();
		}

		$table = Schema::carts_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla interna fija y valor preparado.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT public_id, status, fulfillment, discount_code, delivery_zone_public_id, tip_rate_bps, subtotal_minor, totals_json, created_at, updated_at FROM {$table} WHERE user_id = %d ORDER BY id ASC", $user_id ), ARRAY_A );

		return array_map(
			static fn( array $row ): array => self::export_item(
				'vicunav-restaurante-carts',
				__( 'Carritos del restaurante', 'vicunav-restaurante' ),
				'cart-' . $row['public_id'],
				array(
					__( 'Estado', 'vicunav-restaurante' )  => $row['status'],
					__( 'Entrega', 'vicunav-restaurante' ) => $row['fulfillment'],
					__( 'Descuento', 'vicunav-restaurante' ) => $row['discount_code'],
					__( 'Zona de delivery', 'vicunav-restaurante' ) => $row['delivery_zone_public_id'],
					__( 'Propina en puntos base', 'vicunav-restaurante' ) => $row['tip_rate_bps'],
					__( 'Subtotal en unidad menor', 'vicunav-restaurante' ) => $row['subtotal_minor'],
					__( 'Totales', 'vicunav-restaurante' ) => $row['totals_json'],
					__( 'Creado', 'vicunav-restaurante' )  => $row['created_at'],
					__( 'Actualizado', 'vicunav-restaurante' ) => $row['updated_at'],
				)
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Convierte un mapa a la forma exigida por WordPress.
	 *
	 * @param string               $group_id    Identificador estable del grupo.
	 * @param string               $group_label Etiqueta traducible del grupo.
	 * @param string               $item_id     Identificador estable del elemento.
	 * @param array<string, mixed> $values Datos visibles.
	 * @return array<string, mixed>
	 */
	private static function export_item( string $group_id, string $group_label, string $item_id, array $values ): array {
		$data = array();

		foreach ( $values as $name => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$data[] = array(
				'name'  => $name,
				'value' => (string) $value,
			);
		}

		return compact( 'group_id', 'group_label', 'item_id', 'data' );
	}

	/**
	 * Construye una cláusula preparada para correo y, si existe, cuenta.
	 *
	 * @param string $email_column Columna fija del schema que almacena el correo.
	 * @param string $email        Correo normalizado.
	 * @param int    $user_id      Cuenta local, si existe.
	 * @return array{sql: string}
	 */
	private static function identity_where( string $email_column, string $email, int $user_id ): array {
		global $wpdb;

		if ( 0 < $user_id ) {
			return array(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- La columna llega exclusivamente desde las llamadas internas fijas.
				'sql' => $wpdb->prepare( "({$email_column} = %s OR user_id = %d)", $email, $user_id ),
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- La columna llega exclusivamente desde las llamadas internas fijas.
		return array( 'sql' => $wpdb->prepare( "{$email_column} = %s", $email ) );
	}

	/**
	 * Anonimiza pedidos terminales y su evidencia; informa pedidos activos retenidos.
	 *
	 * @param string $email   Correo normalizado.
	 * @param int    $user_id Cuenta local, si existe.
	 * @return array{removed: bool, retained: bool, messages: string[]}
	 */
	private static function erase_orders( string $email, int $user_id ): array {
		global $wpdb;

		$table = Schema::orders_table_name();
		$where = self::identity_where( 'customer_email', $email, $user_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla y columnas pertenecen al schema interno; los valores llegan preparados.
		$rows     = $wpdb->get_results( "SELECT id, status FROM {$table} WHERE {$where['sql']}", ARRAY_A );
		$removed  = false;
		$retained = false;

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! OrderStateMachine::is_terminal( (string) $row['status'] ) ) {
				$retained = true;
				continue;
			}

			self::anonymize_order( (int) $row['id'] );
			$removed = true;
		}

		$messages = $retained
			? array( __( 'Se retuvieron pedidos activos porque todavía deben completar su ciclo operativo o de pago.', 'vicunav-restaurante' ) )
			: array();

		return compact( 'removed', 'retained', 'messages' );
	}

	/**
	 * Anonimiza PII de un pedido terminal y notas libres de sus snapshots.
	 *
	 * @param int $order_id Identificador interno del pedido.
	 */
	private static function anonymize_order( int $order_id ): void {
		global $wpdb;

		$orders         = Schema::orders_table_name();
		$evidence       = Schema::payment_evidence_table_name();
		$items          = Schema::order_items_table_name();
		$anonymous_hash = hash( 'sha256', wp_generate_uuid4() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$orders,
			array(
				'user_id'               => null,
				'access_token_hash'     => $anonymous_hash,
				'customer_name'         => __( 'Datos eliminados', 'vicunav-restaurante' ),
				'customer_email'        => null,
				'customer_phone'        => '',
				'delivery_address'      => null,
				'delivery_instructions' => null,
				'customer_note'         => null,
			),
			array( 'id' => $order_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$evidence,
			array(
				'request_hash'   => hash( 'sha256', wp_generate_uuid4() ),
				'reference_text' => __( 'Datos eliminados', 'vicunav-restaurante' ),
			),
			array( 'order_id' => $order_id )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla interna fija y valor preparado.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, selection_json, snapshot_json FROM {$items} WHERE order_id = %d", $order_id ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$items,
				array(
					'selection_json' => self::remove_free_text_note( (string) $row['selection_json'] ),
					'snapshot_json'  => self::remove_free_text_note( (string) $row['snapshot_json'] ),
				),
				array( 'id' => (int) $row['id'] )
			);
		}
	}

	/**
	 * Anonimiza reservas terminales e informa reservas activas retenidas.
	 *
	 * @param string $email   Correo normalizado.
	 * @param int    $user_id Cuenta local, si existe.
	 * @return array{removed: bool, retained: bool, messages: string[]}
	 */
	private static function erase_reservations( string $email, int $user_id ): array {
		global $wpdb;

		$table = Schema::reservations_table_name();
		$where = self::identity_where( 'guest_email', $email, $user_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla y columnas pertenecen al schema interno; los valores llegan preparados.
		$rows     = $wpdb->get_results( "SELECT id, status FROM {$table} WHERE {$where['sql']}", ARRAY_A );
		$removed  = false;
		$retained = false;

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! ReservationStateMachine::is_terminal( (string) $row['status'] ) ) {
				$retained = true;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'access_token_hash' => hash( 'sha256', wp_generate_uuid4() ),
					'user_id'           => null,
					'guest_name'        => __( 'Datos eliminados', 'vicunav-restaurante' ),
					'guest_phone'       => '',
					'guest_email'       => null,
					'notes'             => null,
					'zone_preference'   => null,
				),
				array( 'id' => (int) $row['id'] )
			);
			$removed = true;
		}

		$messages = $retained
			? array( __( 'Se retuvieron reservas activas porque todavía deben completar su ciclo operativo.', 'vicunav-restaurante' ) )
			: array();

		return compact( 'removed', 'retained', 'messages' );
	}

	/**
	 * Elimina pizzas guardadas y revoca sus enlaces compartidos.
	 *
	 * @param int $user_id Cuenta local.
	 */
	private static function delete_saved_pizzas( int $user_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return 0 < (int) $wpdb->delete( Schema::saved_pizzas_table_name(), array( 'user_id' => $user_id ) );
	}

	/**
	 * Elimina carritos y sesiones efímeras de una cuenta con sus líneas.
	 *
	 * @param int $user_id Cuenta local.
	 */
	private static function delete_user_carts( int $user_id ): bool {
		global $wpdb;

		$carts    = Schema::carts_table_name();
		$items    = Schema::cart_items_table_name();
		$sessions = Schema::cart_sessions_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla interna fija y valor preparado.
		$cart_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$carts} WHERE user_id = %d", $user_id ) );

		foreach ( is_array( $cart_ids ) ? $cart_ids : array() as $cart_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $items, array( 'cart_id' => (int) $cart_id ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$carts_deleted = (int) $wpdb->delete( $carts, array( 'user_id' => $user_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$sessions_deleted = (int) $wpdb->delete( $sessions, array( 'user_id' => $user_id ) );

		return 0 < $carts_deleted + $sessions_deleted;
	}

	/**
	 * Elimina exclusivamente la nota libre de un snapshot JSON válido.
	 *
	 * @param string $json Snapshot codificado.
	 */
	private static function remove_free_text_note( string $json ): string {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return $json;
		}

		unset( $decoded['note'] );

		return (string) wp_json_encode( $decoded );
	}

	/**
	 * Normaliza la respuesta contractual del borrador.
	 *
	 * @param bool     $removed  Se eliminó o anonimizó al menos un elemento.
	 * @param bool     $retained Se retuvo al menos un elemento activo.
	 * @param string[] $messages Motivos de retención.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	private static function erase_result( bool $removed, bool $retained, array $messages ): array {
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
