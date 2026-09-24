<?php
/**
 * Pruebas de integración del proveedor manual.
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Payments\EventPublisher;
use Vicu\Restaurante\Payments\ManualPaymentProvider;
use Vicu\Restaurante\Payments\ManualSubmissionRepository;
use Vicu\Restaurante\Payments\PaymentRequests;
use Vicu\Restaurante\Payments\PaymentRequestState;
use Vicu\Restaurante\Payments\PostTypes\PaymentRequest;

/**
 * Verifica configuración, atomicidad, idempotencia y eventos post-commit.
 */
final class ManualPaymentProviderTest extends WP_UnitTestCase {
	/**
	 * Aísla solicitudes, entregas y configuración.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		vicu_restaurante_reset_payment_requests();
	}

	/**
	 * La configuración es cerrada, booleana y deshabilitada por defecto.
	 *
	 * @return void
	 */
	public function test_configuration_is_strict_and_disabled_by_default(): void {
		$this->assertSame(
			array(
				'provider' => 'manual',
				'enabled'  => false,
			),
			ManualPaymentProvider::get_configuration()
		);

		$invalid_type = ManualPaymentProvider::configure( array( 'enabled' => 1 ) );
		$unknown_key  = ManualPaymentProvider::configure(
			array(
				'enabled' => true,
				'account' => 'not-supported',
			)
		);

		$this->assertWPError( $invalid_type );
		$this->assertWPError( $unknown_key );
		$this->assertSame( 'vicu_pagos_manual_invalid_configuration', $unknown_key->get_error_code() );
		$this->assertFalse( ManualPaymentProvider::get_configuration()['enabled'] );
		$this->assertTrue( ManualPaymentProvider::configure( array( 'enabled' => true ) )['enabled'] );
		$this->assertWPError( ManualPaymentProvider::configure( array( 'enabled' => 'false' ) ) );
		$this->assertTrue( ManualPaymentProvider::get_configuration()['enabled'] );
	}

	/**
	 * La representación escalar de wp_options conserva el estado entre solicitudes.
	 *
	 * @return void
	 */
	public function test_enabled_configuration_survives_option_cache_boundary(): void {
		$this->assertTrue( ManualPaymentProvider::configure( array( 'enabled' => true ) )['enabled'] );

		wp_cache_delete( 'vicu_pagos_manual_enabled', 'options' );

		$this->assertSame( '1', get_option( 'vicu_pagos_manual_enabled' ) );
		$this->assertTrue( ManualPaymentProvider::get_configuration()['enabled'] );
	}

	/**
	 * Un proveedor deshabilitado y un payload inválido no dejan efectos.
	 *
	 * @return void
	 */
	public function test_disabled_or_invalid_submission_has_no_effects(): void {
		$request  = $this->create_request( 'ORD-MANUAL-DISABLED' );
		$disabled = ManualPaymentProvider::submit_proof( $request['id'], $this->submission(), 1 );

		$this->assertWPError( $disabled );
		$this->assertSame( 'vicu_pagos_manual_provider_disabled', $disabled->get_error_code() );

		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$invalid = ManualPaymentProvider::submit_proof(
			$request['id'],
			array(
				'proof_reference' => '',
				'idempotency_key' => 'key-1',
			),
			1
		);

		$this->assertWPError( $invalid );
		$this->assertSame( 'vicu_pagos_manual_invalid_submission', $invalid->get_error_code() );
		$this->assertSame( PaymentRequestState::PENDING, PaymentRequests::get( $request['id'] )['state'] );
		$this->assertSame( 0, $this->submission_count() );
	}

	/**
	 * Solicitudes ausentes y claves adicionales devuelven errores contractuales.
	 *
	 * @return void
	 */
	public function test_missing_request_and_unknown_submission_keys_are_rejected(): void {
		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$missing = ManualPaymentProvider::submit_proof( 999999, $this->submission(), 1 );

		$this->assertWPError( $missing );
		$this->assertSame( 'vicu_pagos_request_not_found', $missing->get_error_code() );

		$request                  = $this->create_request( 'ORD-MANUAL-UNKNOWN-KEY' );
		$with_unknown_key         = $this->submission();
		$with_unknown_key['file'] = '/tmp/proof.jpg';
		$invalid                  = ManualPaymentProvider::submit_proof( $request['id'], $with_unknown_key, 1 );

		$this->assertWPError( $invalid );
		$this->assertSame( 'vicu_pagos_manual_invalid_submission', $invalid->get_error_code() );
		$this->assertSame( 0, $this->submission_count() );
	}

