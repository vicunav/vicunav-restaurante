<?php
/**
 * Categorías jerárquicas del menú.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Menu;

use WP_Post;
use WP_Term;

/**
 * Registra categorías, campos operativos y selección exclusiva por item.
 */
final class MenuCategory {
	public const TAXONOMY     = 'vicu_menu_category';
	public const META_ORDER   = '_vicu_rest_order';
	public const META_VISIBLE = '_vicu_rest_visible';

	private const NONCE_ACTION = 'vicu_restaurante_save_menu_category';
	private const NONCE_FIELD  = 'vicu_restaurante_menu_category_nonce';

	/**
	 * Evita registrar hooks más de una vez.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Enlaza taxonomía y campos administrativos.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'init', array( self::class, 'register' ) );
		add_action( self::TAXONOMY . '_add_form_fields', array( self::class, 'render_add_fields' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( self::class, 'render_edit_fields' ) );
		add_action( 'created_' . self::TAXONOMY, array( self::class, 'save_fields' ) );
		add_action( 'edited_' . self::TAXONOMY, array( self::class, 'save_fields' ) );
		add_action( 'created_' . self::TAXONOMY, array( self::class, 'after_term_change' ), 20 );
		add_action( 'edited_' . self::TAXONOMY, array( self::class, 'after_term_change' ), 20 );
		add_action( 'delete_' . self::TAXONOMY, array( CatalogRevision::class, 'bump' ) );
		add_action( 'added_term_meta', array( self::class, 'after_meta_change' ), 10, 4 );
		add_action( 'updated_term_meta', array( self::class, 'after_meta_change' ), 10, 4 );
		add_action( 'deleted_term_meta', array( self::class, 'after_meta_change' ), 10, 4 );
		self::$hooks_registered = true;
	}

	/**
	 * Registra taxonomía y meta de término no expuesta por la API genérica.
	 *
	 * @return void
	 */
	public static function register(): void {
		$capability = 'manage_vicu_restaurant_catalog';

		register_taxonomy(
			self::TAXONOMY,
			MenuItemPostType::POST_TYPE,
			array(
				'labels'             => array(
					'name'          => __( 'Categorías del menú', 'vicunav-restaurante' ),
					'singular_name' => __( 'Categoría del menú', 'vicunav-restaurante' ),
					'add_new_item'  => __( 'Añadir categoría', 'vicunav-restaurante' ),
					'edit_item'     => __( 'Editar categoría', 'vicunav-restaurante' ),
				),
				'public'             => true,
				'publicly_queryable' => false,
				'hierarchical'       => true,
				'show_ui'            => true,
				'show_admin_column'  => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'rewrite'            => false,
				'query_var'          => false,
				'meta_box_cb'        => array( self::class, 'render_item_category_box' ),
				'capabilities'       => array(
					'manage_terms' => $capability,
					'edit_terms'   => $capability,
					'delete_terms' => $capability,
					'assign_terms' => $capability,
				),
			)
		);

		register_term_meta(
			self::TAXONOMY,
			self::META_ORDER,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( self::class, 'can_manage' ),
				'show_in_rest'      => false,
			)
		);

