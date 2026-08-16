<?php
/**
 * Metadatos operativos del menú.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Menu;

use WP_Post;

/**
 * Separa precio, disponibilidad y vocabularios del copy editorial.
 */
final class MenuMeta {
	public const PUBLIC_ID     = '_vicu_rest_public_id';
	public const PRICE_MINOR   = '_vicu_rest_price_minor';
	public const CURRENCY      = '_vicu_rest_currency';
	public const AVAILABLE     = '_vicu_rest_available';
	public const CALORIES_KCAL = '_vicu_rest_calories_kcal';
	public const ALLERGENS     = '_vicu_rest_allergens';
	public const DIETARY_TAGS  = '_vicu_rest_dietary_tags';

	private const NONCE_ACTION = 'vicu_restaurante_save_menu_item';
	private const NONCE_FIELD  = 'vicu_restaurante_menu_item_nonce';

	/**
	 * IDs estables de alérgenos y sus etiquetas administrativas.
	 *
	 * @var array<string, string>
	 */
	private const ALLERGEN_LABELS = array(
		'celery'      => 'Apio',
		'crustaceans' => 'Crustáceos',
		'eggs'        => 'Huevos',
		'fish'        => 'Pescado',
		'gluten'      => 'Gluten',
		'lupin'       => 'Altramuz',
		'milk'        => 'Leche',
		'molluscs'    => 'Moluscos',
		'mustard'     => 'Mostaza',
		'nuts'        => 'Frutos secos',
		'peanuts'     => 'Maní',
		'sesame'      => 'Sésamo',
		'soy'         => 'Soya',
		'sulphites'   => 'Sulfitos',
	);

	/**
	 * Etiquetas dietarias controladas de v1.
	 *
	 * @var array<string, string>
	 */
	private const DIETARY_LABELS = array(
		'spicy'      => 'Picante',
		'vegan'      => 'Vegano',
		'vegetarian' => 'Vegetariano',
	);

