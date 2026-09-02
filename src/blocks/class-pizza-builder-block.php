<?php
/**
 * Render de servidor del constructor de pizzas.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Blocks;

use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Catalog\IngredientService;
use Vicu\Restaurante\Catalog\PizzaOptionService;

/**
 * Publica opciones vigentes y delega toda cotización y mutación a REST.
 */
final class PizzaBuilderBlock {
	/** Renderiza un formulario utilizable y mejorado por Interactivity API. */
	public static function render(): string {
		$catalog  = self::catalog();
		$defaults = array(
			'sizeId'   => self::first_available_id( $catalog['sizes'] ),
			'crustId'  => self::first_available_id( $catalog['crusts'] ),
			'sauceId'  => self::first_available_id( $catalog['sauces'] ),
			'cheeseId' => self::first_available_id( $catalog['cheeses'] ),
		);
		$empty    = in_array( '', $defaults, true );
		$root_id  = wp_unique_id( 'vicu-restaurante-pizza-builder-' );

		if ( $empty ) {
			$attributes = get_block_wrapper_attributes();

			return sprintf(
				'<section %1$s aria-label="%2$s"><p role="status">%3$s</p></section>',
				$attributes,
				esc_attr__( 'Constructor de pizzas', 'vicunav-restaurante' ),
				esc_html__( 'El constructor no está disponible porque faltan opciones configuradas.', 'vicunav-restaurante' )
			);
		}

		$context    = array(
			'catalogRevision' => AvailabilityRevision::current(),
			'sizeId'          => $defaults['sizeId'],
			'crustId'         => $defaults['crustId'],
			'sauceId'         => $defaults['sauceId'],
			'cheeseId'        => $defaults['cheeseId'],
			'toppings'        => array(),
			'activeZone'      => 'whole',
			'hasQuote'        => false,
			'isBusy'          => false,
			'quoteTotal'      => '',
			'statusMessage'   => '',
			'errorMessage'    => '',
			'successMessage'  => '',
			'locale'          => str_replace( '_', '-', determine_locale() ),
			'quoteUrl'        => esc_url_raw( rest_url( 'vicu/v1/restaurante/pizza/quote' ) ),
			'cartUrl'         => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart' ) ),
			'cartsUrl'        => esc_url_raw( rest_url( 'vicu/v1/restaurante/carts' ) ),
			'cartItemsUrl'    => esc_url_raw( rest_url( 'vicu/v1/restaurante/cart/items' ) ),
			'savedPizzasUrl'  => esc_url_raw( rest_url( 'vicu/v1/restaurante/saved-pizzas' ) ),
			'restNonce'       => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'catalogById'     => self::catalog_by_id( $catalog ),
			'labels'          => array(
				'quoting'         => __( 'Confirmando precio y disponibilidad.', 'vicunav-restaurante' ),
				'quoted'          => __( 'Precio confirmado.', 'vicunav-restaurante' ),
				'quoteError'      => __( 'No pudimos confirmar el precio.', 'vicunav-restaurante' ),
				'adding'          => __( 'Añadiendo la pizza al carrito.', 'vicunav-restaurante' ),
				/* translators: %d: cantidad de líneas que contiene el carrito. */
				'added'           => __( 'Pizza añadida. El carrito contiene %d líneas.', 'vicunav-restaurante' ),
				'cartError'       => __( 'No pudimos añadir la pizza al carrito.', 'vicunav-restaurante' ),
				'saving'          => __( 'Guardando la pizza en tu cuenta.', 'vicunav-restaurante' ),
				'saved'           => __( 'Pizza guardada en tu cuenta.', 'vicunav-restaurante' ),
				'saveError'       => __( 'No pudimos guardar la pizza.', 'vicunav-restaurante' ),
				'maximumToppings' => __( 'Puedes elegir un máximo de 6 toppings.', 'vicunav-restaurante' ),
				'pizzaLabel'      => __( 'Tu pizza', 'vicunav-restaurante' ),
				'wholeLabel'      => __( 'Entera', 'vicunav-restaurante' ),
				'leftLabel'       => __( 'Mitad izquierda', 'vicunav-restaurante' ),
				'rightLabel'      => __( 'Mitad derecha', 'vicunav-restaurante' ),
				'vegetarianBadge' => __( 'Vegetariana', 'vicunav-restaurante' ),
				'dairyBadge'      => __( 'Contiene lácteos', 'vicunav-restaurante' ),
				'glutenBadge'     => __( 'Contiene gluten', 'vicunav-restaurante' ),
			),
		);
		$attributes = get_block_wrapper_attributes(
			array(
				'id'                  => $root_id,
				'data-wp-interactive' => 'vicunav/restaurante-pizza-builder',
				'data-wp-context'     => wp_json_encode( $context ),
				'data-wp-init'        => 'actions.initialize',
			)
		);

		ob_start();
		?>
		<section <?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php esc_attr_e( 'Constructor de pizzas', 'vicunav-restaurante' ); ?>">
			<form class="vicu-restaurante-pizza-builder__form" data-wp-on--submit="actions.addToCart">
				<div class="vicu-restaurante-pizza-builder__layout">
					<aside class="vicu-restaurante-pizza-builder__preview">
						<div class="vicu-restaurante-pizza-builder__pizza" data-wp-bind--style="state.pizzaVisualStyle" aria-hidden="true">
							<div class="vicu-restaurante-pizza-builder__pizza-crust"></div>
							<div class="vicu-restaurante-pizza-builder__pizza-sauce" data-wp-bind--style="state.pizzaSauceStyle"></div>
							<div class="vicu-restaurante-pizza-builder__pizza-cheese" data-wp-bind--style="state.pizzaCheeseStyle"></div>
							<div class="vicu-restaurante-pizza-builder__pizza-divider" data-wp-bind--hidden="!state.showHalfDivider"></div>
							<div class="vicu-restaurante-pizza-builder__pizza-dots" data-wp-watch="actions.renderPizzaDots"></div>
						</div>
						<div class="vicu-restaurante-pizza-builder__badges">
							<span data-wp-bind--hidden="!state.isVegetarian"><?php esc_html_e( 'Vegetariana', 'vicunav-restaurante' ); ?></span>
							<span data-wp-bind--hidden="!state.hasDairy"><?php esc_html_e( 'Contiene lácteos', 'vicunav-restaurante' ); ?></span>
							<span data-wp-bind--hidden="!state.hasGluten"><?php esc_html_e( 'Contiene gluten', 'vicunav-restaurante' ); ?></span>
						</div>
						<div class="vicu-restaurante-pizza-builder__pizza-summary">
							<p class="vicu-restaurante-pizza-builder__pizza-label"><?php esc_html_e( 'Tu pizza', 'vicunav-restaurante' ); ?></p>
							<p data-wp-text="state.pizzaSummaryHeader"></p>
							<p data-wp-bind--hidden="!state.wholeToppingsText"><strong><?php esc_html_e( 'Entera:', 'vicunav-restaurante' ); ?></strong> <span data-wp-text="state.wholeToppingsText"></span></p>
							<p data-wp-bind--hidden="!state.leftToppingsText"><strong><?php esc_html_e( 'Mitad izquierda:', 'vicunav-restaurante' ); ?></strong> <span data-wp-text="state.leftToppingsText"></span></p>
							<p data-wp-bind--hidden="!state.rightToppingsText"><strong><?php esc_html_e( 'Mitad derecha:', 'vicunav-restaurante' ); ?></strong> <span data-wp-text="state.rightToppingsText"></span></p>
						</div>
						<div class="vicu-restaurante-pizza-builder__summary">
							<p><strong><?php esc_html_e( 'Total confirmado:', 'vicunav-restaurante' ); ?></strong> <span data-wp-text="context.quoteTotal">-</span></p>
							<p role="status" aria-live="polite" aria-atomic="true" data-wp-text="context.statusMessage"></p>
							<p class="vicu-restaurante-pizza-builder__error" role="alert" data-wp-bind--hidden="!context.errorMessage" data-wp-text="context.errorMessage"></p>
							<p class="vicu-restaurante-pizza-builder__success" role="status" data-wp-bind--hidden="!context.successMessage" data-wp-text="context.successMessage"></p>
							<div class="vicu-restaurante-pizza-builder__actions">
								<button type="submit" data-wp-bind--disabled="!state.canAdd"><?php esc_html_e( 'Añadir pizza al carrito', 'vicunav-restaurante' ); ?></button>
								<button type="button" data-wp-on--click="actions.refreshCatalog"><?php esc_html_e( 'Actualizar opciones', 'vicunav-restaurante' ); ?></button>
							</div>
							<?php if ( is_user_logged_in() ) : ?>
								<div class="vicu-restaurante-pizza-builder__save">
									<label for="<?php echo esc_attr( $root_id ); ?>-saved-name"><?php esc_html_e( 'Nombre para guardar', 'vicunav-restaurante' ); ?></label>
									<input id="<?php echo esc_attr( $root_id ); ?>-saved-name" data-saved-pizza-name maxlength="100" value="<?php esc_attr_e( 'Mi pizza', 'vicunav-restaurante' ); ?>" required>
									<button type="button" data-wp-on--click="actions.savePizza" data-wp-bind--disabled="!state.canAdd"><?php esc_html_e( 'Guardar en mi cuenta', 'vicunav-restaurante' ); ?></button>
								</div>
							<?php endif; ?>
						</div>
					</aside>

					<div class="vicu-restaurante-pizza-builder__steps">
						<?php echo self::option_group( 'sizeId', __( 'Tamaño', 'vicunav-restaurante' ), $catalog['sizes'], $context['sizeId'], $root_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo self::option_group( 'crustId', __( 'Masa', 'vicunav-restaurante' ), $catalog['crusts'], $context['crustId'], $root_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo self::option_group( 'sauceId', __( 'Salsa', 'vicunav-restaurante' ), $catalog['sauces'], $context['sauceId'], $root_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo self::option_group( 'cheeseId', __( 'Queso', 'vicunav-restaurante' ), $catalog['cheeses'], $context['cheeseId'], $root_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

						<fieldset class="vicu-restaurante-pizza-builder__zones">
							<legend><?php esc_html_e( 'Zona para el siguiente topping', 'vicunav-restaurante' ); ?></legend>
							<?php foreach ( self::zones() as $zone => $label ) : ?>
								<button type="button" data-zone="<?php echo esc_attr( $zone ); ?>" data-wp-context='<?php echo esc_attr( (string) wp_json_encode( array( 'zoneValue' => $zone ) ) ); ?>' data-wp-on--click="actions.selectZone" data-wp-bind--aria-pressed="state.isZoneActive"><?php echo esc_html( $label ); ?></button>
							<?php endforeach; ?>
						</fieldset>

						<fieldset class="vicu-restaurante-pizza-builder__toppings">
							<legend><?php esc_html_e( 'Toppings (máximo 6)', 'vicunav-restaurante' ); ?></legend>
							<p class="vicu-restaurante-pizza-builder__toppings-hint" data-wp-text="state.toppingsHint"></p>
							<div class="vicu-restaurante-pizza-builder__option-grid vicu-restaurante-pizza-builder__option-grid--compact">
								<?php foreach ( $catalog['toppings'] as $topping ) : ?>
									<button type="button" <?php disabled( ! $topping['available'] ); ?> data-wp-context='<?php echo esc_attr( (string) wp_json_encode( array( 'ingredientId' => $topping['public_id'] ) ) ); ?>' data-wp-on--click="actions.toggleTopping" data-wp-bind--aria-pressed="state.isToppingSelected" data-wp-class--is-selected="state.isToppingSelected">
										<span><?php echo esc_html( (string) $topping['name'] ); ?></span>
										<small data-wp-text="state.toppingZoneSuffix"></small>
										<?php
										if ( ! $topping['available'] ) :
											?>
											<span><?php esc_html_e( 'Agotado', 'vicunav-restaurante' ); ?></span><?php endif; ?>
									</button>
								<?php endforeach; ?>
							</div>
						</fieldset>
					</div>
				</div>
			</form>
			<noscript><p><?php esc_html_e( 'Activa JavaScript para confirmar el precio y añadir la pizza al carrito.', 'vicunav-restaurante' ); ?></p></noscript>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/** Devuelve el catálogo estructurado sin incorporar reglas de precio. */
	private static function catalog(): array {
		$catalog = array(
			'sizes'    => array(),
			'crusts'   => array(),
			'sauces'   => array(),
			'cheeses'  => array(),
			'toppings' => array(),
		);

		foreach ( PizzaOptionService::all() as $option ) {
			$catalog[ $option['type'] . 's' ][] = $option;
		}

		foreach ( IngredientService::all() as $ingredient ) {
			if ( 'cheese' === $ingredient['category'] ) {
				$catalog['cheeses'][] = $ingredient;
			} elseif ( 'topping' === $ingredient['category'] ) {
				$catalog['toppings'][] = $ingredient;
			}
		}

		return $catalog;
	}

	/**
	 * Indexa el catálogo por UUID para que el cliente resuelva nombres y
	 * etiquetas dietarias sin una segunda consulta: el visual de la pizza,
	 * las insignias dietarias y el resumen de zonas se derivan de esto en
	 * `view.js`, nunca de una tabla fija por opción.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $catalog Catálogo agrupado por tipo.
	 * @return array<string, array<string, array<string, mixed>>> Catálogo indexado por UUID.
	 */
	private static function catalog_by_id( array $catalog ): array {
		$by_id = array();

		foreach ( $catalog as $group => $options ) {
			$by_id[ $group ] = array();
			foreach ( $options as $option ) {
				$by_id[ $group ][ $option['public_id'] ] = array(
					'name'        => (string) $option['name'],
					'dietaryTags' => $option['dietary_tags'] ?? array(),
					'allergens'   => $option['allergens'] ?? array(),
				);
			}
		}

		return $by_id;
	}

	/**
	 * Renderiza un grupo de radios con disponibilidad explícita.
	 *
	 * @param string               $field    Propiedad del contexto que se actualizará.
	 * @param string               $legend   Leyenda visible del grupo.
	 * @param array<string, mixed> $options  Opciones vivas del catálogo.
	 * @param string               $selected UUID seleccionado inicialmente.
	 * @param string               $root_id  Prefijo único para los controles.
	 * @return string HTML escapado del grupo.
	 */
	private static function option_group( string $field, string $legend, array $options, string $selected, string $root_id ): string {
		ob_start();
		?>
		<fieldset class="vicu-restaurante-pizza-builder__options">
			<legend><?php echo esc_html( $legend ); ?></legend>
			<div class="vicu-restaurante-pizza-builder__option-grid">
				<?php foreach ( $options as $index => $option ) : ?>
					<?php $input_id = $root_id . '-' . sanitize_key( $field ) . '-' . $index; ?>
					<label for="<?php echo esc_attr( $input_id ); ?>">
						<input id="<?php echo esc_attr( $input_id ); ?>" type="radio" name="<?php echo esc_attr( $root_id . '-' . $field ); ?>" value="<?php echo esc_attr( (string) $option['public_id'] ); ?>" data-configuration-field="<?php echo esc_attr( $field ); ?>" data-wp-on--change="actions.selectOption" <?php checked( $selected, $option['public_id'] ); ?> <?php disabled( ! $option['available'] ); ?>>
						<span><?php echo esc_html( (string) $option['name'] ); ?></span>
						<?php
						if ( ! $option['available'] ) :
							?>
							<small><?php esc_html_e( 'Agotado', 'vicunav-restaurante' ); ?></small><?php endif; ?>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Elige el primer valor realmente disponible.
	 *
	 * @param array<string, mixed> $options Opciones del grupo.
	 * @return string UUID disponible o cadena vacía.
	 */
	private static function first_available_id( array $options ): string {
		foreach ( $options as $option ) {
			if ( $option['available'] ) {
				return (string) $option['public_id'];
			}
		}

		return '';
	}

	/** Etiquetas localizadas de las zonas contractuales. */
	private static function zones(): array {
		return array(
			'whole' => __( 'Completa', 'vicunav-restaurante' ),
			'left'  => __( 'Mitad izquierda', 'vicunav-restaurante' ),
			'right' => __( 'Mitad derecha', 'vicunav-restaurante' ),
		);
	}
}
