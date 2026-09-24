<?php
/**
 * Pruebas de integración del servicio público con WordPress y MySQL.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Payments\EventPublisher;
use Vicu\Restaurante\Payments\ManualSubmissionRepository;
use Vicu\Restaurante\Payments\PaymentRequestRepository;
use Vicu\Restaurante\Payments\PaymentRequests;
use Vicu\Restaurante\Payments\PaymentRequestState;

/**
 * Verifica idempotencia, atomicidad, concurrencia, expiración y eventos.
 */
final class PaymentRequestsTest extends WP_UnitTestCase {
	/**
	 * Aísla la tabla propia antes de cada escenario.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		vicu_restaurante_reset_payment_requests();
	}

	/**
	 * Repetir los mismos datos devuelve la misma solicitud y un solo efecto.
	 *
	 * @return void
	 */
	public function test_creation_is_idempotent_for_equal_data(): void {
		$events   = 0;
		$listener = static function () use ( &$events ): void {
			++$events;
		};
		add_action( 'vicu_pagos_creado', $listener );

		$first  = PaymentRequests::create( $this->attributes( 'ORD-IDEMPOTENT' ) );
		$second = PaymentRequests::create( $this->attributes( 'ORD-IDEMPOTENT' ) );

		remove_action( 'vicu_pagos_creado', $listener );

		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $events );
		$this->assertNull( $first['provider'] );
		$this->assertSame( 1, $this->stored_request_count() );
		$this->assertSame( 'vicu_payment_req', get_post_type( $first['id'] ) );
	}

	/**
	 * Una referencia con datos distintos produce colisión sin mutación parcial.
	 *
	 * @return void
	 */
	public function test_creation_detects_incompatible_reference_collision(): void {
		$first = PaymentRequests::create( $this->attributes( 'ORD-COLLISION' ) );
		$this->assertNotWPError( $first );

		$incompatible                 = $this->attributes( 'ORD-COLLISION' );
		$incompatible['amount_minor'] = 9999;
		$collision                    = PaymentRequests::create( $incompatible );

		$this->assertWPError( $collision );
		$this->assertSame( 'vicu_pagos_reference_collision', $collision->get_error_code() );
		$this->assertSame( $first['id'], $collision->get_error_data()['request_id'] );
		$this->assertSame( 1, $this->stored_request_count() );
		$this->assertSame( 1, $this->payment_post_count() );
		$this->assertSame( 1234, PaymentRequests::get( $first['id'] )['amount_minor'] );
	}

	/**
	 * Recorre todas las aristas permitidas y comprueba revisiones y hooks únicos.
	 *
	 * @return void
	 */
	public function test_all_allowed_transitions_persist_once(): void {
		$events             = array(
			'confirmado' => 0,
			'rechazado'  => 0,
			'expirado'   => 0,
		);
		$confirmed_listener = static function () use ( &$events ): void {
			++$events['confirmado'];
		};
		$rejected_listener  = static function () use ( &$events ): void {
			++$events['rechazado'];
		};
		$expired_listener   = static function () use ( &$events ): void {
			++$events['expirado'];
		};
		add_action( 'vicu_pagos_confirmado', $confirmed_listener );
		add_action( 'vicu_pagos_rechazado', $rejected_listener );
		add_action( 'vicu_pagos_expirado', $expired_listener );

		$confirmed = PaymentRequests::create( $this->attributes( 'ORD-CONFIRMED' ) );
		$confirmed = PaymentRequests::transition( $confirmed['id'], PaymentRequestState::PROOF_UPLOADED, 1 );
		$confirmed = PaymentRequests::transition( $confirmed['id'], PaymentRequestState::CONFIRMED, 2 );

		$retried = PaymentRequests::create( $this->attributes( 'ORD-RETRIED' ) );
		$retried = PaymentRequests::transition( $retried['id'], PaymentRequestState::PROOF_UPLOADED, 1 );
		$retried = PaymentRequests::transition( $retried['id'], PaymentRequestState::REJECTED, 2 );
		$retried = PaymentRequests::transition( $retried['id'], PaymentRequestState::PROOF_UPLOADED, 3 );
		$retried = PaymentRequests::transition( $retried['id'], PaymentRequestState::CONFIRMED, 4 );

		$expired = PaymentRequests::create( $this->attributes( 'ORD-EXPIRED' ) );
		$expired = PaymentRequests::expire( $expired['id'], 1 );

		$rejected_expired = PaymentRequests::create( $this->attributes( 'ORD-REJECTED-EXPIRED' ) );
		$rejected_expired = PaymentRequests::transition( $rejected_expired['id'], PaymentRequestState::PROOF_UPLOADED, 1 );
		$rejected_expired = PaymentRequests::transition( $rejected_expired['id'], PaymentRequestState::REJECTED, 2 );
		$rejected_expired = PaymentRequests::expire( $rejected_expired['id'], 3 );

		remove_action( 'vicu_pagos_confirmado', $confirmed_listener );
		remove_action( 'vicu_pagos_rechazado', $rejected_listener );
		remove_action( 'vicu_pagos_expirado', $expired_listener );

		$this->assertSame( PaymentRequestState::CONFIRMED, $confirmed['state'] );
		$this->assertSame( 3, $confirmed['revision'] );
		$this->assertSame( PaymentRequestState::CONFIRMED, $retried['state'] );
		$this->assertSame( 5, $retried['revision'] );
		$this->assertSame( PaymentRequestState::EXPIRED, $expired['state'] );
		$this->assertSame( 2, $expired['revision'] );
		$this->assertSame( PaymentRequestState::EXPIRED, $rejected_expired['state'] );
		$this->assertSame( 4, $rejected_expired['revision'] );
		$this->assertSame(
			array(
				'confirmado' => 2,
				'rechazado'  => 2,
				'expirado'   => 2,
			),
			$events
		);
	}

	/**
	 * Una transición inválida o terminal no cambia revisión ni emite evento.
	 *
	 * @return void
	 */
	public function test_invalid_and_terminal_transitions_have_no_effects(): void {
		$confirmed_events = 0;
		$listener         = static function () use ( &$confirmed_events ): void {
			++$confirmed_events;
		};
		add_action( 'vicu_pagos_confirmado', $listener );

		$request = PaymentRequests::create( $this->attributes( 'ORD-INVALID' ) );
		$invalid = PaymentRequests::transition( $request['id'], PaymentRequestState::CONFIRMED, 1 );
		$current = PaymentRequests::get( $request['id'] );

		$this->assertWPError( $invalid );
		$this->assertSame( 'vicu_pagos_invalid_transition', $invalid->get_error_code() );
		$this->assertSame( PaymentRequestState::PENDING, $current['state'] );
		$this->assertSame( 1, $current['revision'] );

		$current  = PaymentRequests::transition( $request['id'], PaymentRequestState::PROOF_UPLOADED, 1 );
		$current  = PaymentRequests::transition( $request['id'], PaymentRequestState::CONFIRMED, 2 );
		$terminal = PaymentRequests::transition( $request['id'], PaymentRequestState::REJECTED, 3 );

		remove_action( 'vicu_pagos_confirmado', $listener );

		$this->assertWPError( $terminal );
		$this->assertSame( 'vicu_pagos_invalid_transition', $terminal->get_error_code() );
		$this->assertSame( 1, $confirmed_events );
		$this->assertSame( 3, PaymentRequests::get( $request['id'] )['revision'] );
	}

	/**
	 * Una revisión obsoleta no puede sobrescribir el estado vigente.
	 *
	 * @return void
	 */
	public function test_stale_revision_rejects_concurrent_transition(): void {
		$rejected_events = 0;
		$listener        = static function () use ( &$rejected_events ): void {
			++$rejected_events;
		};
		add_action( 'vicu_pagos_rechazado', $listener );

		$request = PaymentRequests::create( $this->attributes( 'ORD-CONCURRENT' ) );
		$updated = PaymentRequests::transition( $request['id'], PaymentRequestState::PROOF_UPLOADED, 1 );
		$stale   = PaymentRequests::transition( $request['id'], PaymentRequestState::REJECTED, 1 );
		$current = PaymentRequests::get( $request['id'] );

		remove_action( 'vicu_pagos_rechazado', $listener );

		$this->assertSame( 2, $updated['revision'] );
		$this->assertWPError( $stale );
		$this->assertSame( 'vicu_pagos_concurrent_transition', $stale->get_error_code() );
		$this->assertSame( PaymentRequestState::PROOF_UPLOADED, $current['state'] );
		$this->assertSame( 2, $current['revision'] );
		$this->assertSame( 0, $rejected_events );
	}

	/**
	 * La expiración por lote y el reintento no duplican revisión ni hook.
	 *
	 * @return void
	 */
	public function test_expiration_is_repeatable_without_duplicate_effects(): void {
		$payloads = array();
		$listener = static function ( array $payload ) use ( &$payloads ): void {
			$payloads[] = $payload;
		};
		add_action( 'vicu_pagos_expirado', $listener );

		$attributes               = $this->attributes( 'ORD-DUE' );
		$attributes['expires_at'] = '2026-08-12T12:00:00Z';
		$request                  = PaymentRequests::create( $attributes );
		$first_batch              = PaymentRequests::expire_due( '2026-08-13T12:00:00Z' );
		$repeated                 = PaymentRequests::expire( $request['id'], 1 );
		$second_batch             = PaymentRequests::expire_due( '2026-08-13T12:00:00Z' );

		remove_action( 'vicu_pagos_expirado', $listener );

		$this->assertSame(
			array(
				'expired' => 1,
				'skipped' => 0,
				'errors'  => array(),
			),
			$first_batch
		);
		$this->assertSame(
			array(
				'expired' => 0,
				'skipped' => 0,
				'errors'  => array(),
			),
			$second_batch
		);
		$this->assertSame( PaymentRequestState::EXPIRED, $repeated['state'] );
		$this->assertSame( 2, $repeated['revision'] );
		$this->assertCount( 1, $payloads );
	}

	/**
	 * Los payloads se versionan y los callbacks observan persistencia confirmada.
	 *
	 * @return void
	 */
	public function test_events_are_versioned_and_emitted_after_persistence(): void {
		$created_payload    = null;
		$confirmed_payload  = null;
		$created_listener   = static function ( array $payload ) use ( &$created_payload ): void {
			$created_payload = array(
				'payload'   => $payload,
				'persisted' => PaymentRequests::get( $payload['request']['id'] ),
			);
		};
		$confirmed_listener = static function ( array $payload ) use ( &$confirmed_payload ): void {
			$confirmed_payload = array(
				'payload'   => $payload,
				'persisted' => PaymentRequests::get( $payload['request']['id'] ),
			);
		};
		add_action( 'vicu_pagos_creado', $created_listener );
		add_action( 'vicu_pagos_confirmado', $confirmed_listener );

		$request = PaymentRequests::create( $this->attributes( 'ORD-EVENTS' ) );
		$request = PaymentRequests::transition( $request['id'], PaymentRequestState::PROOF_UPLOADED, 1 );
		$request = PaymentRequests::transition( $request['id'], PaymentRequestState::CONFIRMED, 2 );

		remove_action( 'vicu_pagos_creado', $created_listener );
		remove_action( 'vicu_pagos_confirmado', $confirmed_listener );

		$this->assertSame( EventPublisher::PAYLOAD_VERSION, $created_payload['payload']['payload_version'] );
		$this->assertSame( $created_payload['payload']['request'], $created_payload['persisted'] );
		$this->assertNull( $created_payload['payload']['transition']['from'] );
		$this->assertSame( PaymentRequestState::PENDING, $created_payload['payload']['transition']['to'] );
		$this->assertSame( EventPublisher::PAYLOAD_VERSION, $confirmed_payload['payload']['payload_version'] );
		$this->assertSame( $confirmed_payload['payload']['request'], $confirmed_payload['persisted'] );
		$this->assertSame( PaymentRequestState::PROOF_UPLOADED, $confirmed_payload['payload']['transition']['from'] );
		$this->assertSame( PaymentRequestState::CONFIRMED, $request['state'] );
	}

	/**
	 * Comprueba que la tabla usa InnoDB e índices únicos contractuales.
	 *
	 * @return void
	 */
	public function test_schema_uses_transactional_engine_and_unique_keys(): void {
		global $wpdb;

		$table = PaymentRequestRepository::table_name();
		$query = $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$status = $wpdb->get_row( $query, ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$unique  = array();

		foreach ( $indexes as $index ) {
			if ( 0 === (int) $index['Non_unique'] ) {
				$unique[] = $index['Key_name'];
			}
		}

		$this->assertSame( 'InnoDB', $status['Engine'] );
		$this->assertContains( 'reference_hash', $unique );
		$this->assertContains( 'post_id', $unique );

		$submission_table = ManualSubmissionRepository::table_name();
		$submission_query = $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $submission_table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$submission_status = $wpdb->get_row( $submission_query, ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$submission_indexes = $wpdb->get_results( "SHOW INDEX FROM {$submission_table}", ARRAY_A );
		$submission_unique  = array();

		foreach ( $submission_indexes as $index ) {
			if ( 0 === (int) $index['Non_unique'] ) {
				$submission_unique[] = $index['Key_name'];
			}
		}

		$this->assertSame( 'InnoDB', $submission_status['Engine'] );
		$this->assertContains( 'request_idempotency', $submission_unique );
		$this->assertSame( '2', get_option( 'vicu_pagos_db_version' ) );
	}

	/**
	 * Eliminar el post administrativo también retira su fila interna.
	 *
	 * @return void
	 */
	public function test_post_deletion_removes_internal_storage(): void {
		$request = PaymentRequests::create( $this->attributes( 'ORD-DELETE' ) );
		$this->assertNotWPError( $request );

		wp_delete_post( $request['id'], true );

		$this->assertSame( 0, $this->stored_request_count() );
		$this->assertWPError( PaymentRequests::get( $request['id'] ) );
	}

	/**
	 * Datos válidos comunes para una creación.
	 *
	 * @param string $external_id Identificador único del escenario.
	 * @return array<string, mixed>
	 */
	private function attributes( string $external_id ): array {
		return array(
			'external_type' => 'vicu_order',
			'external_id'   => $external_id,
			'amount_minor'  => 1234,
			'currency'      => 'USD',
		);
	}

	/**
	 * Cuenta filas autoritativas sin depender de post meta.
	 *
	 * @return int
	 */
	private function stored_request_count(): int {
		global $wpdb;

		$table = PaymentRequestRepository::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Cuenta representaciones administrativas persistidas.
	 *
	 * @return int
	 */
	private function payment_post_count(): int {
		$query = new WP_Query(
			array(
				'post_type'      => 'vicu_payment_req',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		return $query->post_count;
	}
}