	/**
	 * Una entrega persiste historial, proveedor, meta y transición una sola vez.
	 *
	 * @return void
	 */
	public function test_submission_persists_public_result_and_transition(): void {
		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$request = $this->create_request( 'ORD-MANUAL-SUCCESS' );
		$result  = ManualPaymentProvider::submit_proof( $request['id'], $this->submission(), 1 );

		$this->assertNotWPError( $result );
		$this->assertSame( 'manual', $result['request']['provider'] );
		$this->assertSame( PaymentRequestState::PROOF_UPLOADED, $result['request']['state'] );
		$this->assertSame( 2, $result['request']['revision'] );
		$this->assertSame( 'manual', $result['submission']['provider'] );
		$this->assertSame( 'evidence:ORD-42:1', $result['submission']['proof_reference'] );
		$this->assertSame( 2, $result['submission']['request_revision'] );
		$this->assertArrayNotHasKey( 'idempotency_key', $result['submission'] );
		$this->assertSame( 'manual', get_post_meta( $request['id'], PaymentRequest::META_PROVIDER, true ) );
		$this->assertSame( 1, $this->submission_count() );
		$this->assertSame(
			$result['submission'],
			ManualPaymentProvider::get_submission( $request['id'], $result['submission']['id'] )
		);
	}

	/**
	 * El mismo reintento acepta la revisión original y no duplica efectos.
	 *
	 * @return void
	 */
	public function test_identical_retry_is_idempotent_with_stale_revision(): void {
		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$events   = 0;
		$listener = static function () use ( &$events ): void {
			++$events;
		};
		add_action( 'vicu_pagos_comprobante_recibido', $listener );

		$request = $this->create_request( 'ORD-MANUAL-RETRY' );
		$first   = ManualPaymentProvider::submit_proof( $request['id'], $this->submission(), 1 );
		$retry   = ManualPaymentProvider::submit_proof( $request['id'], $this->submission(), 1 );

		remove_action( 'vicu_pagos_comprobante_recibido', $listener );

		$this->assertNotWPError( $first );
		$this->assertSame( $first, $retry );
		$this->assertSame( 1, $events );
		$this->assertSame( 1, $this->submission_count() );
		$this->assertSame( 2, PaymentRequests::get( $request['id'] )['revision'] );
	}

	/**
	 * Reutilizar la clave con otra referencia produce una colisión explícita.
	 *
	 * @return void
	 */
	public function test_idempotency_collision_does_not_mutate_request(): void {
		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$request = $this->create_request( 'ORD-MANUAL-COLLISION' );
		ManualPaymentProvider::submit_proof( $request['id'], $this->submission(), 1 );

		$collision_submission                    = $this->submission();
		$collision_submission['proof_reference'] = 'evidence:ORD-42:DIFFERENT';
		$collision                               = ManualPaymentProvider::submit_proof(
			$request['id'],
			$collision_submission,
			2
		);

		$this->assertWPError( $collision );
		$this->assertSame( 'vicu_pagos_manual_submission_collision', $collision->get_error_code() );
		$this->assertSame( 1, $this->submission_count() );
		$this->assertSame( 2, PaymentRequests::get( $request['id'] )['revision'] );
	}

	/**
	 * Una clave nueva con revisión obsoleta no puede crear historial parcial.
	 *
	 * @return void
	 */
	public function test_stale_new_submission_is_rejected_atomically(): void {
		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$request = $this->create_request( 'ORD-MANUAL-CONCURRENT' );
		ManualPaymentProvider::submit_proof( $request['id'], $this->submission(), 1 );
		PaymentRequests::transition( $request['id'], PaymentRequestState::REJECTED, 2 );

		$next                    = $this->submission();
		$next['idempotency_key'] = 'manual-key-2';
		$stale                   = ManualPaymentProvider::submit_proof( $request['id'], $next, 2 );

		$this->assertWPError( $stale );
		$this->assertSame( 'vicu_pagos_concurrent_transition', $stale->get_error_code() );
		$this->assertSame( 1, $this->submission_count() );
		$this->assertSame( PaymentRequestState::REJECTED, PaymentRequests::get( $request['id'] )['state'] );
		$this->assertSame( 3, PaymentRequests::get( $request['id'] )['revision'] );
	}

