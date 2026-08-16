<?php
/**
 * Administración nativa de ingredientes y opciones.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Admin;

use Vicu\Restaurante\Catalog\AvailabilityRevision;
use Vicu\Restaurante\Catalog\CatalogValidator;
use Vicu\Restaurante\Catalog\IngredientService;
use Vicu\Restaurante\Catalog\PizzaOptionService;

/**
 * Expone formularios acotados bajo el menú Vicunav.
 */
final class CatalogAdmin {
	private const INGREDIENT_PAGE   = 'vicu-restaurante-ingredients';
	private const OPTION_PAGE       = 'vicu-restaurante-pizza-options';
	private const AVAILABILITY_PAGE = 'vicu-restaurante-availability';

	/**
	 * Evita registrar hooks dos veces.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Registra submenús y handlers POST.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'admin_menu', array( self::class, 'register_pages' ), 20 );
		add_action( 'admin_post_vicu_restaurante_save_ingredient', array( self::class, 'save_ingredient' ) );
		add_action( 'admin_post_vicu_restaurante_save_pizza_option', array( self::class, 'save_option' ) );
		add_action( 'admin_post_vicu_restaurante_set_availability', array( self::class, 'set_availability' ) );
		self::$hooks_registered = true;
	}

	/**
	 * Añade páginas separadas por capability.
	 *
	 * @return void
	 */
	public static function register_pages(): void {
		add_submenu_page( 'vicunav', __( 'Ingredientes', 'vicunav-restaurante' ), __( 'Ingredientes', 'vicunav-restaurante' ), 'manage_vicu_restaurant_catalog', self::INGREDIENT_PAGE, array( self::class, 'render_ingredients' ) );
		add_submenu_page( 'vicunav', __( 'Opciones de pizza', 'vicunav-restaurante' ), __( 'Opciones de pizza', 'vicunav-restaurante' ), 'manage_vicu_restaurant_catalog', self::OPTION_PAGE, array( self::class, 'render_options' ) );
		add_submenu_page( 'vicunav', __( 'Disponibilidad', 'vicunav-restaurante' ), __( 'Disponibilidad', 'vicunav-restaurante' ), 'manage_vicu_restaurant_availability', self::AVAILABILITY_PAGE, array( self::class, 'render_availability' ) );
	}

	/**
	 * Renderiza catálogo y formularios de ingredientes.
	 *
	 * @return void
	 */
	public static function render_ingredients(): void {
		self::require_capability( 'manage_vicu_restaurant_catalog' );
		?>
		<div class="wrap"><h1><?php echo esc_html__( 'Ingredientes', 'vicunav-restaurante' ); ?></h1>
			<p><?php echo esc_html__( 'La disponibilidad se modifica en su pantalla dedicada.', 'vicunav-restaurante' ); ?></p>
			<?php foreach ( IngredientService::all() as $ingredient ) : ?>
				<?php self::render_ingredient_form( $ingredient ); ?>
			<?php endforeach; ?>
			<h2><?php echo esc_html__( 'Añadir ingrediente', 'vicunav-restaurante' ); ?></h2>
			<?php self::render_ingredient_form( null ); ?>
		</div>
		<?php
	}

	/**
	 * Renderiza catálogo y formularios de opciones.
	 *
	 * @return void
	 */
	public static function render_options(): void {
		self::require_capability( 'manage_vicu_restaurant_catalog' );
		?>
		<div class="wrap"><h1><?php echo esc_html__( 'Opciones de pizza', 'vicunav-restaurante' ); ?></h1>
			<?php foreach ( PizzaOptionService::all() as $option ) : ?>
				<?php self::render_option_form( $option ); ?>
			<?php endforeach; ?>
			<h2><?php echo esc_html__( 'Añadir opción', 'vicunav-restaurante' ); ?></h2>
			<?php self::render_option_form( null ); ?>
		</div>
		<?php
	}

