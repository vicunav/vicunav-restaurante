<?php
/**
 * Lecturas públicas del catálogo.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Menu;

use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Proyecta solo items publicados y operativamente completos.
 */
final class CatalogRepository {
	private const CACHE_GROUP = 'vicu_restaurante_menu';
	private const CACHE_TTL   = 300;

	/**
	 * Lista categorías visibles e items válidos.
	 *
	 * @param string $category_slug Filtro opcional validado.
	 * @return array{revision: int, categories: array<int, array<string, mixed>>, items: array<int, array<string, mixed>>}
	 */
	public function all( string $category_slug = '' ): array {
		$revision  = CatalogRevision::current();
		$cache_key = 'collection:' . $revision . ':' . ( '' === $category_slug ? 'all' : $category_slug );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$categories = $this->visible_categories( $category_slug );
		$items      = $this->query_items( $category_slug );
		$result     = array(
			'revision'   => $revision,
			'categories' => array_values( array_map( array( $this, 'format_category' ), $categories ) ),
			'items'      => array_values( array_filter( array_map( array( $this, 'format_item' ), $items ) ) ),
		);

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Busca un item exclusivamente por su ID público.
	 *
	 * @param string $public_id UUID público.
	 * @return array<string, mixed>|null
	 */
	public function find( string $public_id ): ?array {
		$revision  = CatalogRevision::current();
		$cache_key = 'item:' . $revision . ':' . $public_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// El catálogo es acotado y la búsqueda exacta por UUID se cachea por revisión.
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$query = new WP_Query(
			array(
				'post_type'              => MenuItemPostType::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
				'meta_key'               => MenuMeta::PUBLIC_ID,
				'meta_value'             => $public_id,
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		$item = isset( $query->posts[0] ) && $query->posts[0] instanceof WP_Post
			? $this->format_item( $query->posts[0] )
			: null;

		if ( null !== $item ) {
			wp_cache_set( $cache_key, $item, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return $item;
	}

	/**
	 * Comprueba si un filtro identifica una categoría pública.
	 *
	 * @param string $slug Slug normalizado.
	 * @return bool
	 */
	public function has_visible_category( string $slug ): bool {
		return array() !== $this->visible_categories( $slug );
	}

	/**
	 * Obtiene categorías visibles en orden operativo.
	 *
	 * @param string $slug Filtro opcional.
	 * @return WP_Term[]
	 */
	private function visible_categories( string $slug = '' ): array {
		// Las categorías del menú son una taxonomía editorial pequeña con dos campos operativos.
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$args = array(
			'taxonomy'   => MenuCategory::TAXONOMY,
			'hide_empty' => false,
			'meta_key'   => MenuCategory::META_ORDER,
			'orderby'    => 'meta_value_num',
			'order'      => 'ASC',
			'meta_query' => array(
				array(
					'key'     => MenuCategory::META_VISIBLE,
					'value'   => '1',
					'compare' => '=',
				),
			),
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		if ( '' !== $slug ) {
			$args['slug'] = $slug;
		}

		$terms = get_terms( $args );

		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Carga items en una consulta paginable futura y con caches agrupados.
	 *
	 * @param string $category_slug Filtro opcional.
	 * @return WP_Post[]
	 */
	private function query_items( string $category_slug ): array {
		$args = array(
			'post_type'              => MenuItemPostType::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'orderby'                => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
		);

		if ( '' !== $category_slug ) {
			// El filtro contractual usa una sola taxonomía y un único slug validado.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$args['tax_query'] = array(
				array(
					'taxonomy' => MenuCategory::TAXONOMY,
					'field'    => 'slug',
					'terms'    => array( $category_slug ),
				),
			);
		}

		$posts = ( new WP_Query( $args ) )->posts;
		$this->prime_image_caches( $posts );

		return $posts;
	}

	/**
	 * Proyecta una categoría sin IDs internos.
	 *
	 * @param WP_Term $term Categoría visible.
	 * @return array<string, mixed>
	 */
	private function format_category( WP_Term $term ): array {
		return array(
			'slug'        => $term->slug,
			'name'        => $term->name,
			'description' => $term->description,
			'order'       => absint( get_term_meta( $term->term_id, MenuCategory::META_ORDER, true ) ),
		);
	}

	/**
	 * Proyecta un item o falla cerrado si rompe el contrato operativo.
	 *
	 * @param WP_Post $post Item publicado.
	 * @return array<string, mixed>|null
	 */
	private function format_item( WP_Post $post ): ?array {
		$required_meta = array(
			MenuMeta::PUBLIC_ID,
			MenuMeta::PRICE_MINOR,
			MenuMeta::CURRENCY,
			MenuMeta::AVAILABLE,
		);

		foreach ( $required_meta as $meta_key ) {
			if ( ! metadata_exists( 'post', $post->ID, $meta_key ) ) {
				return null;
			}
		}

		$public_id = MenuMeta::sanitize_public_id( get_post_meta( $post->ID, MenuMeta::PUBLIC_ID, true ) );
		$currency  = MenuMeta::sanitize_currency( get_post_meta( $post->ID, MenuMeta::CURRENCY, true ) );
		$title     = trim( wp_strip_all_tags( get_the_title( $post ) ) );
		$terms     = get_the_terms( $post, MenuCategory::TAXONOMY );

		if ( '' === $public_id || '' === $currency || '' === $title || ! is_array( $terms ) || 1 !== count( $terms ) ) {
			return null;
		}

		$category = reset( $terms );

		if ( ! $category instanceof WP_Term || ! rest_sanitize_boolean( get_term_meta( $category->term_id, MenuCategory::META_VISIBLE, true ) ) ) {
			return null;
		}

		return array(
			'public_id'     => $public_id,
			'name'          => $title,
			'description'   => wp_strip_all_tags( $post->post_excerpt ),
			'story'         => wp_kses_post( apply_filters( 'the_content', $post->post_content ) ),
			'price_minor'   => MenuMeta::sanitize_non_negative_int( get_post_meta( $post->ID, MenuMeta::PRICE_MINOR, true ) ),
			'currency'      => $currency,
			'available'     => rest_sanitize_boolean( get_post_meta( $post->ID, MenuMeta::AVAILABLE, true ) ),
			'calories_kcal' => MenuMeta::sanitize_non_negative_int( get_post_meta( $post->ID, MenuMeta::CALORIES_KCAL, true ) ),
			'allergens'     => MenuMeta::sanitize_allergens( get_post_meta( $post->ID, MenuMeta::ALLERGENS, true ) ),
			'dietary_tags'  => MenuMeta::sanitize_dietary_tags( get_post_meta( $post->ID, MenuMeta::DIETARY_TAGS, true ) ),
			'category'      => $category->slug,
			'order'         => (int) $post->menu_order,
			'image'         => $this->format_image( get_post_thumbnail_id( $post ) ),
		);
	}

	/**
	 * Devuelve media responsive sin publicar el ID interno del attachment.
	 *
	 * @param int|false $attachment_id ID de media o false.
	 * @return array<string, int|string>|null
	 */
	private function format_image( int|false $attachment_id ): ?array {
		if ( false === $attachment_id || 1 > $attachment_id ) {
			return null;
		}

		$image = wp_get_attachment_image_src( $attachment_id, 'large' );

		if ( false === $image ) {
			return null;
		}

		return array(
			'url'    => $image[0],
			'alt'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'width'  => (int) $image[1],
			'height' => (int) $image[2],
		);
	}

	/**
	 * Precarga posts y metadatos de imágenes en dos consultas agrupadas.
	 *
	 * @param WP_Post[] $posts Items del resultado.
	 * @return void
	 */
	private function prime_image_caches( array $posts ): void {
		$attachment_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( WP_Post $post ): int {
							return (int) get_post_thumbnail_id( $post );
						},
						$posts
					)
				)
			)
		);

		if ( array() === $attachment_ids ) {
			return;
		}

		get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post__in'               => $attachment_ids,
				'posts_per_page'         => count( $attachment_ids ),
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
	}
}