		register_term_meta(
			self::TAXONOMY,
			self::META_VISIBLE,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => array( self::class, 'can_manage' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Autoriza meta de categoría con la capability del catálogo.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_vicu_restaurant_catalog' );
	}

	/**
	 * Renderiza campos al crear una categoría.
	 *
	 * @return void
	 */
	public static function render_add_fields(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div class="form-field">
			<label for="vicu-rest-menu-category-order"><?php echo esc_html__( 'Orden', 'vicunav-restaurante' ); ?></label>
			<input id="vicu-rest-menu-category-order" name="vicu_rest_menu_category_order" type="number" min="0" step="1" value="0">
		</div>
		<div class="form-field">
			<label><input name="vicu_rest_menu_category_visible" type="checkbox" value="1"> <?php echo esc_html__( 'Visible en el menú público', 'vicunav-restaurante' ); ?></label>
		</div>
		<?php
	}

	/**
	 * Renderiza campos al editar una categoría.
	 *
	 * @param WP_Term $term Categoría editada.
	 * @return void
	 */
	public static function render_edit_fields( WP_Term $term ): void {
		$order   = absint( get_term_meta( $term->term_id, self::META_ORDER, true ) );
		$visible = rest_sanitize_boolean( get_term_meta( $term->term_id, self::META_VISIBLE, true ) );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<tr class="form-field">
			<th scope="row"><label for="vicu-rest-menu-category-order"><?php echo esc_html__( 'Orden', 'vicunav-restaurante' ); ?></label></th>
			<td><input id="vicu-rest-menu-category-order" name="vicu_rest_menu_category_order" type="number" min="0" step="1" value="<?php echo esc_attr( (string) $order ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php echo esc_html__( 'Visibilidad', 'vicunav-restaurante' ); ?></th>
			<td><label><input name="vicu_rest_menu_category_visible" type="checkbox" value="1" <?php checked( $visible ); ?>> <?php echo esc_html__( 'Visible en el menú público', 'vicunav-restaurante' ); ?></label></td>
		</tr>
		<?php
	}

	/**
	 * Persiste campos operativos con nonce y capability.
	 *
	 * @param int $term_id Identificador interno del término.
	 * @return void
	 */
	public static function save_fields( int $term_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! self::can_manage() || '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$order = isset( $_POST['vicu_rest_menu_category_order'] ) ? absint( wp_unslash( $_POST['vicu_rest_menu_category_order'] ) ) : 0;
		$order = min( 9999, $order );

		update_term_meta( $term_id, self::META_ORDER, $order );
		update_term_meta( $term_id, self::META_VISIBLE, isset( $_POST['vicu_rest_menu_category_visible'] ) );
		CatalogRevision::bump();
	}

	/**
	 * Invalida tras cambiar nombre, slug o descripción de una categoría.
	 *
	 * @return void
	 */
	public static function after_term_change(): void {
		CatalogRevision::bump();
	}

	/**
	 * Invalida al cambiar meta operativo de una categoría del menú.
	 *
	 * @param int|int[] $meta_id ID interno de meta o IDs eliminados.
	 * @param int       $term_id    ID interno del término.
	 * @param string    $meta_key   Clave cambiada.
	 * @param mixed     $meta_value Valor nuevo o eliminado.
	 * @return void
	 */
	public static function after_meta_change( int|array $meta_id, int $term_id, string $meta_key, mixed $meta_value ): void {
		unset( $meta_id, $meta_value );
		$term = get_term( $term_id );

		if (
			in_array( $meta_key, array( self::META_ORDER, self::META_VISIBLE ), true ) &&
			$term instanceof WP_Term &&
			self::TAXONOMY === $term->taxonomy
		) {
			CatalogRevision::bump();
		}
	}

	/**
	 * Muestra una selección de categoría exclusiva.
	 *
	 * @param WP_Post $post Item editado.
	 * @return void
	 */
	public static function render_item_category_box( WP_Post $post ): void {
		$assigned = wp_get_object_terms( $post->ID, self::TAXONOMY, array( 'fields' => 'ids' ) );
		$selected = is_wp_error( $assigned ) || array() === $assigned ? 0 : (int) reset( $assigned );
		$terms    = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);
		?>
		<div id="taxonomy-<?php echo esc_attr( self::TAXONOMY ); ?>" class="categorydiv">
			<p><label><input type="radio" name="tax_input[<?php echo esc_attr( self::TAXONOMY ); ?>][]" value="0" <?php checked( 0, $selected ); ?>> <?php echo esc_html__( 'Sin categoría', 'vicunav-restaurante' ); ?></label></p>
			<?php if ( ! is_wp_error( $terms ) ) : ?>
				<?php foreach ( $terms as $term ) : ?>
					<p><label><input type="radio" name="tax_input[<?php echo esc_attr( self::TAXONOMY ); ?>][]" value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php checked( $term->term_id, $selected ); ?>> <?php echo esc_html( $term->name ); ?></label></p>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
