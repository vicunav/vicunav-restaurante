<?php
/**
 * Servicio autoritativo de descuentos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Commerce;

use DateTimeImmutable;
use Vicu\Restaurante\Catalog\CatalogDatabase;
use Vicu\Restaurante\Schema;
use WP_Error;

/**
 * Valida códigos y reserva usos limitados bajo bloqueo de fila.
 */
final class DiscountService {
	public const TYPES = array( 'fixed', 'percent' );

	/**
	 * Crea un código.
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

		if ( null !== self::find_by_code( $data['code'] ) ) {
			return self::invalid();
		}

		if ( ! CatalogDatabase::begin() ) {
			return CatalogDatabase::storage_error();
		}

		$public_id = wp_generate_uuid4();
		$now       = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			Schema::discount_codes_table_name(),
			array_merge(
				$data,
				array(
					'public_id'  => $public_id,
					'uses_count' => 0,
					'revision'   => 1,
					'created_at' => $now,
					'updated_at' => $now,
				)
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted || ! PricingRevision::bump_in_transaction() || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::storage_error();
		}

		PricingRevision::clear_cache();

		return self::find( $public_id ) ?? CatalogDatabase::storage_error();
	}

	/**
	 * Actualiza reglas sin alterar usos consumidos.
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

		$duplicate = self::find_by_code( $data['code'] );

		if ( null !== $duplicate && $duplicate['public_id'] !== $public_id ) {
			return self::invalid();
		}

		if ( $current['revision'] !== $expected_revision ) {
			return CatalogDatabase::stale_error( $current['revision'] );
		}

		if ( ! CatalogDatabase::begin() ) {
			return CatalogDatabase::storage_error();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			Schema::discount_codes_table_name(),
			array_merge(
				$data,
				array(
					'revision'   => $expected_revision + 1,
					'updated_at' => current_time( 'mysql', true ),
				)
			),
			array(
				'public_id' => $public_id,
				'revision'  => $expected_revision,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s' ),
			array( '%s', '%d' )
		);

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
	 * Resuelve un descuento vigente sin consumirlo.
	 *
	 * @param string $code           Código aportado.
	 * @param int    $subtotal_minor Subtotal autoritativo.
	 * @param string $now_utc        Fecha UTC para evaluación.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function resolve( string $code, int $subtotal_minor, string $now_utc = '' ): array|WP_Error {
		$discount = self::find_by_code( $code );

		if ( null === $discount || ! self::is_usable( $discount, $subtotal_minor, $now_utc ) ) {
			return self::unavailable();
		}

		$discount['amount_minor'] = self::amount( $discount, $subtotal_minor );

		return $discount;
	}

	/**
	 * Consume un uso con bloqueo de fila para impedir sobrepasar el límite.
	 *
	 * @param string $code           Código.
	 * @param int    $subtotal_minor Subtotal autoritativo.
	 * @param string $now_utc        Fecha UTC.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function consume( string $code, int $subtotal_minor, string $now_utc = '' ): array|WP_Error {
		global $wpdb;

		$normalized = self::normalize_code( $code );

		if ( '' === $normalized || ! CatalogDatabase::begin() ) {
			return '' === $normalized ? self::invalid() : CatalogDatabase::storage_error();
		}

		$table = Schema::discount_codes_table_name();
		// El bloqueo serializa la comprobación y el incremento del límite.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row         = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s FOR UPDATE", $normalized ), ARRAY_A );
		$discount    = is_array( $row ) ? self::format( $row ) : null;
		$internal_id = is_array( $row ) ? (int) $row['id'] : 0;

		if ( null === $discount || ! self::is_usable( $discount, $subtotal_minor, $now_utc ) ) {
			CatalogDatabase::rollback();
			return self::unavailable();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET uses_count = uses_count + 1, revision = revision + 1, updated_at = %s WHERE id = %d AND revision = %d",
				current_time( 'mysql', true ),
				$internal_id,
				$discount['revision']
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( 1 !== $updated || ! PricingRevision::bump_in_transaction() || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::storage_error();
		}

		PricingRevision::clear_cache();
		$result = self::find( $discount['public_id'] );

		if ( null === $result ) {
			return CatalogDatabase::storage_error();
		}

		$result['amount_minor'] = self::amount( $discount, $subtotal_minor );

		return $result;
	}

	/**
	 * Busca por UUID.
	 *
	 * @param string $public_id UUID.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $public_id ): ?array {
		global $wpdb;

		$table = Schema::discount_codes_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s", $public_id ), ARRAY_A );

		return is_array( $row ) ? self::format( $row ) : null;
	}

	/**
	 * Lista códigos para administración.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		$table = Schema::discount_codes_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY active DESC, code ASC, id ASC", ARRAY_A );

		return array_map( array( self::class, 'format' ), $rows );
	}

	/**
	 * Busca por código normalizado.
	 *
	 * @param string $code Código.
	 * @return array<string, mixed>|null
	 */
	private static function find_by_code( string $code ): ?array {
		global $wpdb;

		$normalized = self::normalize_code( $code );

		if ( '' === $normalized ) {
			return null;
		}

		$table = Schema::discount_codes_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $normalized ), ARRAY_A );

		return is_array( $row ) ? self::format( $row ) : null;
	}

	/**
	 * Valida el formulario completo.
	 *
	 * @param array<string, mixed> $input Datos.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function validate( array $input ): array|WP_Error {
		$code        = self::normalize_code( is_scalar( $input['code'] ?? null ) ? (string) $input['code'] : '' );
		$type        = sanitize_key( is_scalar( $input['type'] ?? null ) ? (string) $input['type'] : '' );
		$value       = self::integer( $input['value'] ?? null, 1, 'percent' === $type ? 10000 : 100000000 );
		$minimum     = self::integer( $input['minimum_subtotal_minor'] ?? 0, 0, 100000000 );
		$max_uses    = self::nullable_positive_integer( $input['max_uses'] ?? null );
		$valid_from  = self::date( $input['valid_from'] ?? null );
		$valid_until = self::date( $input['valid_until'] ?? null );

		if (
			'' === $code ||
			! in_array( $type, self::TYPES, true ) ||
			null === $value ||
			null === $minimum ||
			is_wp_error( $max_uses ) ||
			is_wp_error( $valid_from ) ||
			is_wp_error( $valid_until ) ||
			( null !== $valid_from && null !== $valid_until && $valid_until <= $valid_from )
		) {
			return self::invalid();
		}

		return array(
			'code'                   => $code,
			'type'                   => $type,
			'value'                  => $value,
			'active'                 => rest_sanitize_boolean( $input['active'] ?? false ) ? 1 : 0,
			'valid_from'             => $valid_from,
			'valid_until'            => $valid_until,
			'minimum_subtotal_minor' => $minimum,
			'max_uses'               => $max_uses,
		);
	}

	/**
	 * Determina vigencia y límite sobre una fila ya leída.
	 *
	 * @param array<string, mixed> $discount      Descuento.
	 * @param int                  $subtotal_minor Subtotal.
	 * @param string               $now_utc        Fecha UTC.
	 * @return bool
	 */
	private static function is_usable( array $discount, int $subtotal_minor, string $now_utc ): bool {
		$now = '' === $now_utc ? current_time( 'mysql', true ) : $now_utc;

		return $discount['active'] &&
			0 <= $subtotal_minor &&
			$subtotal_minor >= $discount['minimum_subtotal_minor'] &&
			( null === $discount['valid_from'] || $now >= $discount['valid_from'] ) &&
			( null === $discount['valid_until'] || $now < $discount['valid_until'] ) &&
			( null === $discount['max_uses'] || $discount['uses_count'] < $discount['max_uses'] );
	}

	/**
	 * Calcula y limita el descuento al subtotal.
	 *
	 * @param array<string, mixed> $discount      Regla vigente.
	 * @param int                  $subtotal_minor Subtotal.
	 * @return int
	 */
	private static function amount( array $discount, int $subtotal_minor ): int {
		$amount = 'fixed' === $discount['type']
			? $discount['value']
			: intdiv( $subtotal_minor, 10000 ) * $discount['value'] +
				intdiv( ( $subtotal_minor % 10000 ) * $discount['value'] + 5000, 10000 );

		return min( $subtotal_minor, $amount );
	}

	/**
	 * Proyecta una fila sin ID interno.
	 *
	 * @param array<string, mixed> $row Fila SQL.
	 * @return array<string, mixed>
	 */
	private static function format( array $row ): array {
		return array(
			'public_id'              => (string) $row['public_id'],
			'code'                   => (string) $row['code'],
			'type'                   => (string) $row['type'],
			'value'                  => (int) $row['value'],
			'active'                 => 1 === (int) $row['active'],
			'valid_from'             => null === $row['valid_from'] ? null : (string) $row['valid_from'],
			'valid_until'            => null === $row['valid_until'] ? null : (string) $row['valid_until'],
			'minimum_subtotal_minor' => (int) $row['minimum_subtotal_minor'],
			'max_uses'               => null === $row['max_uses'] ? null : (int) $row['max_uses'],
			'uses_count'             => (int) $row['uses_count'],
			'revision'               => (int) $row['revision'],
		);
	}

	/**
	 * Normaliza código ASCII visible.
	 *
	 * @param string $code Código.
	 * @return string
	 */
	private static function normalize_code( string $code ): string {
		$normalized = strtoupper( trim( sanitize_text_field( wp_unslash( $code ) ) ) );

		return 1 === preg_match( '/^[A-Z0-9_-]{3,64}$/', $normalized ) ? $normalized : '';
	}

	/**
	 * Valida fecha UTC canónica o null.
	 *
	 * @param mixed $value Valor.
	 * @return string|null|WP_Error
	 */
	private static function date( mixed $value ): string|null|WP_Error {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( ! is_scalar( $value ) ) {
			return self::invalid();
		}

		$date   = (string) $value;
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date );

		return false !== $parsed && $parsed->format( 'Y-m-d H:i:s' ) === $date ? $date : self::invalid();
	}

	/**
	 * Valida entero acotado.
	 *
	 * @param mixed $value Valor.
	 * @param int   $min   Mínimo.
	 * @param int   $max   Máximo.
	 * @return int|null
	 */
	private static function integer( mixed $value, int $min, int $max ): ?int {
		if ( ! is_scalar( $value ) || is_float( $value ) || ! is_numeric( $value ) || trim( (string) $value ) !== (string) (int) $value ) {
			return null;
		}

		$number = (int) $value;

		return $min <= $number && $max >= $number ? $number : null;
	}

	/**
	 * Valida límite de usos opcional.
	 *
	 * @param mixed $value Valor.
	 * @return int|null|WP_Error
	 */
	private static function nullable_positive_integer( mixed $value ): int|null|WP_Error {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$number = self::integer( $value, 1, 100000000 );

		return null === $number ? self::invalid() : $number;
	}

	/**
	 * Error de entrada.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_request', __( 'Los datos del descuento no son válidos.', 'vicunav-restaurante' ), array( 'status' => 400 ) );
	}

	/**
	 * Error indistinguible de código no aplicable.
	 *
	 * @return WP_Error
	 */
	private static function unavailable(): WP_Error {
		return new WP_Error( 'vicu_restaurante_unavailable', __( 'El código no está disponible para este pedido.', 'vicunav-restaurante' ), array( 'status' => 409 ) );
	}
}
