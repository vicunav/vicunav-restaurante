<?php
/**
 * Persistencia propietaria y enlaces de pizzas guardadas.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\SavedPizza;

use Throwable;
use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Catalog\CatalogDatabase;
use Vicu\Restaurante\Pizza\PizzaConfigurationValidator;
use Vicu\Restaurante\Pizza\PizzaPricingService;
use Vicu\Restaurante\Schema;
use WP_Error;

/**
 * La configuración no guarda importes y cada mutación exige ownership y revisión.
 */
final class SavedPizzaService {
	private const MAX_PER_USER = 100;

	/**
	 * Lista únicamente las pizzas de una cuenta.
	 *
	 * @param int $user_id Usuario propietario.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_for_user( int $user_id ): array {
		global $wpdb;

		if ( 1 > $user_id ) {
			return array();
		}

		$table = Schema::saved_pizzas_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC, id DESC", $user_id ), ARRAY_A );

		return array_values( array_filter( array_map( array( self::class, 'response' ), $rows ) ) );
	}

	/**
	 * Crea una pizza solo después de una cotización autoritativa.
	 *
	 * @param int                  $user_id Usuario propietario.
	 * @param string               $name    Nombre privado.
	 * @param array<string, mixed> $input   Configuración candidata.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( int $user_id, string $name, array $input ): array|WP_Error {
		global $wpdb;

		$name  = self::name( $name );
		$quote = PizzaPricingService::quote( $input );

		if ( 1 > $user_id || '' === $name || is_wp_error( $quote ) ) {
			return is_wp_error( $quote ) ? $quote : self::invalid();
		}

		$table = Schema::saved_pizzas_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );

		if ( self::MAX_PER_USER <= $count ) {
			return new WP_Error( 'vicu_restaurante_unavailable', __( 'La cuenta alcanzó el límite de pizzas guardadas.', 'vicunav-restaurante' ), array( 'status' => 409 ) );
		}

		$json = wp_json_encode( $quote['configuration'] );

		if ( false === $json ) {
			return self::storage_error();
		}

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'public_id'             => wp_generate_uuid4(),
				'user_id'               => $user_id,
				'name'                  => $name,
				'configuration_version' => PizzaConfigurationValidator::VERSION,
				'configuration_json'    => $json,
				'revision'              => 1,
				'share_token_hash'      => null,
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return self::storage_error();
		}

		$row = self::owner_row_by_id( (int) $wpdb->insert_id, $user_id );

		return null === $row ? self::storage_error() : self::response( $row );
	}

	/**
	 * Renombra o reemplaza una configuración mediante compare-and-swap.
	 *
	 * @param int                       $user_id          Usuario propietario.
	 * @param string                    $public_id        UUID público.
	 * @param int                       $expected_revision Revisión esperada.
	 * @param string|null               $name             Nombre opcional.
	 * @param array<string, mixed>|null $configuration    Configuración opcional.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( int $user_id, string $public_id, int $expected_revision, ?string $name, ?array $configuration ): array|WP_Error {
		global $wpdb;

		if ( 1 > $user_id || 1 > $expected_revision || ( null === $name && null === $configuration ) ) {
			return self::invalid();
		}

		$normalized_name = null === $name ? null : self::name( $name );

		if ( null !== $name && '' === $normalized_name ) {
			return self::invalid();
		}

		$quote = null === $configuration ? null : PizzaPricingService::quote( $configuration );

		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		if ( ! CatalogDatabase::begin() ) {
			return self::storage_error();
		}

		$row = self::lock_owner_row( $public_id, $user_id );

		if ( null === $row ) {
			CatalogDatabase::rollback();
			return self::not_found();
		}

		if ( (int) $row['revision'] !== $expected_revision ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::stale_error( (int) $row['revision'] );
		}

		$data = array(
			'name'                  => null === $normalized_name ? $row['name'] : $normalized_name,
			'configuration_version' => null === $quote ? (int) $row['configuration_version'] : PizzaConfigurationValidator::VERSION,
			'configuration_json'    => null === $quote ? $row['configuration_json'] : wp_json_encode( $quote['configuration'] ),
			'revision'              => $expected_revision + 1,
			'updated_at'            => current_time( 'mysql', true ),
		);

		if ( false === $data['configuration_json'] ) {
			CatalogDatabase::rollback();
			return self::storage_error();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			Schema::saved_pizzas_table_name(),
			$data,
			array(
				'id'       => (int) $row['id'],
				'revision' => $expected_revision,
			),
			array( '%s', '%d', '%s', '%d', '%s' ),
			array( '%d', '%d' )
		);

		if ( 1 !== $updated || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return self::storage_error();
		}

		$fresh = self::owner_row_by_id( (int) $row['id'], $user_id );

		return null === $fresh ? self::storage_error() : self::response( $fresh );
	}

	/**
	 * Elimina una pizza propietaria con revisión esperada.
	 *
	 * @param int    $user_id          Usuario propietario.
	 * @param string $public_id        UUID público.
	 * @param int    $expected_revision Revisión esperada.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete( int $user_id, string $public_id, int $expected_revision ): array|WP_Error {
		global $wpdb;

		if ( ! CatalogDatabase::begin() ) {
			return self::storage_error();
		}

		$row = self::lock_owner_row( $public_id, $user_id );

		if ( null === $row ) {
			CatalogDatabase::rollback();
			return self::not_found();
		}

		if ( (int) $row['revision'] !== $expected_revision ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::stale_error( (int) $row['revision'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			Schema::saved_pizzas_table_name(),
			array(
				'id'       => (int) $row['id'],
				'revision' => $expected_revision,
			),
			array( '%d', '%d' )
		);

		if ( 1 !== $deleted || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return self::storage_error();
		}

		return array(
			'deleted'   => true,
			'public_id' => $public_id,
		);
	}

	/**
	 * Rota el token compartible y nunca persiste su valor en texto plano.
	 *
	 * @param int    $user_id          Usuario propietario.
	 * @param string $public_id        UUID público.
	 * @param int    $expected_revision Revisión esperada.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function rotate_share( int $user_id, string $public_id, int $expected_revision ): array|WP_Error {
		global $wpdb;

		try {
			// Codifica bytes aleatorios como token URL-safe; no oculta código ni datos.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		} catch ( Throwable $error ) {
			unset( $error );
			return self::storage_error();
		}

		if ( ! CatalogDatabase::begin() ) {
			return self::storage_error();
		}

		$row = self::lock_owner_row( $public_id, $user_id );

		if ( null === $row ) {
			CatalogDatabase::rollback();
			return self::not_found();
		}

		if ( (int) $row['revision'] !== $expected_revision ) {
			CatalogDatabase::rollback();
			return CatalogDatabase::stale_error( (int) $row['revision'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			Schema::saved_pizzas_table_name(),
			array(
				'share_token_hash' => self::token_hash( $token ),
				'revision'         => $expected_revision + 1,
				'updated_at'       => current_time( 'mysql', true ),
			),
			array(
				'id'       => (int) $row['id'],
				'revision' => $expected_revision,
			),
			array( '%s', '%d', '%s' ),
			array( '%d', '%d' )
		);

		if ( 1 !== $updated || ! CatalogDatabase::commit() ) {
			CatalogDatabase::rollback();
			return self::storage_error();
		}

		$fresh = self::owner_row_by_id( (int) $row['id'], $user_id );

		if ( null === $fresh ) {
			return self::storage_error();
		}

		$response                = self::response( $fresh );
		$response['share_token'] = $token;
		$response['share_path']  = '/wp-json/vicu/v1/restaurante/saved-pizzas/shared/' . $token;

		return $response;
	}

	/**
	 * Resuelve un token público y vuelve a cotizar contra catálogo vigente.
	 *
	 * @param string $token Token compartible.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function shared( string $token ): array|WP_Error {
		global $wpdb;

		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) ) {
			return self::not_found();
		}

		$table = Schema::saved_pizzas_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT configuration_version, configuration_json FROM {$table} WHERE share_token_hash = %s", self::token_hash( $token ) ), ARRAY_A );

		if ( ! is_array( $row ) || PizzaConfigurationValidator::VERSION !== (int) $row['configuration_version'] ) {
			return self::not_found();
		}

		$configuration = json_decode( (string) $row['configuration_json'], true );

		if ( ! is_array( $configuration ) ) {
			return self::storage_error();
		}

		$configuration['catalog_revision'] = AvailabilityRevision::current();
		$quote                             = PizzaPricingService::quote( $configuration );

		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		return array(
			'share_version'       => 1,
			'configuration'       => $quote['configuration'],
			'authoritative_quote' => $quote,
		);
	}

	/**
	 * Busca una fila por ID interno y propietario.
	 *
	 * @param int $id      ID interno.
	 * @param int $user_id Usuario propietario.
	 * @return array<string, mixed>|null
	 */
	private static function owner_row_by_id( int $id, int $user_id ): ?array {
		global $wpdb;
		$table = Schema::saved_pizzas_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $id, $user_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Bloquea una fila solo dentro del scope propietario.
	 *
	 * @param string $public_id UUID público.
	 * @param int    $user_id   Usuario propietario.
	 * @return array<string, mixed>|null
	 */
	private static function lock_owner_row( string $public_id, int $user_id ): ?array {
		global $wpdb;
		$table = Schema::saved_pizzas_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s AND user_id = %d FOR UPDATE", $public_id, $user_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Proyecta una fila privada sin IDs internos ni hash compartido.
	 *
	 * @param array<string, mixed> $row Fila.
	 * @return array<string, mixed>|null
	 */
	private static function response( array $row ): ?array {
		$configuration = json_decode( (string) $row['configuration_json'], true );

		if ( ! is_array( $configuration ) || PizzaConfigurationValidator::VERSION !== (int) $row['configuration_version'] ) {
			return null;
		}

		return array(
			'public_id'     => (string) $row['public_id'],
			'name'          => (string) $row['name'],
			'configuration' => $configuration,
			'revision'      => (int) $row['revision'],
			'share_enabled' => null !== $row['share_token_hash'],
			'created_at'    => mysql_to_rfc3339( (string) $row['created_at'] ),
			'updated_at'    => mysql_to_rfc3339( (string) $row['updated_at'] ),
		);
	}

	/**
	 * Normaliza un nombre privado acotado.
	 *
	 * @param string $name Nombre.
	 * @return string
	 */
	private static function name( string $name ): string {
		$name = trim( sanitize_text_field( $name ) );
		return 1 <= strlen( $name ) && 100 >= strlen( $name ) ? $name : '';
	}

	/**
	 * Hashea un token con separación de propósito.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function token_hash( string $token ): string {
		return hash_hmac( 'sha256', 'saved-pizza-share|' . $token, wp_salt( 'auth' ) );
	}

	/**
	 * Construye un error de entrada estable.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_request', __( 'Los datos de la pizza guardada no son válidos.', 'vicunav-restaurante' ), array( 'status' => 400 ) );
	}

	/**
	 * Construye un error opaco de ausencia u ownership.
	 *
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'vicu_restaurante_not_found', __( 'No se encontró la pizza guardada.', 'vicunav-restaurante' ), array( 'status' => 404 ) );
	}

	/**
	 * Construye un error seguro de persistencia.
	 *
	 * @return WP_Error
	 */
	private static function storage_error(): WP_Error {
		return new WP_Error( 'vicu_restaurante_storage_error', __( 'No se pudo guardar la pizza.', 'vicunav-restaurante' ), array( 'status' => 500 ) );
	}
}
