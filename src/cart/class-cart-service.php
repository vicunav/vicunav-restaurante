<?php
/**
 * Persistencia y mutaciones autoritativas del carrito.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Cart;

use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Catalog\CatalogDatabase;
use Vicu\Restaurante\Commerce\PricingRevision;
use Vicu\Restaurante\Commerce\TotalsService;
use Vicu\Restaurante\Menu\CatalogRevision;
use Vicu\Restaurante\Schema;
use Vicu\Restaurante\Settings\RestaurantSettings;
use WP_Error;

/**
 * Serializa cada mutación con bloqueo de fila y compare-and-swap.
 */
final class CartService {
	public const STATUS_ACTIVE  = 'active';
	public const STATUS_EXPIRED = 'expired';

	/**
	 * Registra y agenda la expiración repetible.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'vicu_restaurante_expire_carts', array( self::class, 'expire_due' ) );
	}

	/**
	 * Agenda una única tarea horaria.
	 *
	 * @return void
	 */
	public static function schedule_expiration(): void {
		if ( ! wp_next_scheduled( 'vicu_restaurante_expire_carts' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'vicu_restaurante_expire_carts' );
		}
	}

	/**
	 * Retira la tarea sin tocar carritos persistidos.
	 *
	 * @return void
	 */
	public static function unschedule_expiration(): void {
		wp_clear_scheduled_hook( 'vicu_restaurante_expire_carts' );
	}

