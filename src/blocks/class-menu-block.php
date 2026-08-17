<?php
/**
 * Render de servidor del bloque de menú.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Blocks;

use Vicu\Restaurante\Menu\CatalogRepository;

/**
 * Proyecta el catálogo público sin duplicar autoridad de negocio en el bloque.
 */
final class MenuBlock {
	/**
	 * Renderiza el catálogo completo como fallback progresivo.
	 *
	 * @return string
	 */
	public static function render(): string {
		$catalog = ( new CatalogRepository() )->all();
		$items   = $catalog['items'];
		$root_id = wp_unique_id( 'vicu-restaurante-menu-' );

		$attributes = get_block_wrapper_attributes(
			array(
				'id'                     => $root_id,
				'data-vicu-menu-root'    => '',
				'data-rest-url'          => esc_url_raw( rest_url( 'vicu/v1/restaurante/menu' ) ),
				'data-loading-message'   => __( 'Actualizando el menú.', 'vicunav-restaurante' ),
				'data-error-message'     => __( 'No pudimos actualizar el menú. Mostramos la última versión disponible.', 'vicunav-restaurante' ),
				'data-empty-message'     => __( 'No encontramos platos con estos filtros.', 'vicunav-restaurante' ),
				'data-available-label'   => __( 'Disponible', 'vicunav-restaurante' ),
				'data-unavailable-label' => __( 'Agotado', 'vicunav-restaurante' ),
				'data-allergens-label'   => __( 'Alérgenos:', 'vicunav-restaurante' ),
				'data-spicy-label'       => __( 'Picante', 'vicunav-restaurante' ),
				'data-vegan-label'       => __( 'Vegano', 'vicunav-restaurante' ),
				'data-vegetarian-label'  => __( 'Vegetariano', 'vicunav-restaurante' ),
				'data-result-singular'   => __( 'resultado', 'vicunav-restaurante' ),
				'data-result-plural'     => __( 'resultados', 'vicunav-restaurante' ),
				'data-catalog-revision'  => (string) $catalog['revision'],
			)
		);

		ob_start();
		?>
		<section <?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php esc_attr_e( 'Menú del restaurante', 'vicunav-restaurante' ); ?>">
			<div class="vicu-restaurante-menu__controls" data-menu-controls>
				<div class="vicu-restaurante-menu__search">
					<label for="<?php echo esc_attr( $root_id ); ?>-search"><?php esc_html_e( 'Buscar en el menú', 'vicunav-restaurante' ); ?></label>
					<input id="<?php echo esc_attr( $root_id ); ?>-search" type="search" inputmode="search" autocomplete="off" data-menu-search>
				</div>
				<fieldset class="vicu-restaurante-menu__dietary">
					<legend><?php esc_html_e( 'Preferencias', 'vicunav-restaurante' ); ?></legend>
					<label><input type="checkbox" value="vegetarian" data-menu-dietary> <?php esc_html_e( 'Vegetariano', 'vicunav-restaurante' ); ?></label>
					<label><input type="checkbox" value="spicy" data-menu-dietary> <?php esc_html_e( 'Picante', 'vicunav-restaurante' ); ?></label>
				</fieldset>
				<div class="vicu-restaurante-menu__categories" aria-label="<?php esc_attr_e( 'Categorías del menú', 'vicunav-restaurante' ); ?>" data-menu-categories>
					<?php echo self::category_button( '', __( 'Todos', 'vicunav-restaurante' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php foreach ( $catalog['categories'] as $category ) : ?>
						<?php echo self::category_button( (string) $category['slug'], (string) $category['name'], false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="vicu-restaurante-menu__status" role="status" aria-live="polite" aria-atomic="true" data-menu-status></div>
			<p class="vicu-restaurante-menu__error" role="alert" data-menu-error hidden></p>
			<p class="vicu-restaurante-menu__empty" data-menu-empty <?php echo array() === $items ? '' : 'hidden'; ?>><?php esc_html_e( 'El menú todavía no tiene platos publicados.', 'vicunav-restaurante' ); ?></p>

			<ul class="vicu-restaurante-menu__grid" aria-label="<?php esc_attr_e( 'Resultados del menú', 'vicunav-restaurante' ); ?>" data-menu-items>
				<?php foreach ( $items as $item ) : ?>
					<?php echo self::item( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endforeach; ?>
			</ul>

			<p class="vicu-restaurante-menu__allergen-note"><?php esc_html_e( 'La información de alérgenos no elimina el riesgo de contaminación cruzada.', 'vicunav-restaurante' ); ?></p>
			<noscript><p><?php esc_html_e( 'El catálogo completo está visible. Activa JavaScript para usar los filtros.', 'vicunav-restaurante' ); ?></p></noscript>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Construye un botón de categoría.
	 *
	 * @param string $slug     Slug o cadena vacía para todas.
	 * @param string $name     Nombre visible.
	 * @param bool   $selected Estado inicial.
	 * @return string
	 */
	private static function category_button( string $slug, string $name, bool $selected ): string {
		return sprintf(
			'<button type="button" data-menu-category="%1$s" aria-pressed="%2$s">%3$s</button>',
			esc_attr( $slug ),
			$selected ? 'true' : 'false',
			esc_html( $name )
		);
	}

	/**
	 * Construye una tarjeta pública a partir de la proyección validada.
	 *
	 * @param array<string, mixed> $item Item del repositorio.
	 * @return string
	 */
	private static function item( array $item ): string {
		$tags       = array_map( 'sanitize_key', (array) $item['dietary_tags'] );
		$allergens  = array_map( 'sanitize_key', (array) $item['allergens'] );
		$searchable = remove_accents( strtolower( (string) $item['name'] . ' ' . (string) $item['description'] ) );

		ob_start();
		?>
		<li class="vicu-restaurante-menu__item<?php echo $item['available'] ? '' : ' is-unavailable'; ?>" data-menu-item data-category="<?php echo esc_attr( (string) $item['category'] ); ?>" data-search="<?php echo esc_attr( $searchable ); ?>" data-dietary-tags="<?php echo esc_attr( implode( ' ', $tags ) ); ?>" data-public-id="<?php echo esc_attr( (string) $item['public_id'] ); ?>">
			<?php if ( is_array( $item['image'] ) ) : ?>
				<img class="vicu-restaurante-menu__image" src="<?php echo esc_url( (string) $item['image']['url'] ); ?>" alt="<?php echo esc_attr( (string) $item['image']['alt'] ); ?>" width="<?php echo esc_attr( (string) $item['image']['width'] ); ?>" height="<?php echo esc_attr( (string) $item['image']['height'] ); ?>" loading="lazy" decoding="async">
			<?php endif; ?>
			<div class="vicu-restaurante-menu__item-content">
				<div class="vicu-restaurante-menu__item-heading">
					<h3><?php echo esc_html( (string) $item['name'] ); ?></h3>
					<span class="vicu-restaurante-menu__availability" data-menu-availability><?php echo esc_html( $item['available'] ? __( 'Disponible', 'vicunav-restaurante' ) : __( 'Agotado', 'vicunav-restaurante' ) ); ?></span>
				</div>
				<p><?php echo esc_html( (string) $item['description'] ); ?></p>
				<p class="vicu-restaurante-menu__price" data-price-minor="<?php echo esc_attr( (string) $item['price_minor'] ); ?>" data-currency="<?php echo esc_attr( (string) $item['currency'] ); ?>"><?php echo esc_html( self::price( (int) $item['price_minor'], (string) $item['currency'] ) ); ?></p>
				<?php if ( array() !== $tags ) : ?>
					<p class="vicu-restaurante-menu__tags"><?php echo esc_html( implode( ' · ', array_map( array( self::class, 'dietary_label' ), $tags ) ) ); ?></p>
				<?php endif; ?>
				<?php if ( array() !== $allergens ) : ?>
					<p class="vicu-restaurante-menu__allergens"><strong><?php esc_html_e( 'Alérgenos:', 'vicunav-restaurante' ); ?></strong> <?php echo esc_html( implode( ', ', $allergens ) ); ?></p>
				<?php endif; ?>
			</div>
		</li>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Formatea un importe informativo sin alterar el valor autoritativo.
	 *
	 * @param int    $minor    Importe en unidad menor.
	 * @param string $currency Moneda operativa.
	 * @return string
	 */
	private static function price( int $minor, string $currency ): string {
		return number_format_i18n( $minor / 100, 2 ) . ' ' . $currency;
	}

	/**
	 * Traduce las etiquetas dietarias usadas por este bloque.
	 *
	 * @param string $tag Identificador estable.
	 * @return string
	 */
	private static function dietary_label( string $tag ): string {
		$labels = array(
			'spicy'      => __( 'Picante', 'vicunav-restaurante' ),
			'vegan'      => __( 'Vegano', 'vicunav-restaurante' ),
			'vegetarian' => __( 'Vegetariano', 'vicunav-restaurante' ),
		);

		return $labels[ $tag ] ?? $tag;
	}
}
