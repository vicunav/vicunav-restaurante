<?php
/**
 * Edición de relaciones entre menú e ingredientes.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Admin;

use Vicu\Restaurante\Catalog\CatalogValidator;
use Vicu\Restaurante\Catalog\IngredientService;
use Vicu\Restaurante\Catalog\MenuIngredientService;
use Vicu\Restaurante\Menu\MenuItemPostType;
use WP_Post;

/**
 * Añade un panel nativo sin depender de ACF.
 */
final class MenuRelationsAdmin {
	private const NONCE_ACTION = 'vicu_restaurante_save_menu_relations';
	private const NONCE_FIELD  = 'vicu_restaurante_menu_relations_nonce';

	/**
	 * Evita hooks duplicados.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Enlaza panel y guardado.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'add_meta_boxes_' . MenuItemPostType::POST_TYPE, array( self::class, 'add_meta_box' ) );
		add_action( 'save_post_' . MenuItemPostType::POST_TYPE, array( self::class, 'save' ), 20, 2 );
		self::$hooks_registered = true;
	}

	/**
	 * Registra el panel de relaciones.
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		add_meta_box(
			'vicu-restaurante-menu-ingredients',
			__( 'Relaciones de ingredientes', 'vicunav-restaurante' ),
			array( self::class, 'render' ),
			MenuItemPostType::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Renderiza roles, orden y sustitución contra el catálogo único.
	 *
	 * @param WP_Post $post Item editado.
	 * @return void
	 */
	public static function render( WP_Post $post ): void {
		$ingredients = IngredientService::all();
		$relations   = array();

		foreach ( MenuIngredientService::for_menu_item( $post->ID ) as $relation ) {
			$relations[ $relation['ingredient_public_id'] ] = $relation;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		if ( array() === $ingredients ) {
			echo '<p>' . esc_html__( 'Crea ingredientes antes de definir relaciones.', 'vicunav-restaurante' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'Ingrediente', 'vicunav-restaurante' ); ?></th><th><?php echo esc_html__( 'Rol', 'vicunav-restaurante' ); ?></th><th><?php echo esc_html__( 'Orden', 'vicunav-restaurante' ); ?></th><th><?php echo esc_html__( 'Sustitución', 'vicunav-restaurante' ); ?></th></tr></thead><tbody>
		<?php foreach ( $ingredients as $ingredient ) : ?>
			<?php
			$relation = $relations[ $ingredient['public_id'] ] ?? array(
				'role'                   => '',
				'display_order'          => 0,
				'substitution_public_id' => '',
			);
			?>
			<tr><td><?php echo esc_html( $ingredient['name'] ); ?></td><td><select name="vicu_rest_ingredient_relations[<?php echo esc_attr( $ingredient['public_id'] ); ?>][role]"><option value=""><?php echo esc_html__( 'Sin relación', 'vicunav-restaurante' ); ?></option>
			<?php
			foreach ( CatalogValidator::RELATION_ROLES as $role ) :
				?>
				<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $role, $relation['role'] ); ?>><?php echo esc_html( $role ); ?></option><?php endforeach; ?></select></td>
			<td><input type="number" min="0" max="9999" name="vicu_rest_ingredient_relations[<?php echo esc_attr( $ingredient['public_id'] ); ?>][display_order]" value="<?php echo esc_attr( (string) $relation['display_order'] ); ?>"></td>
			<td><select name="vicu_rest_ingredient_relations[<?php echo esc_attr( $ingredient['public_id'] ); ?>][substitution_public_id]"><option value=""><?php echo esc_html__( 'Sin sustitución', 'vicunav-restaurante' ); ?></option>
			<?php
			foreach ( $ingredients as $substitution ) :
				?>
				<?php
				if ( $substitution['public_id'] !== $ingredient['public_id'] ) :
					?>
				<option value="<?php echo esc_attr( $substitution['public_id'] ); ?>" <?php selected( $substitution['public_id'], $relation['substitution_public_id'] ); ?>><?php echo esc_html( $substitution['name'] ); ?></option><?php endif; ?><?php endforeach; ?></select></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	/**
	 * Guarda el conjunto completo con nonce y capability.
	 *
	 * @param int     $post_id ID interno.
	 * @param WP_Post $post    Item guardado.
	 * @return void
	 */
	public static function save( int $post_id, WP_Post $post ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_scalar( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) )
			: '';

		if (
			MenuItemPostType::POST_TYPE !== $post->post_type ||
			wp_is_post_autosave( $post_id ) ||
			wp_is_post_revision( $post_id ) ||
			! current_user_can( 'manage_vicu_restaurant_catalog' ) ||
			'' === $nonce ||
			! wp_verify_nonce( $nonce, self::NONCE_ACTION )
		) {
			return;
		}

		// El nonce se verificó antes de leer la estructura completa del formulario.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$posted    = isset( $_POST['vicu_rest_ingredient_relations'] ) && is_array( $_POST['vicu_rest_ingredient_relations'] ) ? wp_unslash( $_POST['vicu_rest_ingredient_relations'] ) : array();
		$relations = array();

		foreach ( $posted as $ingredient_public_id => $relation ) {
			if ( ! is_array( $relation ) || '' === ( $relation['role'] ?? '' ) ) {
				continue;
			}

			$relations[] = array(
				'ingredient_public_id'   => sanitize_text_field( (string) $ingredient_public_id ),
				'role'                   => sanitize_key( (string) ( $relation['role'] ?? '' ) ),
				'display_order'          => absint( $relation['display_order'] ?? 0 ),
				'substitution_public_id' => sanitize_text_field( (string) ( $relation['substitution_public_id'] ?? '' ) ),
			);
		}

		MenuIngredientService::replace( $post_id, $relations );
	}
}