	/**
	 * Crea o reutiliza el carrito activo exclusivo de una identidad.
	 *
	 * @param array<string, int|string> $identity Identidad validada.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( array $identity ): array|WP_Error {
		global $wpdb;

		if ( ! CatalogDatabase::begin() ) {
			return CatalogDatabase::storage_error();
		}

		$current = self::lock_by_owner( (string) $identity['key'] );

		if ( null !== $current && self::STATUS_ACTIVE === $current['status'] && $current['expires_at'] > current_time( 'mysql', true ) ) {
			CatalogDatabase::commit();
			return self::find( (string) $current['public_id'], $identity );
		}

		if ( null !== $current ) {
			self::mark_expired_row( (int) $current['id'] );
		}

		$now        = current_time( 'mysql', true );
		$expires_at = self::new_expiration();
		$totals     = TotalsService::calculate( 0, null, 0, 'pickup', null );

		if ( is_wp_error( $totals ) ) {
			CatalogDatabase::rollback();
			return $totals;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			Schema::carts_table_name(),
			array(
				'public_id'               => wp_generate_uuid4(),
				'owner_key'               => (string) $identity['key'],
				'session_id'              => 0 < (int) $identity['session_id'] ? (int) $identity['session_id'] : null,
				'user_id'                 => 0 < (int) $identity['user_id'] ? (int) $identity['user_id'] : null,
				'status'                  => self::STATUS_ACTIVE,
				'revision'                => 1,
				'discount_code'           => null,
				'fulfillment'             => 'pickup',
				'delivery_zone_public_id' => null,
				'tip_rate_bps'            => 0,
				'subtotal_minor'          => 0,
				'totals_json'             => wp_json_encode( $totals ),
				'catalog_revision'        => CatalogRevision::current(),
				'availability_revision'   => AvailabilityRevision::current(),
				'pricing_revision'        => PricingRevision::current(),
				'expires_at'              => $expires_at,
				'created_at'              => $now,
				'updated_at'              => $now,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::storage_error();
		}

		return self::get( $identity );
	}

	/**
	 * Devuelve el carrito activo propio.
	 *
	 * @param array<string, int|string> $identity Identidad validada.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( array $identity ): array|WP_Error {
		$row = self::row_by_owner( (string) $identity['key'] );

		if ( null === $row || self::STATUS_ACTIVE !== $row['status'] ) {
			return self::not_found();
		}

		if ( $row['expires_at'] <= current_time( 'mysql', true ) ) {
			self::expire_owner( (string) $identity['key'] );
			return self::not_found();
		}

		return self::format_cart( $row );
	}

	/**
	 * Asocia un carrito anónimo a una cuenta solo si no existe otro carrito de usuario.
	 *
	 * @param array<string, int|string> $session_identity Identidad anónima validada.
	 * @param array<string, int|string> $user_identity    Usuario autenticado.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function associate( array $session_identity, array $user_identity ): array|WP_Error {
		global $wpdb;

		if ( 'session' !== $session_identity['type'] || 'user' !== $user_identity['type'] || ! CatalogDatabase::begin() ) {
			return CatalogDatabase::storage_error();
		}

		$user_cart    = self::lock_by_owner( (string) $user_identity['key'] );
		$session_cart = self::lock_by_owner( (string) $session_identity['key'] );

		if ( null !== $user_cart && self::STATUS_ACTIVE === $user_cart['status'] && $user_cart['expires_at'] > current_time( 'mysql', true ) ) {
			CatalogDatabase::commit();
			return self::get( $user_identity );
		}

		if ( null === $session_cart || self::STATUS_ACTIVE !== $session_cart['status'] || $session_cart['expires_at'] <= current_time( 'mysql', true ) ) {
			CatalogDatabase::rollback();
			return self::create( $user_identity );
		}

		if ( null !== $user_cart ) {
			self::mark_expired_row( (int) $user_cart['id'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			Schema::carts_table_name(),
			array(
				'owner_key'  => (string) $user_identity['key'],
				'session_id' => null,
				'user_id'    => (int) $user_identity['user_id'],
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id'        => (int) $session_cart['id'],
				'owner_key' => (string) $session_identity['key'],
			),
			array( '%s', '%d', '%d', '%s' ),
			array( '%d', '%s' )
		);

		// Rotar significa que ningún secreto conocido vuelve a validar esta sesión.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$revoked = $wpdb->update(
			Schema::cart_sessions_table_name(),
			array(
				'secret_hash' => hash( 'sha256', random_bytes( 32 ) ),
				'csrf_hash'   => hash( 'sha256', random_bytes( 32 ) ),
				'user_id'     => (int) $user_identity['user_id'],
				'expires_at'  => current_time( 'mysql', true ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $session_identity['session_id'] ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( 1 !== $updated || false === $revoked || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::storage_error();
		}

		CartSessionService::clear_cookie();

		return self::get( $user_identity );
	}

	/**
	 * Añade una línea y fusiona solo líneas de menú equivalentes.
	 *
	 * @param array<string, int|string> $identity          Identidad.
	 * @param int                       $expected_revision Revisión del cliente.
	 * @param array<string, mixed>      $input             Selección sin precios.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function add_item( array $identity, int $expected_revision, array $input ): array|WP_Error {
		return self::mutate(
			$identity,
			$expected_revision,
			static function ( array $cart ) use ( $input ): true|WP_Error {
				global $wpdb;

				$quote = CartLinePricing::quote( $input );

				if ( is_wp_error( $quote ) ) {
					return $quote;
				}

				$table = Schema::cart_items_table_name();

				if ( 'menu' === $quote['type'] && null !== $quote['merge_hash'] ) {
					// El hash incluye selección normalizada y precio autoritativo.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cart_id = %d AND merge_hash = %s FOR UPDATE", $cart['id'], $quote['merge_hash'] ), ARRAY_A );

					if ( is_array( $existing ) ) {
						$quantity = (int) $existing['quantity'] + (int) $quote['quantity'];

						if ( 99 < $quantity ) {
							return self::invalid();
						}

						$selection             = self::decode_json( (string) $existing['selection_json'] );
						$selection['quantity'] = $quantity;
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$updated = $wpdb->update(
							$table,
							array(
								'quantity'       => $quantity,
								'selection_json' => wp_json_encode( $selection ),
								'updated_at'     => current_time( 'mysql', true ),
							),
							array( 'id' => (int) $existing['id'] ),
							array( '%d', '%s', '%s' ),
							array( '%d' )
						);

						return false === $updated ? CatalogDatabase::storage_error() : true;
					}
				}

				$now = current_time( 'mysql', true );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$inserted = $wpdb->insert(
					$table,
					array(
						'public_id'        => wp_generate_uuid4(),
						'cart_id'          => $cart['id'],
						'type'             => $quote['type'],
						'source_public_id' => $quote['source_public_id'],
						'quantity'         => $quote['quantity'],
						'selection_json'   => wp_json_encode( $quote['selection'] ),
						'snapshot_json'    => wp_json_encode( $quote['snapshot'] ),
						'unit_price_minor' => $quote['unit_price_minor'],
						'line_total_minor' => $quote['line_total_minor'],
						'merge_hash'       => $quote['merge_hash'],
						'created_at'       => $now,
						'updated_at'       => $now,
					),
					array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
				);

				return false === $inserted ? CatalogDatabase::storage_error() : true;
			}
		);
	}

	/**
	 * Sustituye una línea de forma atómica.
	 *
	 * @param array<string, int|string> $identity          Identidad.
	 * @param int                       $expected_revision Revisión esperada.
	 * @param string                    $line_public_id    UUID de línea.
	 * @param array<string, mixed>      $input             Nueva selección completa.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function replace_item( array $identity, int $expected_revision, string $line_public_id, array $input ): array|WP_Error {
		return self::mutate(
			$identity,
			$expected_revision,
			static function ( array $cart ) use ( $line_public_id, $input ): true|WP_Error {
				global $wpdb;

				$quote = CartLinePricing::quote( $input );

				if ( is_wp_error( $quote ) ) {
					return $quote;
				}

				// La línea original solo cambia dentro de la transacción confirmable.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->update(
					Schema::cart_items_table_name(),
					array(
						'type'             => $quote['type'],
						'source_public_id' => $quote['source_public_id'],
						'quantity'         => $quote['quantity'],
						'selection_json'   => wp_json_encode( $quote['selection'] ),
						'snapshot_json'    => wp_json_encode( $quote['snapshot'] ),
						'unit_price_minor' => $quote['unit_price_minor'],
						'line_total_minor' => $quote['line_total_minor'],
						'merge_hash'       => $quote['merge_hash'],
						'updated_at'       => current_time( 'mysql', true ),
					),
					array(
						'cart_id'   => $cart['id'],
						'public_id' => $line_public_id,
					),
					array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' ),
					array( '%d', '%s' )
				);

				return 1 === $updated ? true : self::not_found();
			}
		);
	}

	/**
	 * Elimina una línea propia.
	 *
	 * @param array<string, int|string> $identity          Identidad.
	 * @param int                       $expected_revision Revisión esperada.
	 * @param string                    $line_public_id    UUID de línea.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function remove_item( array $identity, int $expected_revision, string $line_public_id ): array|WP_Error {
		return self::mutate(
			$identity,
			$expected_revision,
			static function ( array $cart ) use ( $line_public_id ): true|WP_Error {
				global $wpdb;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$deleted = $wpdb->delete(
					Schema::cart_items_table_name(),
					array(
						'cart_id'   => $cart['id'],
						'public_id' => $line_public_id,
					),
					array( '%d', '%s' )
				);

				return 1 === $deleted ? true : self::not_found();
			}
		);
	}

	/**
	 * Aplica o retira un código sin consumir sus usos.
	 *
	 * @param array<string, int|string> $identity          Identidad.
	 * @param int                       $expected_revision Revisión esperada.
	 * @param string|null               $code              Código o null.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_discount( array $identity, int $expected_revision, ?string $code ): array|WP_Error {
		return self::set_cart_field( $identity, $expected_revision, 'discount_code', null === $code ? null : strtoupper( trim( sanitize_text_field( $code ) ) ) );
	}

	/**
	 * Cambia pickup o delivery con zona explícita.
	 *
	 * @param array<string, int|string> $identity          Identidad.
	 * @param int                       $expected_revision Revisión esperada.
	 * @param string                    $fulfillment       Tipo.
	 * @param string|null               $zone_public_id    Zona.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_fulfillment( array $identity, int $expected_revision, string $fulfillment, ?string $zone_public_id ): array|WP_Error {
		if ( ! in_array( $fulfillment, array( 'pickup', 'delivery' ), true ) || ( 'pickup' === $fulfillment && null !== $zone_public_id ) || ( 'delivery' === $fulfillment && ( null === $zone_public_id || ! wp_is_uuid( $zone_public_id, 4 ) ) ) ) {
			return self::invalid();
		}

		return self::mutate(
			$identity,
			$expected_revision,
			static function ( array $cart ) use ( $fulfillment, $zone_public_id ): true|WP_Error {
				global $wpdb;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->update(
					Schema::carts_table_name(),
					array(
						'fulfillment'             => $fulfillment,
						'delivery_zone_public_id' => $zone_public_id,
					),
					array( 'id' => $cart['id'] ),
					array( '%s', '%s' ),
					array( '%d' )
				);

				return false === $updated ? CatalogDatabase::storage_error() : true;
			}
		);
	}

	/**
	 * Define una tasa de propina configurada.
	 *
	 * @param array<string, int|string> $identity          Identidad.
	 * @param int                       $expected_revision Revisión esperada.
	 * @param int                       $tip_rate_bps       Tasa.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_tip( array $identity, int $expected_revision, int $tip_rate_bps ): array|WP_Error {
		if ( ! in_array( $tip_rate_bps, RestaurantSettings::tip_rates_bps(), true ) ) {
			return self::invalid();
		}

		return self::set_cart_field( $identity, $expected_revision, 'tip_rate_bps', $tip_rate_bps );
	}

	/**
	 * Expira carritos vencidos sin borrar historia ni pedidos futuros.
	 *
	 * @return int
	 */
	public static function expire_due(): int {
		global $wpdb;

		$table = Schema::carts_table_name();
		$now   = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, owner_key = NULL, updated_at = %s WHERE status = %s AND expires_at <= %s", self::STATUS_EXPIRED, $now, self::STATUS_ACTIVE, $now ) );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Ejecuta una mutación completa bajo una sola transacción.
	 *
	 * @param array<string, int|string> $identity          Identidad.
	 * @param int                       $expected_revision Revisión.
	 * @param callable                  $operation         Escritura específica.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function mutate( array $identity, int $expected_revision, callable $operation ): array|WP_Error {
		if ( 1 > $expected_revision || ! CatalogDatabase::begin() ) {
			return 1 > $expected_revision ? self::invalid() : CatalogDatabase::storage_error();
		}

		$cart = self::lock_by_owner( (string) $identity['key'] );

		if ( null === $cart || self::STATUS_ACTIVE !== $cart['status'] || $cart['expires_at'] <= current_time( 'mysql', true ) ) {
			if ( null !== $cart && self::STATUS_ACTIVE === $cart['status'] ) {
				self::mark_expired_row( (int) $cart['id'] );
				CatalogDatabase::commit();
			} else {
				CatalogDatabase::rollback();
			}

			return self::not_found();
		}

		if ( (int) $cart['revision'] !== $expected_revision ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::stale_error( (int) $cart['revision'] );
		}

		$result = $operation( $cart );

		if ( is_wp_error( $result ) ) {
			CatalogDatabase::rollback();
			return $result;
		}

		$recalculated = self::recalculate_locked( $cart );

		if ( is_wp_error( $recalculated ) ) {
			CatalogDatabase::rollback();
			return $recalculated;
		}

		if ( ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::storage_error();
		}

		return self::get( $identity );
	}

	/**
	 * Recalcula líneas y totales antes de incrementar la revisión.
	 *
	 * @param array<string, mixed> $cart Fila bloqueada.
	 * @return true|WP_Error
	 */
	private static function recalculate_locked( array $cart ): true|WP_Error {
		global $wpdb;

		$items_table = Schema::cart_items_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$items_table} WHERE cart_id = %d ORDER BY id ASC FOR UPDATE", $cart['id'] ), ARRAY_A );
		$subtotal = 0;

		foreach ( $items as $item ) {
			$quote = CartLinePricing::reprice( (string) $item['type'], self::decode_json( (string) $item['selection_json'] ) );

			if ( is_wp_error( $quote ) || $subtotal > PHP_INT_MAX - (int) ( is_wp_error( $quote ) ? 0 : $quote['line_total_minor'] ) ) {
				return is_wp_error( $quote ) ? $quote : self::invalid();
			}

			$subtotal += (int) $quote['line_total_minor'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$items_table,
				array(
					'quantity'         => $quote['quantity'],
					'selection_json'   => wp_json_encode( $quote['selection'] ),
					'snapshot_json'    => wp_json_encode( $quote['snapshot'] ),
					'unit_price_minor' => $quote['unit_price_minor'],
					'line_total_minor' => $quote['line_total_minor'],
					'merge_hash'       => $quote['merge_hash'],
					'updated_at'       => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $item['id'] ),
				array( '%d', '%s', '%s', '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				return CatalogDatabase::storage_error();
			}
		}

		$fresh          = self::row_by_id( (int) $cart['id'] );
		$discount_code  = null === $fresh['discount_code'] ? null : (string) $fresh['discount_code'];
		$zone_public_id = null === $fresh['delivery_zone_public_id'] ? null : (string) $fresh['delivery_zone_public_id'];
		$totals         = TotalsService::calculate( $subtotal, $discount_code, (int) $fresh['tip_rate_bps'], (string) $fresh['fulfillment'], $zone_public_id );

		if ( is_wp_error( $totals ) && null !== $discount_code && 'vicu_restaurante_unavailable' === $totals->get_error_code() ) {
			$discount_code = null;
			$totals        = TotalsService::calculate( $subtotal, null, (int) $fresh['tip_rate_bps'], (string) $fresh['fulfillment'], $zone_public_id );
		}

		if ( is_wp_error( $totals ) ) {
			return $totals;
		}

		$carts_table = Schema::carts_table_name();
		// La revisión en WHERE es el compare-and-swap definitivo.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$carts_table} SET revision = revision + 1, discount_code = NULLIF(%s, ''), subtotal_minor = %d, totals_json = %s, catalog_revision = %d, availability_revision = %d, pricing_revision = %d, expires_at = %s, updated_at = %s WHERE id = %d AND revision = %d",
				null === $discount_code ? '' : $discount_code,
				$subtotal,
				wp_json_encode( $totals ),
				CatalogRevision::current(),
				AvailabilityRevision::current(),
				PricingRevision::current(),
				$cart['expires_at'],
				current_time( 'mysql', true ),
				$cart['id'],
				$cart['revision']
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return 1 === $updated ? true : CatalogDatabase::stale_error( (int) self::row_by_id( (int) $cart['id'] )['revision'] );
	}

	/**
	 * Cambia un único campo controlado antes del recálculo.
	 *
	 * @param array<string, int|string> $identity          Identidad.
	 * @param int                       $expected_revision Revisión.
	 * @param string                    $field             Campo fijo.
	 * @param int|string|null           $value             Valor saneado.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function set_cart_field( array $identity, int $expected_revision, string $field, int|string|null $value ): array|WP_Error {
		if ( ! in_array( $field, array( 'discount_code', 'tip_rate_bps' ), true ) ) {
			return self::invalid();
		}

		return self::mutate(
			$identity,
			$expected_revision,
			static function ( array $cart ) use ( $field, $value ): true|WP_Error {
				global $wpdb;

				// El nombre de columna pertenece a una allowlist cerrada.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->update( Schema::carts_table_name(), array( $field => $value ), array( 'id' => $cart['id'] ), array( is_int( $value ) ? '%d' : '%s' ), array( '%d' ) );

				return false === $updated ? CatalogDatabase::storage_error() : true;
			}
		);
	}

	/**
	 * Busca un carrito por UUID y confirma ownership.
	 *
	 * @param string                    $public_id UUID.
	 * @param array<string, int|string> $identity  Identidad.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function find( string $public_id, array $identity ): array|WP_Error {
		$row = self::row_by_owner( (string) $identity['key'] );

		return null !== $row && $public_id === $row['public_id'] ? self::format_cart( $row ) : self::not_found();
	}

	/**
	 * Bloquea la fila activa de una identidad.
	 *
	 * @param string $owner_key Clave interna de propietario.
	 * @return array<string, mixed>|null
	 */
	private static function lock_by_owner( string $owner_key ): ?array {
		global $wpdb;

		$table = Schema::carts_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_key = %s FOR UPDATE", $owner_key ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lee la fila activa por propietario.
	 *
	 * @param string $owner_key Clave interna.
	 * @return array<string, mixed>|null
	 */
	private static function row_by_owner( string $owner_key ): ?array {
		global $wpdb;

		$table = Schema::carts_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_key = %s", $owner_key ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lee una fila por ID interno ya autorizado.
	 *
	 * @param int $id ID.
	 * @return array<string, mixed>
	 */
	private static function row_by_id( int $id ): array {
		global $wpdb;

		$table = Schema::carts_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Proyecta el carrito sin IDs internos ni secretos.
	 *
	 * @param array<string, mixed> $row Fila autorizada.
	 * @return array<string, mixed>
	 */
	private static function format_cart( array $row ): array {
		global $wpdb;

		$table = Schema::cart_items_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE cart_id = %d ORDER BY id ASC", $row['id'] ), ARRAY_A );
		$items = array_map(
			static function ( array $item ): array {
				return array(
					'line_id'          => (string) $item['public_id'],
					'type'             => (string) $item['type'],
					'quantity'         => (int) $item['quantity'],
					'selection'        => self::decode_json( (string) $item['selection_json'] ),
					'snapshot'         => self::decode_json( (string) $item['snapshot_json'] ),
					'unit_price_minor' => (int) $item['unit_price_minor'],
					'line_total_minor' => (int) $item['line_total_minor'],
				);
			},
			$rows
		);

		return array(
			'public_id'             => (string) $row['public_id'],
			'status'                => (string) $row['status'],
			'revision'              => (int) $row['revision'],
			'catalog_revision'      => (int) $row['catalog_revision'],
			'availability_revision' => (int) $row['availability_revision'],
			'pricing_revision'      => (int) $row['pricing_revision'],
			'items'                 => $items,
			'discount_code'         => null === $row['discount_code'] || '' === $row['discount_code'] ? null : (string) $row['discount_code'],
			'fulfillment'           => (string) $row['fulfillment'],
			'delivery_zone_id'      => null === $row['delivery_zone_public_id'] ? null : (string) $row['delivery_zone_public_id'],
			'tip_rate_bps'          => (int) $row['tip_rate_bps'],
			'totals'                => self::decode_json( (string) $row['totals_json'] ),
			'expires_at'            => mysql_to_rfc3339( (string) $row['expires_at'] ),
		);
	}

	/**
	 * Decodifica solo mapas JSON válidos.
	 *
	 * @param string $json JSON persistido.
	 * @return array<string, mixed>
	 */
	private static function decode_json( string $json ): array {
		$value = json_decode( $json, true );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Expira el carrito de una identidad.
	 *
	 * @param string $owner_key Propietario.
	 * @return void
	 */
	private static function expire_owner( string $owner_key ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::carts_table_name(),
			array(
				'status'     => self::STATUS_EXPIRED,
				'owner_key'  => null,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'owner_key' => $owner_key ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Expira una fila ya bloqueada.
	 *
	 * @param int $id ID interno.
	 * @return void
	 */
	private static function mark_expired_row( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::carts_table_name(),
			array(
				'status'     => self::STATUS_EXPIRED,
				'owner_key'  => null,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Calcula el nuevo vencimiento UTC.
	 *
	 * @return string
	 */
	private static function new_expiration(): string {
		return gmdate( 'Y-m-d H:i:s', time() + RestaurantSettings::cart_lifetime_hours() * HOUR_IN_SECONDS );
	}

	/**
	 * Error de recurso no revelable.
	 *
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'vicu_restaurante_not_found', __( 'No se encontró un carrito activo.', 'vicunav-restaurante' ), array( 'status' => 404 ) );
	}

	/**
	 * Error de datos inválidos.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_request', __( 'La mutación del carrito no es válida.', 'vicunav-restaurante' ), array( 'status' => 400 ) );
	}
}
