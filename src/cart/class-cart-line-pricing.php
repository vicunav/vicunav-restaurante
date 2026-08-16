<?php
/**
 * Validación y pricing de líneas de carrito.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Cart;

use Vicu\Restaurante\Catalog\IngredientService;
use Vicu\Restaurante\Catalog\MenuIngredientService;
use Vicu\Restaurante\Menu\CatalogRepository;
use Vicu\Restaurante\Menu\MenuItemPostType;
use Vicu\Restaurante\Menu\MenuMeta;
use Vicu\Restaurante\Pizza\PizzaPricingService;
use Vicu\Restaurante\Settings\RestaurantSettings;
use WP_Error;
use WP_Query;

/**
 * Impide que el cliente aporte importes o snapshots autoritativos.
 */
final class CartLinePricing {
	public const TYPES = array( 'menu', 'pizza' );

	/**
	 * Cotiza una selección externa o una selección persistida.
	 *
	 * @param array<string, mixed> $input Selección candidata.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function quote( array $input ): array|WP_Error {
		$type = sanitize_key( (string) ( $input['type'] ?? '' ) );

		if ( 'menu' === $type ) {
			return self::menu( $input );
		}

		if ( 'pizza' === $type ) {
			return self::pizza( $input );
		}

		return self::invalid();
	}

	/**
	 * Recalcula una selección persistida sin confiar en su snapshot.
	 *
	 * @param string               $type      Tipo persistido.
	 * @param array<string, mixed> $selection Selección normalizada.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function reprice( string $type, array $selection ): array|WP_Error {
		$selection['type'] = $type;

		return self::quote( $selection );
	}

	/**
	 * Valida una línea del menú y resuelve su precio vivo.
	 *
	 * @param array<string, mixed> $input Entrada.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function menu( array $input ): array|WP_Error {
		$public_id = MenuMeta::sanitize_public_id( $input['menu_item_id'] ?? '' );
		$quantity  = self::quantity( $input['quantity'] ?? null );
		$item      = '' === $public_id ? null : ( new CatalogRepository() )->find( $public_id );

		if ( null === $quantity || null === $item ) {
			return self::invalid();
		}

		if ( ! $item['available'] || RestaurantSettings::currency() !== $item['currency'] ) {
			return self::unavailable();
		}

		$post_id = self::menu_post_id( $public_id );

		if ( 0 === $post_id ) {
			return self::unavailable();
		}

		$removed = self::removed_ingredients( $input['removed_ingredient_ids'] ?? array(), $post_id );
		$options = self::options( $input['options'] ?? array() );
		$note    = self::note( $input['note'] ?? '' );

		if ( is_wp_error( $removed ) || is_wp_error( $options ) || null === $note || ! self::required_ingredients_available( $post_id ) ) {
			return is_wp_error( $removed ) ? $removed : ( is_wp_error( $options ) ? $options : self::unavailable() );
		}

		if ( $item['price_minor'] > intdiv( PHP_INT_MAX, $quantity ) ) {
			return self::unavailable();
		}

		$selection  = array(
			'menu_item_id'           => $public_id,
			'quantity'               => $quantity,
			'options'                => $options,
			'removed_ingredient_ids' => $removed,
			'note'                   => $note,
		);
		$merge_data = array(
			'menu_item_id'           => $public_id,
			'options'                => $options,
			'removed_ingredient_ids' => $removed,
			'note'                   => $note,
			'unit_price_minor'       => $item['price_minor'],
		);

		return array(
			'type'             => 'menu',
			'source_public_id' => $public_id,
			'quantity'         => $quantity,
			'selection'        => $selection,
			'snapshot'         => array(
				'name'                   => $item['name'],
				'allergens'              => $item['allergens'],
				'dietary_tags'           => $item['dietary_tags'],
				'options'                => $options,
				'removed_ingredient_ids' => $removed,
				'note'                   => $note,
			),
			'unit_price_minor' => $item['price_minor'],
			'line_total_minor' => $item['price_minor'] * $quantity,
			'merge_hash'       => hash( 'sha256', (string) wp_json_encode( $merge_data ) ),
		);
	}

	/**
	 * Valida una pizza mediante el servicio autoritativo existente.
	 *
	 * @param array<string, mixed> $input Entrada.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function pizza( array $input ): array|WP_Error {
		$configuration = $input['configuration'] ?? null;

		if ( ! is_array( $configuration ) ) {
			return self::invalid();
		}

		$quote = PizzaPricingService::quote( $configuration );

		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		return array(
			'type'             => 'pizza',
			'source_public_id' => null,
			'quantity'         => $quote['quantity'],
			'selection'        => array( 'configuration' => $quote['configuration'] ),
			'snapshot'         => array(
				'name'       => __( 'Pizza personalizada', 'vicunav-restaurante' ),
				'components' => $quote['components'],
			),
			'unit_price_minor' => $quote['unit_total_minor'],
			'line_total_minor' => $quote['total_minor'],
			'merge_hash'       => null,
		);
	}

	/**
	 * Resuelve el post interno exclusivamente dentro del dominio.
	 *
	 * @param string $public_id UUID público.
	 * @return int
	 */
	private static function menu_post_id( string $public_id ): int {
		// El UUID exacto es un lookup interno acotado y no se expone en la respuesta.
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$query = new WP_Query(
			array(
				'post_type'      => MenuItemPostType::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_key'       => MenuMeta::PUBLIC_ID,
				'meta_value'     => $public_id,
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		return isset( $query->posts[0] ) ? (int) $query->posts[0] : 0;
	}

	/**
	 * Acepta únicamente ingredientes declarados como removibles.
	 *
	 * @param mixed $input   Lista candidata.
	 * @param int   $post_id Item interno.
	 * @return string[]|WP_Error
	 */
	private static function removed_ingredients( mixed $input, int $post_id ): array|WP_Error {
		if ( ! is_array( $input ) || count( $input ) > 20 ) {
			return self::invalid();
		}

		$allowed = array();

		foreach ( MenuIngredientService::for_menu_item( $post_id ) as $relation ) {
			if ( 'removable' === $relation['role'] ) {
				$allowed[] = $relation['ingredient_public_id'];
			}
		}

		$removed = array_values( array_unique( array_map( 'strval', $input ) ) );
		sort( $removed, SORT_STRING );

		foreach ( $removed as $public_id ) {
			if ( ! wp_is_uuid( $public_id, 4 ) || ! in_array( $public_id, $allowed, true ) ) {
				return self::invalid();
			}
		}

		return $removed;
	}

	/**
	 * Rechaza un plato cuando un ingrediente requerido no tiene alternativa viva.
	 *
	 * @param int $post_id Item interno.
	 * @return bool
	 */
	private static function required_ingredients_available( int $post_id ): bool {
		foreach ( MenuIngredientService::for_menu_item( $post_id ) as $relation ) {
			if ( 'required' !== $relation['role'] ) {
				continue;
			}

			$ingredient   = IngredientService::find( $relation['ingredient_public_id'] );
			$substitution = '' === $relation['substitution_public_id'] ? null : IngredientService::find( $relation['substitution_public_id'] );

			if ( null === $ingredient || ( ! $ingredient['available'] && ( null === $substitution || ! $substitution['available'] ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normaliza opciones acotadas que no alteran precio.
	 *
	 * @param mixed $input Mapa candidato.
	 * @return array<string, string>|WP_Error
	 */
	private static function options( mixed $input ): array|WP_Error {
		if ( ! is_array( $input ) || count( $input ) > 20 ) {
			return self::invalid();
		}

		$options = array();

		foreach ( $input as $key => $value ) {
			$key   = sanitize_key( (string) $key );
			$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

			if ( '' === $key || '' === $value || 64 < strlen( $key ) || 100 < strlen( $value ) ) {
				return self::invalid();
			}

			$options[ $key ] = $value;
		}

		ksort( $options, SORT_STRING );

		return $options;
	}

	/**
	 * Normaliza una nota sin permitir markup ni exceso de tamaño.
	 *
	 * @param mixed $input Nota candidata.
	 * @return string|null
	 */
	private static function note( mixed $input ): ?string {
		if ( ! is_scalar( $input ) ) {
			return null;
		}

		$note = preg_replace( '/\s+/u', ' ', trim( sanitize_textarea_field( (string) $input ) ) );
		$note = is_string( $note ) ? $note : '';

		return 500 >= strlen( $note ) ? $note : null;
	}

	/**
	 * Valida cantidad entera acotada.
	 *
	 * @param mixed $value Cantidad candidata.
	 * @return int|null
	 */
	private static function quantity( mixed $value ): ?int {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) || trim( (string) $value ) !== (string) (int) $value ) {
			return null;
		}

		$value = (int) $value;

		return 1 <= $value && 99 >= $value ? $value : null;
	}

	/**
	 * Error de schema o personalización inválida.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'vicu_restaurante_invalid_request', __( 'La línea de carrito no es válida.', 'vicunav-restaurante' ), array( 'status' => 400 ) );
	}

	/**
	 * Error de catálogo no cotizable.
	 *
	 * @return WP_Error
	 */
	private static function unavailable(): WP_Error {
		return new WP_Error( 'vicu_restaurante_unavailable', __( 'El elemento ya no está disponible.', 'vicunav-restaurante' ), array( 'status' => 409 ) );
	}
}
