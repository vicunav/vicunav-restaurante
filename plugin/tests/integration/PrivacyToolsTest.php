<?php
/**
 * Pruebas de integración con las herramientas de privacidad de WordPress.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Installer;
use Vicu\Restaurante\Privacy\PrivacyTools;
use Vicu\Restaurante\Schema;

/** Verifica exportación segura, anonimización terminal y retención operativa. */
final class PrivacyToolsTest extends WP_UnitTestCase {
	private const EMAIL = 'privacidad@example.test';

	/** Instala el schema y aísla los datos del caso. */
	public function setUp(): void {
		parent::setUp();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( Installer::install() );
		$this->truncate_tables();
	}

	/** WordPress descubre las dos callbacks públicas del vertical. */
	public function test_registers_native_privacy_tools(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertSame( array( PrivacyTools::class, 'export_personal_data' ), $exporters['vicunav-restaurante']['callback'] );
		$this->assertSame( array( PrivacyTools::class, 'erase_personal_data' ), $erasers['vicunav-restaurante']['callback'] );
	}

	/** Exporta datos útiles sin hashes, tokens, claves de ownership ni evidencia privada. */
	public function test_exports_all_account_surfaces_without_internal_secrets(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => self::EMAIL ) );
		$this->seed_domain( $user_id );

		$result = PrivacyTools::export_personal_data( self::EMAIL );
		$json   = (string) wp_json_encode( $result['data'] );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 6, $result['data'] );
		$this->assertStringContainsString( 'ORDER-PRIVACY-1', $json );
		$this->assertStringContainsString( 'RES-PRIVACY-1', $json );
		$this->assertStringContainsString( 'Favorita privada', $json );
		$this->assertStringContainsString( self::EMAIL, $json );
		$this->assertStringNotContainsString( 'reference_text', $json );
		$this->assertStringNotContainsString( str_repeat( 'a', 64 ), $json );
		$this->assertStringNotContainsString( 'owner-private', $json );
	}

	/** El borrador anonimiza terminales, elimina efímeros y conserva activos con motivo. */
	public function test_erases_terminal_and_ephemeral_data_but_retains_active_records(): void {
		global $wpdb;

		$user_id = self::factory()->user->create( array( 'user_email' => self::EMAIL ) );
		$ids     = $this->seed_domain( $user_id );
		$result  = PrivacyTools::erase_personal_data( self::EMAIL );

		$this->assertTrue( $result['done'] );
		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertCount( 2, $result['messages'] );

		$orders = Schema::orders_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla interna fija y valor preparado.
		$terminal_order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$orders} WHERE id = %d", $ids['terminal_order'] ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla interna fija y valor preparado.
		$active_order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$orders} WHERE id = %d", $ids['active_order'] ), ARRAY_A );
		$this->assertNull( $terminal_order['user_id'] );
		$this->assertNull( $terminal_order['customer_email'] );
		$this->assertSame( '', $terminal_order['customer_phone'] );
		$this->assertSame( self::EMAIL, $active_order['customer_email'] );

		$reservations = Schema::reservations_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla interna fija y valor preparado.
		$terminal_reservation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$reservations} WHERE id = %d", $ids['terminal_reservation'] ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla interna fija y valor preparado.
		$active_reservation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$reservations} WHERE id = %d", $ids['active_reservation'] ), ARRAY_A );
		$this->assertNull( $terminal_reservation['guest_email'] );
		$this->assertNull( $terminal_reservation['notes'] );
		$this->assertSame( self::EMAIL, $active_reservation['guest_email'] );

		$evidence = Schema::payment_evidence_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla interna fija y valor preparado.
		$reference = $wpdb->get_var( $wpdb->prepare( "SELECT reference_text FROM {$evidence} WHERE order_id = %d", $ids['terminal_order'] ) );
		$this->assertSame( 'Datos eliminados', $reference );

		$order_items = Schema::order_items_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla interna fija y valor preparado.
		$snapshots = $wpdb->get_row( $wpdb->prepare( "SELECT selection_json, snapshot_json FROM {$order_items} WHERE order_id = %d", $ids['terminal_order'] ), ARRAY_A );
		$this->assertStringNotContainsString( 'nota privada', $snapshots['selection_json'] );
		$this->assertStringNotContainsString( 'nota privada', $snapshots['snapshot_json'] );
		$this->assertSame( 0, $this->count_for_user( Schema::saved_pizzas_table_name(), $user_id ) );
		$this->assertSame( 0, $this->count_for_user( Schema::carts_table_name(), $user_id ) );
		$this->assertSame( 0, $this->count_for_user( Schema::cart_sessions_table_name(), $user_id ) );
	}

	/**
	 * Crea dos autoridades terminales, dos activas y recursos efímeros.
	 *
	 * @param int $user_id Cuenta propietaria.
	 * @return array<string, int>
	 */
	private function seed_domain( int $user_id ): array {
		$terminal_order = $this->insert_order( $user_id, 'completado', '1' );
		$active_order   = $this->insert_order( $user_id, 'confirmado', '2' );
		$this->insert_order_item( $terminal_order );
		$this->insert_payment_evidence( $terminal_order );
		$terminal_reservation = $this->insert_reservation( $user_id, 'completada', '1' );
		$active_reservation   = $this->insert_reservation( $user_id, 'confirmada', '2' );
		$this->insert_saved_pizza( $user_id );
		$this->insert_cart( $user_id );

		return compact( 'terminal_order', 'active_order', 'terminal_reservation', 'active_reservation' );
	}

	/**
	 * Inserta un pedido mínimo válido para la política de privacidad.
	 *
	 * @param int    $user_id Cuenta propietaria.
	 * @param string $status  Estado del pedido.
	 * @param string $suffix  Sufijo único del fixture.
	 */
	private function insert_order( int $user_id, string $status, string $suffix ): int {
		$now = current_time( 'mysql', true );
		$this->insert_row(
			Schema::orders_table_name(),
			array(
				'public_id'           => "00000000-0000-4000-8000-00000000000{$suffix}",
				'order_number'        => "ORDER-PRIVACY-{$suffix}",
				'cart_public_id'      => "10000000-0000-4000-8000-00000000000{$suffix}",
				'user_id'             => $user_id,
				'access_token_hash'   => str_repeat( 'a', 64 ),
				'status'              => $status,
				'revision'            => 1,
				'fulfillment'         => 'pickup',
				'customer_name'       => 'Persona privada',
				'customer_email'      => self::EMAIL,
				'customer_phone'      => '+580000000000',
				'customer_note'       => 'nota privada',
				'currency'            => 'USD',
				'subtotal_minor'      => 1000,
				'discount_total'      => 0,
				'tax_total'           => 0,
				'tip_total'           => 0,
				'delivery_total'      => 0,
				'total_minor'         => 1000,
				'totals_json'         => '{}',
				'payment_expires_at'  => gmdate( 'Y-m-d H:i:s', strtotime( '+30 minutes' ) ),
				'payment_sync_status' => 'synced',
				'payment_revision'    => 1,
				'projection_status'   => 'pending',
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);

		return $this->last_insert_id();
	}

	/**
	 * Inserta una línea con texto libre que debe anonimizarse.
	 *
	 * @param int $order_id Pedido propietario.
	 */
	private function insert_order_item( int $order_id ): void {
		$this->insert_row(
			Schema::order_items_table_name(),
			array(
				'public_id'        => '20000000-0000-4000-8000-000000000001',
				'order_id'         => $order_id,
				'line_public_id'   => '30000000-0000-4000-8000-000000000001',
				'type'             => 'menu_item',
				'quantity'         => 1,
				'selection_json'   => '{"note":"nota privada","item":"pizza"}',
				'snapshot_json'    => '{"note":"nota privada","name":"Pizza"}',
				'unit_price_minor' => 1000,
				'line_total_minor' => 1000,
				'created_at'       => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Inserta evidencia privada vinculada al pedido terminal.
	 *
	 * @param int $order_id Pedido propietario.
	 */
	private function insert_payment_evidence( int $order_id ): void {
		$now = current_time( 'mysql', true );
		$this->insert_row(
			Schema::payment_evidence_table_name(),
			array(
				'public_id'                => '40000000-0000-4000-8000-000000000001',
				'order_id'                 => $order_id,
				'idempotency_hash'         => str_repeat( 'b', 64 ),
				'request_hash'             => str_repeat( 'c', 64 ),
				'reference_text'           => 'REF-PRIVADA-42',
				'payment_request_revision' => 1,
				'status'                   => 'synced',
				'created_at'               => $now,
				'updated_at'               => $now,
			)
		);
	}

	/**
	 * Inserta una reserva mínima válida para la política de privacidad.
	 *
	 * @param int    $user_id Cuenta propietaria.
	 * @param string $status  Estado de la reserva.
	 * @param string $suffix  Sufijo único del fixture.
	 */
	private function insert_reservation( int $user_id, string $status, string $suffix ): int {
		$now = current_time( 'mysql', true );
		$this->insert_row(
			Schema::reservations_table_name(),
			array(
				'public_id'         => "50000000-0000-4000-8000-00000000000{$suffix}",
				'confirmation_code' => "RES-PRIVACY-{$suffix}",
				'access_token_hash' => str_repeat( 'd', 64 ),
				'user_id'           => $user_id,
				'status'            => $status,
				'revision'          => 1,
				'guest_name'        => 'Persona privada',
				'guest_phone'       => '+580000000000',
				'guest_email'       => self::EMAIL,
				'notes'             => 'notas privadas',
				'zone_preference'   => 'Terraza',
				'party_size'        => 2,
				'interval_minutes'  => 30,
				'local_date'        => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
				'local_time'        => '18:00:00',
				'timezone'          => 'America/Caracas',
				'starts_at_utc'     => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ),
				'ends_at_utc'       => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days +90 minutes' ) ),
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);

		return $this->last_insert_id();
	}

	/**
	 * Inserta una pizza guardada de la cuenta.
	 *
	 * @param int $user_id Cuenta propietaria.
	 */
	private function insert_saved_pizza( int $user_id ): void {
		$now = current_time( 'mysql', true );
		$this->insert_row(
			Schema::saved_pizzas_table_name(),
			array(
				'public_id'             => '60000000-0000-4000-8000-000000000001',
				'user_id'               => $user_id,
				'name'                  => 'Favorita privada',
				'configuration_version' => 1,
				'configuration_json'    => '{"version":1}',
				'revision'              => 1,
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);
	}

	/**
	 * Inserta sesión, carrito y línea efímeros de la cuenta.
	 *
	 * @param int $user_id Cuenta propietaria.
	 */
	private function insert_cart( int $user_id ): void {
		$now = current_time( 'mysql', true );
		$this->insert_row(
			Schema::cart_sessions_table_name(),
			array(
				'public_id'   => '70000000-0000-4000-8000-000000000001',
				'secret_hash' => str_repeat( 'e', 64 ),
				'csrf_hash'   => str_repeat( 'f', 64 ),
				'user_id'     => $user_id,
				'expires_at'  => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day' ) ),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$session_id = $this->last_insert_id();
		$this->insert_row(
			Schema::carts_table_name(),
			array(
				'public_id'             => '80000000-0000-4000-8000-000000000001',
				'owner_key'             => 'owner-private',
				'session_id'            => $session_id,
				'user_id'               => $user_id,
				'status'                => 'active',
				'revision'              => 1,
				'fulfillment'           => 'pickup',
				'tip_rate_bps'          => 0,
				'subtotal_minor'        => 1000,
				'totals_json'           => '{}',
				'catalog_revision'      => 1,
				'availability_revision' => 1,
				'pricing_revision'      => 1,
				'expires_at'            => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day' ) ),
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);
	}

	/**
	 * Inserta una fila y exige que MySQL la acepte.
	 *
	 * @param string               $table Nombre interno de tabla.
	 * @param array<string, mixed> $data  Valores.
	 */
	private function insert_row( string $table, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertNotFalse( $wpdb->insert( $table, $data ) );
	}

	/** Devuelve el último ID generado por la conexión de prueba. */
	private function last_insert_id(): int {
		global $wpdb;

		return (int) $wpdb->insert_id;
	}

	/**
	 * Cuenta recursos efímeros pertenecientes a la cuenta.
	 *
	 * @param string $table   Tabla interna.
	 * @param int    $user_id Cuenta propietaria.
	 */
	private function count_for_user( string $table, int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla interna fija y valor preparado.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );
	}

	/** Vacía exclusivamente las autoridades usadas por la prueba. */
	private function truncate_tables(): void {
		global $wpdb;

		$tables = array(
			Schema::payment_evidence_table_name(),
			Schema::order_items_table_name(),
			Schema::orders_table_name(),
			Schema::reservations_table_name(),
			Schema::saved_pizzas_table_name(),
			Schema::cart_items_table_name(),
			Schema::carts_table_name(),
			Schema::cart_sessions_table_name(),
		);
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombres internos fijos.
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
	}
}