	/**
	 * Renderiza toggles para operadores de disponibilidad.
	 *
	 * @return void
	 */
	public static function render_availability(): void {
		self::require_capability( 'manage_vicu_restaurant_availability' );
		?>
		<div class="wrap"><h1><?php echo esc_html__( 'Disponibilidad', 'vicunav-restaurante' ); ?></h1>
			<?php /* translators: %d: revisión pública del catálogo. */ ?>
			<p><?php echo esc_html( sprintf( __( 'Revisión pública actual: %d', 'vicunav-restaurante' ), AvailabilityRevision::current() ) ); ?></p>
			<h2><?php echo esc_html__( 'Ingredientes', 'vicunav-restaurante' ); ?></h2>
			<?php foreach ( IngredientService::all() as $ingredient ) : ?>
				<?php self::render_availability_form( 'ingredient', $ingredient ); ?>
			<?php endforeach; ?>
			<h2><?php echo esc_html__( 'Opciones de pizza', 'vicunav-restaurante' ); ?></h2>
			<?php foreach ( PizzaOptionService::all() as $option ) : ?>
				<?php self::render_availability_form( 'option', $option ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Crea o actualiza un ingrediente conservando su disponibilidad.
	 *
	 * @return void
	 */
	public static function save_ingredient(): void {
		self::require_capability( 'manage_vicu_restaurant_catalog' );
		check_admin_referer( 'vicu_restaurante_save_ingredient' );

		$public_id = self::request_text( 'public_id' );
		$current   = '' === $public_id ? null : IngredientService::find( $public_id );

		if ( '' !== $public_id && null === $current ) {
			self::redirect( self::INGREDIENT_PAGE, 'vicu_restaurante_not_found' );
		}

		$input = array(
			'name'                 => self::request_text( 'name' ),
			'category'             => self::request_text( 'category' ),
			'price_modifier_minor' => self::request_text( 'price_modifier_minor' ),
			'available'            => null === $current ? false : $current['available'],
			'allergens'            => self::request_csv( 'allergens' ),
			'dietary_tags'         => self::request_csv( 'dietary_tags' ),
		);

		$result = null === $current
			? IngredientService::create( $input )
			: IngredientService::update( $public_id, absint( self::request_text( 'expected_revision' ) ), $input );

		self::redirect( self::INGREDIENT_PAGE, is_wp_error( $result ) ? $result->get_error_code() : 'saved' );
	}

	/**
	 * Crea o actualiza una opción conservando disponibilidad.
	 *
	 * @return void
	 */
	public static function save_option(): void {
		self::require_capability( 'manage_vicu_restaurant_catalog' );
		check_admin_referer( 'vicu_restaurante_save_pizza_option' );

		$public_id = self::request_text( 'public_id' );
		$current   = '' === $public_id ? null : PizzaOptionService::find( $public_id );

		if ( '' !== $public_id && null === $current ) {
			self::redirect( self::OPTION_PAGE, 'vicu_restaurante_not_found' );
		}

		$input = array(
			'name'                 => self::request_text( 'name' ),
			'type'                 => self::request_text( 'type' ),
			'price_modifier_minor' => self::request_text( 'price_modifier_minor' ),
			'display_order'        => self::request_text( 'display_order' ),
			'available'            => null === $current ? false : $current['available'],
		);

		$result = null === $current
			? PizzaOptionService::create( $input )
			: PizzaOptionService::update( $public_id, absint( self::request_text( 'expected_revision' ) ), $input );

		self::redirect( self::OPTION_PAGE, is_wp_error( $result ) ? $result->get_error_code() : 'saved' );
	}

	/**
	 * Cambia únicamente disponibilidad mediante el servicio autoritativo.
	 *
	 * @return void
	 */
	public static function set_availability(): void {
		self::require_capability( 'manage_vicu_restaurant_availability' );
		check_admin_referer( 'vicu_restaurante_set_availability' );

		$kind      = self::request_text( 'kind' );
		$public_id = self::request_text( 'public_id' );
		$revision  = absint( self::request_text( 'expected_revision' ) );
		$available = '1' === self::request_text( 'available' );

		if ( ! in_array( $kind, array( 'ingredient', 'option' ), true ) ) {
			self::redirect( self::AVAILABILITY_PAGE, 'vicu_restaurante_invalid_request' );
		}

		$current = 'ingredient' === $kind ? IngredientService::find( $public_id ) : PizzaOptionService::find( $public_id );

		if ( null === $current ) {
			self::redirect( self::AVAILABILITY_PAGE, 'vicu_restaurante_not_found' );
		}

		$current['available'] = $available;
		$result               = 'ingredient' === $kind
			? IngredientService::update( $public_id, $revision, $current )
			: PizzaOptionService::update( $public_id, $revision, $current );

		self::redirect( self::AVAILABILITY_PAGE, is_wp_error( $result ) ? $result->get_error_code() : 'saved' );
	}

	/**
	 * Renderiza un formulario de ingrediente.
	 *
	 * @param array<string, mixed>|null $ingredient Fila o creación.
	 * @return void
	 */
	private static function render_ingredient_form( ?array $ingredient ): void {
		$ingredient = $ingredient ?? array(
			'public_id'            => '',
			'name'                 => '',
			'category'             => 'base',
			'price_modifier_minor' => 0,
			'allergens'            => array(),
			'dietary_tags'         => array(),
			'revision'             => 0,
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1rem 0;padding:1rem;background:#fff">
			<input type="hidden" name="action" value="vicu_restaurante_save_ingredient">
			<input type="hidden" name="public_id" value="<?php echo esc_attr( $ingredient['public_id'] ); ?>">
			<input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) $ingredient['revision'] ); ?>">
			<?php wp_nonce_field( 'vicu_restaurante_save_ingredient' ); ?>
			<label><?php echo esc_html__( 'Nombre', 'vicunav-restaurante' ); ?> <input name="name" value="<?php echo esc_attr( $ingredient['name'] ); ?>" required></label>
			<label><?php echo esc_html__( 'Categoría', 'vicunav-restaurante' ); ?> <select name="category">
			<?php
			foreach ( CatalogValidator::INGREDIENT_CATEGORIES as $category ) :
				?>
				<option value="<?php echo esc_attr( $category ); ?>" <?php selected( $category, $ingredient['category'] ); ?>><?php echo esc_html( $category ); ?></option><?php endforeach; ?></select></label>
			<label><?php echo esc_html__( 'Modificador en unidad menor', 'vicunav-restaurante' ); ?> <input type="number" name="price_modifier_minor" value="<?php echo esc_attr( (string) $ingredient['price_modifier_minor'] ); ?>" required></label>
			<label><?php echo esc_html__( 'Alérgenos (IDs separados por coma)', 'vicunav-restaurante' ); ?> <input name="allergens" value="<?php echo esc_attr( implode( ',', $ingredient['allergens'] ) ); ?>"></label>
			<label><?php echo esc_html__( 'Dieta (IDs separados por coma)', 'vicunav-restaurante' ); ?> <input name="dietary_tags" value="<?php echo esc_attr( implode( ',', $ingredient['dietary_tags'] ) ); ?>"></label>
			<button class="button button-primary" type="submit"><?php echo esc_html__( 'Guardar', 'vicunav-restaurante' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Renderiza un formulario de opción.
	 *
	 * @param array<string, mixed>|null $option Fila o creación.
	 * @return void
	 */
	private static function render_option_form( ?array $option ): void {
		$option = $option ?? array(
			'public_id'            => '',
			'type'                 => 'size',
			'name'                 => '',
			'price_modifier_minor' => 0,
			'display_order'        => 0,
			'revision'             => 0,
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1rem 0;padding:1rem;background:#fff">
			<input type="hidden" name="action" value="vicu_restaurante_save_pizza_option">
			<input type="hidden" name="public_id" value="<?php echo esc_attr( $option['public_id'] ); ?>">
			<input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) $option['revision'] ); ?>">
			<?php wp_nonce_field( 'vicu_restaurante_save_pizza_option' ); ?>
			<label><?php echo esc_html__( 'Nombre', 'vicunav-restaurante' ); ?> <input name="name" value="<?php echo esc_attr( $option['name'] ); ?>" required></label>
			<label><?php echo esc_html__( 'Tipo', 'vicunav-restaurante' ); ?> <select name="type">
			<?php
			foreach ( CatalogValidator::OPTION_TYPES as $type ) :
				?>
				<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $type, $option['type'] ); ?>><?php echo esc_html( $type ); ?></option><?php endforeach; ?></select></label>
			<label><?php echo esc_html__( 'Modificador', 'vicunav-restaurante' ); ?> <input type="number" name="price_modifier_minor" value="<?php echo esc_attr( (string) $option['price_modifier_minor'] ); ?>" required></label>
			<label><?php echo esc_html__( 'Orden', 'vicunav-restaurante' ); ?> <input type="number" min="0" name="display_order" value="<?php echo esc_attr( (string) $option['display_order'] ); ?>" required></label>
			<button class="button button-primary" type="submit"><?php echo esc_html__( 'Guardar', 'vicunav-restaurante' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Renderiza un toggle de disponibilidad con revisión esperada.
	 *
	 * @param string               $kind Tipo interno.
	 * @param array<string, mixed> $item Fila de catálogo.
	 * @return void
	 */
	private static function render_availability_form( string $kind, array $item ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:1rem;margin:.5rem 0">
			<input type="hidden" name="action" value="vicu_restaurante_set_availability">
			<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>">
			<input type="hidden" name="public_id" value="<?php echo esc_attr( $item['public_id'] ); ?>">
			<input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) $item['revision'] ); ?>">
			<?php wp_nonce_field( 'vicu_restaurante_set_availability' ); ?>
			<strong><?php echo esc_html( $item['name'] ); ?></strong>
			<select name="available"><option value="1" <?php selected( $item['available'] ); ?>><?php echo esc_html__( 'Disponible', 'vicunav-restaurante' ); ?></option><option value="0" <?php selected( ! $item['available'] ); ?>><?php echo esc_html__( 'No disponible', 'vicunav-restaurante' ); ?></option></select>
			<button class="button" type="submit"><?php echo esc_html__( 'Actualizar', 'vicunav-restaurante' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Lee texto escalar de POST después del nonce del handler.
	 *
	 * @param string $key Clave.
	 * @return string
	 */
	private static function request_text( string $key ): string {
		// Los únicos callers son handlers que verifican su nonce antes de llegar aquí.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $value;
	}

	/**
	 * Convierte un campo CSV en IDs.
	 *
	 * @param string $key Clave.
	 * @return string[]
	 */
	private static function request_csv( string $key ): array {
		$value = self::request_text( $key );

		return '' === $value ? array() : array_map( 'sanitize_key', array_map( 'trim', explode( ',', $value ) ) );
	}

	/**
	 * Exige una capability antes de renderizar o mutar.
	 *
	 * @param string $capability Capability requerida.
	 * @return void
	 */
	private static function require_capability( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'No autorizado.', 'vicunav-restaurante' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirige a una página propia con un código no sensible.
	 *
	 * @param string $page   Slug administrativo.
	 * @param string $status Código de resultado.
	 * @return never
	 */
	private static function redirect( string $page, string $status ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => $page,
					'vicu_status' => sanitize_key( $status ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