	/**
	 * Evita registrar hooks más de una vez.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Enlaza registro, edición e invalidación.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'init', array( self::class, 'register_meta' ), 20 );
		add_action( 'add_meta_boxes_' . MenuItemPostType::POST_TYPE, array( self::class, 'add_meta_box' ) );
		add_action( 'save_post_' . MenuItemPostType::POST_TYPE, array( self::class, 'save_meta_box' ), 10, 2 );
		add_action( 'wp_after_insert_post', array( self::class, 'after_insert' ), 10, 4 );
		add_action( 'added_post_meta', array( self::class, 'after_meta_change' ), 10, 4 );
		add_action( 'updated_post_meta', array( self::class, 'after_meta_change' ), 10, 4 );
		add_action( 'deleted_post_meta', array( self::class, 'after_meta_change' ), 10, 4 );
		add_action( 'set_object_terms', array( self::class, 'after_terms_change' ), 10, 6 );
		add_action( 'deleted_post', array( self::class, 'after_delete' ), 10, 2 );
		self::$hooks_registered = true;
	}

	/**
	 * Registra el schema de meta sin exponerlo en la API genérica.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		self::register_field( self::PUBLIC_ID, 'string', '', array( self::class, 'sanitize_public_id' ) );
		self::register_field( self::PRICE_MINOR, 'integer', 0, array( self::class, 'sanitize_non_negative_int' ) );
		self::register_field( self::CURRENCY, 'string', '', array( self::class, 'sanitize_currency' ) );
		self::register_field( self::AVAILABLE, 'boolean', false, 'rest_sanitize_boolean' );
		self::register_field( self::CALORIES_KCAL, 'integer', 0, array( self::class, 'sanitize_non_negative_int' ) );
		self::register_field( self::ALLERGENS, 'array', array(), array( self::class, 'sanitize_allergens' ) );
		self::register_field( self::DIETARY_TAGS, 'array', array(), array( self::class, 'sanitize_dietary_tags' ) );
	}

	/**
	 * Registra un campo operativo homogéneo.
	 *
	 * @param string   $key      Clave física.
	 * @param string   $type     Tipo REST de WordPress.
	 * @param mixed    $default_value Valor inicial.
	 * @param callable $sanitize Sanitizador específico.
	 * @return void
	 */
	private static function register_field( string $key, string $type, mixed $default_value, callable $sanitize ): void {
		register_post_meta(
			MenuItemPostType::POST_TYPE,
			$key,
			array(
				'type'              => $type,
				'single'            => true,
				'default'           => $default_value,
				'sanitize_callback' => $sanitize,
				'auth_callback'     => array( self::class, 'can_manage' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Verifica la capability del catálogo.
	 *
	 * @param mixed ...$unused Argumentos entregados por la Metadata API.
	 * @return bool
	 */
	public static function can_manage( mixed ...$unused ): bool {
		unset( $unused );

		return current_user_can( 'manage_vicu_restaurant_catalog' );
	}

	/**
	 * Normaliza un entero sin aceptar valores negativos.
	 *
	 * @param mixed $value Valor externo.
	 * @return int
	 */
	public static function sanitize_non_negative_int( mixed $value ): int {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return 0;
		}

		return max( 0, (int) $value );
	}

	/**
	 * Normaliza una moneda ISO 4217 de tres letras.
	 *
	 * @param mixed $value Valor externo.
	 * @return string
	 */
	public static function sanitize_currency( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$currency = strtoupper( sanitize_text_field( wp_unslash( (string) $value ) ) );

		return 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : '';
	}

	/**
	 * Conserva únicamente UUID v4 canónicos.
	 *
	 * @param mixed $value Valor candidato.
	 * @return string
	 */
	public static function sanitize_public_id( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = strtolower( sanitize_text_field( wp_unslash( (string) $value ) ) );

		return wp_is_uuid( $value, 4 ) ? $value : '';
	}

	/**
	 * Filtra alérgenos contra el vocabulario de v1.
	 *
	 * @param mixed $value Lista candidata.
	 * @return string[]
	 */
	public static function sanitize_allergens( mixed $value ): array {
		return self::sanitize_controlled_list( $value, array_keys( self::ALLERGEN_LABELS ) );
	}

	/**
	 * Filtra etiquetas dietarias contra el vocabulario de v1.
	 *
	 * @param mixed $value Lista candidata.
	 * @return string[]
	 */
	public static function sanitize_dietary_tags( mixed $value ): array {
		return self::sanitize_controlled_list( $value, array_keys( self::DIETARY_LABELS ) );
	}

	/**
	 * Devuelve IDs estables de alérgenos para schemas públicos.
	 *
	 * @return string[]
	 */
	public static function allergen_ids(): array {
		return array_keys( self::ALLERGEN_LABELS );
	}

	/**
	 * Devuelve IDs estables de dieta para schemas públicos.
	 *
	 * @return string[]
	 */
	public static function dietary_tag_ids(): array {
		return array_keys( self::DIETARY_LABELS );
	}

	/**
	 * Añade el panel operativo al editor nativo.
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		add_meta_box(
			'vicu-restaurante-menu-operations',
			__( 'Datos operativos', 'vicunav-restaurante' ),
			array( self::class, 'render_meta_box' ),
			MenuItemPostType::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Renderiza campos operativos seguros.
	 *
	 * @param WP_Post $post Item editado.
	 * @return void
	 */
	public static function render_meta_box( WP_Post $post ): void {
		$price        = self::sanitize_non_negative_int( get_post_meta( $post->ID, self::PRICE_MINOR, true ) );
		$currency     = self::sanitize_currency( get_post_meta( $post->ID, self::CURRENCY, true ) );
		$available    = rest_sanitize_boolean( get_post_meta( $post->ID, self::AVAILABLE, true ) );
		$calories     = self::sanitize_non_negative_int( get_post_meta( $post->ID, self::CALORIES_KCAL, true ) );
		$allergens    = self::sanitize_allergens( get_post_meta( $post->ID, self::ALLERGENS, true ) );
		$dietary_tags = self::sanitize_dietary_tags( get_post_meta( $post->ID, self::DIETARY_TAGS, true ) );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<p><label for="vicu-rest-price-minor"><?php echo esc_html__( 'Precio en unidad menor', 'vicunav-restaurante' ); ?></label><br>
		<input id="vicu-rest-price-minor" name="vicu_rest_price_minor" type="number" min="0" step="1" value="<?php echo esc_attr( (string) $price ); ?>" required></p>
		<p><label for="vicu-rest-currency"><?php echo esc_html__( 'Moneda ISO 4217', 'vicunav-restaurante' ); ?></label><br>
		<input id="vicu-rest-currency" name="vicu_rest_currency" type="text" maxlength="3" pattern="[A-Za-z]{3}" value="<?php echo esc_attr( $currency ); ?>" required></p>
		<p><label><input name="vicu_rest_available" type="checkbox" value="1" <?php checked( $available ); ?>> <?php echo esc_html__( 'Disponible para selecciones nuevas', 'vicunav-restaurante' ); ?></label></p>
		<p><label for="vicu-rest-calories"><?php echo esc_html__( 'Calorías aproximadas (kcal)', 'vicunav-restaurante' ); ?></label><br>
		<input id="vicu-rest-calories" name="vicu_rest_calories_kcal" type="number" min="0" step="1" value="<?php echo esc_attr( (string) $calories ); ?>"></p>
		<?php self::render_checkboxes( 'vicu_rest_allergens', __( 'Alérgenos', 'vicunav-restaurante' ), self::ALLERGEN_LABELS, $allergens ); ?>
		<?php self::render_checkboxes( 'vicu_rest_dietary_tags', __( 'Etiquetas dietarias', 'vicunav-restaurante' ), self::DIETARY_LABELS, $dietary_tags ); ?>
		<p class="description"><?php echo esc_html__( 'La información de alérgenos no elimina el riesgo de contaminación cruzada.', 'vicunav-restaurante' ); ?></p>
		<?php
	}

	/**
	 * Persiste el panel sin aceptar importes o vocabularios libres.
	 *
	 * @param int     $post_id Identificador interno.
	 * @param WP_Post $post    Item guardado.
	 * @return void
	 */
	public static function save_meta_box( int $post_id, WP_Post $post ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if (
			MenuItemPostType::POST_TYPE !== $post->post_type ||
			wp_is_post_autosave( $post_id ) ||
			wp_is_post_revision( $post_id ) ||
			! self::can_manage() ||
			'' === $nonce ||
			! wp_verify_nonce( $nonce, self::NONCE_ACTION )
		) {
			return;
		}

		$price    = isset( $_POST['vicu_rest_price_minor'] ) && is_scalar( $_POST['vicu_rest_price_minor'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['vicu_rest_price_minor'] ) )
			: 0;
		$currency = isset( $_POST['vicu_rest_currency'] ) && is_scalar( $_POST['vicu_rest_currency'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['vicu_rest_currency'] ) )
			: '';
		$calories = isset( $_POST['vicu_rest_calories_kcal'] ) && is_scalar( $_POST['vicu_rest_calories_kcal'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['vicu_rest_calories_kcal'] ) )
			: 0;

		update_post_meta( $post_id, self::PRICE_MINOR, self::sanitize_non_negative_int( $price ) );
		update_post_meta( $post_id, self::CURRENCY, self::sanitize_currency( $currency ) );
		update_post_meta( $post_id, self::AVAILABLE, isset( $_POST['vicu_rest_available'] ) );
		update_post_meta( $post_id, self::CALORIES_KCAL, self::sanitize_non_negative_int( $calories ) );
		update_post_meta( $post_id, self::ALLERGENS, self::sanitize_allergens( self::posted_array( 'vicu_rest_allergens' ) ) );
		update_post_meta( $post_id, self::DIETARY_TAGS, self::sanitize_dietary_tags( self::posted_array( 'vicu_rest_dietary_tags' ) ) );
	}

	/**
	 * Garantiza ID opaco y revisión después de una escritura del CPT.
	 *
	 * @param int          $post_id     Identificador interno.
	 * @param WP_Post      $post        Item guardado.
	 * @param bool         $update      Si ya existía.
	 * @param WP_Post|null $post_before Versión anterior.
	 * @return void
	 */
	public static function after_insert( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before ): void {
		unset( $update, $post_before );

		if ( MenuItemPostType::POST_TYPE !== $post->post_type || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( '' === self::sanitize_public_id( get_post_meta( $post_id, self::PUBLIC_ID, true ) ) ) {
			update_post_meta( $post_id, self::PUBLIC_ID, self::generate_public_id() );
		}

		CatalogRevision::bump();
	}

	/**
	 * Invalida al cambiar meta contractual de un item.
	 *
	 * @param int|int[] $meta_id ID interno de meta o IDs eliminados.
	 * @param int       $post_id   ID interno del item.
	 * @param string    $meta_key  Clave cambiada.
	 * @param mixed     $meta_value Valor nuevo o eliminado.
	 * @return void
	 */
	public static function after_meta_change( int|array $meta_id, int $post_id, string $meta_key, mixed $meta_value ): void {
		unset( $meta_id, $meta_value );

		if ( MenuItemPostType::POST_TYPE === get_post_type( $post_id ) && in_array( $meta_key, self::all_keys(), true ) ) {
			CatalogRevision::bump();
		}
	}

	/**
	 * Invalida al cambiar la categoría asignada.
	 *
	 * @param int          $object_id Item modificado.
	 * @param int[]|string $terms     Términos solicitados.
	 * @param int[]        $term_ids  IDs finales.
	 * @param string       $taxonomy  Taxonomía afectada.
	 * @param bool         $append    Si se añadieron relaciones.
	 * @param int[]        $old_term_ids IDs anteriores.
	 * @return void
	 */
	public static function after_terms_change( int $object_id, array|string $terms, array $term_ids, string $taxonomy, bool $append, array $old_term_ids ): void {
		unset( $terms, $term_ids, $append, $old_term_ids );

		if ( MenuCategory::TAXONOMY === $taxonomy && MenuItemPostType::POST_TYPE === get_post_type( $object_id ) ) {
			CatalogRevision::bump();
		}
	}

	/**
	 * Invalida cuando se elimina definitivamente un item.
	 *
	 * @param int     $post_id Identificador eliminado.
	 * @param WP_Post $post    Item eliminado.
	 * @return void
	 */
	public static function after_delete( int $post_id, WP_Post $post ): void {
		unset( $post_id );

		if ( MenuItemPostType::POST_TYPE === $post->post_type ) {
			CatalogRevision::bump();
		}
	}

	/**
	 * Devuelve todas las claves que invalidan el catálogo.
	 *
	 * @return string[]
	 */
	public static function all_keys(): array {
		return array(
			self::PUBLIC_ID,
			self::PRICE_MINOR,
			self::CURRENCY,
			self::AVAILABLE,
			self::CALORIES_KCAL,
			self::ALLERGENS,
			self::DIETARY_TAGS,
		);
	}

	/**
	 * Renderiza un vocabulario como checkboxes accesibles.
	 *
	 * @param string                $name     Nombre del campo.
	 * @param string                $legend   Etiqueta del grupo.
	 * @param array<string, string> $labels   Opciones controladas.
	 * @param string[]              $selected Valores vigentes.
	 * @return void
	 */
	private static function render_checkboxes( string $name, string $legend, array $labels, array $selected ): void {
		?>
		<fieldset><legend><strong><?php echo esc_html( $legend ); ?></strong></legend>
			<?php foreach ( $labels as $value => $label ) : ?>
				<label style="display:inline-block;margin:0 1rem .5rem 0"><input name="<?php echo esc_attr( $name ); ?>[]" type="checkbox" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $selected, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Filtra una lista y mantiene un orden estable.
	 *
	 * @param mixed    $value   Lista candidata.
	 * @param string[] $allowed IDs permitidos.
	 * @return string[]
	 */
	private static function sanitize_controlled_list( mixed $value, array $allowed ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array_map( 'sanitize_key', wp_unslash( $value ) );
		$sanitized = array_values( array_unique( array_intersect( $sanitized, $allowed ) ) );
		sort( $sanitized, SORT_STRING );

		return $sanitized;
	}

	/**
	 * Lee una lista del formulario sin aceptar escalares.
	 *
	 * @param string $key Clave de POST.
	 * @return array<mixed>
	 */
	private static function posted_array( string $key ): array {
		// El caller verificó el nonce antes de leer cualquier campo del formulario.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$values = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();

		return array_map( 'sanitize_text_field', $values );
	}

	/**
	 * Genera un UUID no repetido entre items del menú.
	 *
	 * @return string
	 */
	private static function generate_public_id(): string {
		do {
			$public_id = wp_generate_uuid4();
			// El catálogo es editorial y acotado; el UUID exacto evita enumeración y se cachea en las lecturas públicas.
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$existing = get_posts(
				array(
					'post_type'              => MenuItemPostType::POST_TYPE,
					'post_status'            => 'any',
					'fields'                 => 'ids',
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_key'               => self::PUBLIC_ID,
					'meta_value'             => $public_id,
				)
			);
			// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		} while ( array() !== $existing );

		return $public_id;
	}
}