	/**
	 * El rechazo permite una nueva entrega y conserva ambas entradas históricas.
	 *
	 * @return void
	 */
	public function test_rejected_request_accepts_a_new_submission(): void {
		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$request  = $this->create_request( 'ORD-MANUAL-RESUBMIT' );
		$first    = ManualPaymentProvider::submit_proof( $request['id'], $this->submission(), 1 );
		$rejected = PaymentRequests::transition( $request['id'], PaymentRequestState::REJECTED, 2 );

		$next                    = $this->submission();
		$next['proof_reference'] = 'evidence:ORD-42:2';
		$next['idempotency_key'] = 'manual-key-2';
		$second                  = ManualPaymentProvider::submit_proof( $request['id'], $next, $rejected['revision'] );

		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->assertSame( 4, $second['request']['revision'] );
		$this->assertSame( 4, $second['submission']['request_revision'] );
		$this->assertSame( 2, $this->submission_count() );
	}

	/**
	 * Un estado terminal rechaza una entrega nueva sin efecto.
	 *
	 * @return void
	 */
	public function test_terminal_request_rejects_new_submission(): void {
		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$request = $this->create_request( 'ORD-MANUAL-TERMINAL' );
		ManualPaymentProvider::submit_proof( $request['id'], $this->submission(), 1 );
		PaymentRequests::transition( $request['id'], PaymentRequestState::CONFIRMED, 2 );

		$next                    = $this->submission();
		$next['idempotency_key'] = 'manual-key-2';
		$terminal                = ManualPaymentProvider::submit_proof( $request['id'], $next, 3 );

		$this->assertWPError( $terminal );
		$this->assertSame( 'vicu_pagos_invalid_transition', $terminal->get_error_code() );
		$this->assertSame( 1, $this->submission_count() );
		$this->assertSame( 3, PaymentRequests::get( $request['id'] )['revision'] );
	}

	/**
	 * El evento está versionado y su callback observa ambos registros confirmados.
	 *
	 * @return void
	 */
	public function test_event_is_versioned_and_emitted_after_commit(): void {
		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$observed = null;
		$listener = static function ( array $payload ) use ( &$observed ): void {
			$observed = array(
				'payload'    => $payload,
				'request'    => PaymentRequests::get( $payload['request']['id'] ),
				'submission' => ManualPaymentProvider::get_submission(
					$payload['request']['id'],
					$payload['submission']['id']
				),
			);
		};
		add_action( 'vicu_pagos_comprobante_recibido', $listener );

		$request = $this->create_request( 'ORD-MANUAL-EVENT' );
		$result  = ManualPaymentProvider::submit_proof( $request['id'], $this->submission(), 1 );

		remove_action( 'vicu_pagos_comprobante_recibido', $listener );

		$this->assertNotNull( $observed );
		$this->assertSame( EventPublisher::PAYLOAD_VERSION, $observed['payload']['payload_version'] );
		$this->assertSame( 'comprobante_recibido', $observed['payload']['event'] );
		$this->assertSame( 'manual', $observed['payload']['provider'] );
		$this->assertSame( $result['request'], $observed['request'] );
		$this->assertSame( $result['submission'], $observed['submission'] );
		$this->assertSame( $result['submission'], $observed['payload']['submission'] );
	}

	/**
	 * Eliminar la solicitud retira también el historial interno.
	 *
	 * @return void
	 */
	public function test_post_deletion_removes_manual_history(): void {
		ManualPaymentProvider::configure( array( 'enabled' => true ) );
		$request = $this->create_request( 'ORD-MANUAL-DELETE' );
		$result  = ManualPaymentProvider::submit_proof( $request['id'], $this->submission(), 1 );

		wp_delete_post( $request['id'], true );

		$this->assertSame( 0, $this->submission_count() );
		$this->assertWPError(
			ManualPaymentProvider::get_submission( $request['id'], $result['submission']['id'] )
		);
	}

	/**
	 * Crea una solicitud válida para el escenario.
	 *
	 * @param string $external_id Referencia externa única.
	 * @return array<string, mixed>
	 */
	private function create_request( string $external_id ): array {
		$request = PaymentRequests::create(
			array(
				'external_type' => 'vicu_order',
				'external_id'   => $external_id,
				'amount_minor'  => 1234,
				'currency'      => 'USD',
			)
		);

		$this->assertNotWPError( $request );

		return $request;
	}

	/**
	 * Devuelve un payload manual válido común.
	 *
	 * @return array<string, string>
	 */
	private function submission(): array {
		return array(
			'proof_reference' => 'evidence:ORD-42:1',
			'idempotency_key' => 'manual-key-1',
		);
	}

	/**
	 * Cuenta entradas históricas sin exponerlas al consumidor.
	 *
	 * @return int
	 */
	private function submission_count(): int {
		global $wpdb;

		$table = ManualSubmissionRepository::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
