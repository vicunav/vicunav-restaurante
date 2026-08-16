<?php
/**
 * Administración nativa de zonas y descuentos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Admin;

use Vicu\Restaurante\Commerce\DeliveryZoneService;
use Vicu\Restaurante\Commerce\DiscountService;

/**
 * Expone formularios operativos sin depender de ACF.
 */
final class CommerceAdmin {
	private const ZONES_PAGE     = 'vicu-restaurante-delivery-zones';
	private const DISCOUNTS_PAGE = 'vicu-restaurante-discounts';

	/**
	 * Evita hooks duplicados.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Enlaza páginas y handlers protegidos.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'admin_menu', array( self::class, 'register_pages' ), 20 );
		add_action( 'admin_post_vicu_restaurante_save_delivery_zone', array( self::class, 'save_zone' ) );
		add_action( 'admin_post_vicu_restaurante_save_discount', array( self::class, 'save_discount' ) );
		self::$hooks_registered = true;
	}

	/**
	 * Registra páginas con capabilities separadas.
	 *
	 * @return void
	 */
	public static function register_pages(): void {
		add_submenu_page( 'vicunav', __( 'Zonas de entrega', 'vicunav-restaurante' ), __( 'Delivery', 'vicunav-restaurante' ), 'manage_vicu_restaurant_delivery', self::ZONES_PAGE, array( self::class, 'render_zones' ) );
		add_submenu_page( 'vicunav', __( 'Descuentos', 'vicunav-restaurante' ), __( 'Descuentos', 'vicunav-restaurante' ), 'manage_vicu_restaurant_discounts', self::DISCOUNTS_PAGE, array( self::class, 'render_discounts' ) );
	}

	/**
	 * Renderiza zonas activables, nunca borrables.
	 *
	 * @return void
	 */
	public static function render_zones(): void {
		self::require_capability( 'manage_vicu_restaurant_delivery' );
		?>
		<div class="wrap"><h1><?php echo esc_html__( 'Zonas de entrega', 'vicunav-restaurante' ); ?></h1>
			<p><?php echo esc_html__( 'Desactiva una zona para impedir nuevas selecciones sin alterar pedidos históricos.', 'vicunav-restaurante' ); ?></p>
			<?php foreach ( DeliveryZoneService::all() as $zone ) : ?>
				<?php self::render_zone_form( $zone ); ?>
			<?php endforeach; ?>
			<h2><?php echo esc_html__( 'Añadir zona', 'vicunav-restaurante' ); ?></h2>
			<?php self::render_zone_form( null ); ?>
		</div>
		<?php
	}

	/**
	 * Renderiza códigos y consumo actual.
	 *
	 * @return void
	 */
	public static function render_discounts(): void {
		self::require_capability( 'manage_vicu_restaurant_discounts' );
		?>
		<div class="wrap"><h1><?php echo esc_html__( 'Descuentos', 'vicunav-restaurante' ); ?></h1>
			<p><?php echo esc_html__( 'Los límites se consumen únicamente durante checkout.', 'vicunav-restaurante' ); ?></p>
			<?php foreach ( DiscountService::all() as $discount ) : ?>
				<?php self::render_discount_form( $discount ); ?>
			<?php endforeach; ?>
			<h2><?php echo esc_html__( 'Añadir código', 'vicunav-restaurante' ); ?></h2>
			<?php self::render_discount_form( null ); ?>
		</div>
		<?php
	}

	/**
	 * Guarda una zona completa mediante CAS.
	 *
	 * @return void
	 */
	public static function save_zone(): void {
		self::require_capability( 'manage_vicu_restaurant_delivery' );
		check_admin_referer( 'vicu_restaurante_save_delivery_zone' );

		$public_id = self::request_text( 'public_id' );
		$current   = '' === $public_id ? null : DeliveryZoneService::find( $public_id );

		if ( '' !== $public_id && null === $current ) {
			self::redirect( self::ZONES_PAGE, 'vicu_restaurante_not_found' );
		}

		$input  = array(
			'name'            => self::request_text( 'name' ),
			'active'          => '1' === self::request_text( 'active' ),
			'fee_minor'       => self::request_text( 'fee_minor' ),
			'eta_min_minutes' => self::request_text( 'eta_min_minutes' ),
			'eta_max_minutes' => self::request_text( 'eta_max_minutes' ),
			'display_order'   => self::request_text( 'display_order' ),
		);
		$result = null === $current
			? DeliveryZoneService::create( $input )
			: DeliveryZoneService::update( $public_id, absint( self::request_text( 'expected_revision' ) ), $input );

		self::redirect( self::ZONES_PAGE, is_wp_error( $result ) ? $result->get_error_code() : 'saved' );
	}

	/**
	 * Guarda reglas de descuento sin tocar usos consumidos.
	 *
	 * @return void
	 */
	public static function save_discount(): void {
		self::require_capability( 'manage_vicu_restaurant_discounts' );
		check_admin_referer( 'vicu_restaurante_save_discount' );

		$public_id = self::request_text( 'public_id' );
		$current   = '' === $public_id ? null : DiscountService::find( $public_id );

		if ( '' !== $public_id && null === $current ) {
			self::redirect( self::DISCOUNTS_PAGE, 'vicu_restaurante_not_found' );
		}

		$input  = array(
			'code'                   => self::request_text( 'code' ),
			'type'                   => self::request_text( 'type' ),
			'value'                  => self::request_text( 'value' ),
			'active'                 => '1' === self::request_text( 'active' ),
			'valid_from'             => self::request_text( 'valid_from' ),
			'valid_until'            => self::request_text( 'valid_until' ),
			'minimum_subtotal_minor' => self::request_text( 'minimum_subtotal_minor' ),
			'max_uses'               => self::request_text( 'max_uses' ),
		);
		$result = null === $current
			? DiscountService::create( $input )
			: DiscountService::update( $public_id, absint( self::request_text( 'expected_revision' ) ), $input );

		self::redirect( self::DISCOUNTS_PAGE, is_wp_error( $result ) ? $result->get_error_code() : 'saved' );
	}

	/**
	 * Renderiza formulario de zona.
	 *
	 * @param array<string, mixed>|null $zone Zona o creación.
	 * @return void
	 */
	private static function render_zone_form( ?array $zone ): void {
		$zone = $zone ?? array(
			'public_id'       => '',
			'name'            => '',
			'active'          => false,
			'fee_minor'       => 0,
			'eta_min_minutes' => 0,
			'eta_max_minutes' => 0,
			'display_order'   => 0,
			'revision'        => 0,
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1rem 0;padding:1rem;background:#fff">
			<input type="hidden" name="action" value="vicu_restaurante_save_delivery_zone"><input type="hidden" name="public_id" value="<?php echo esc_attr( $zone['public_id'] ); ?>"><input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) $zone['revision'] ); ?>">
			<?php wp_nonce_field( 'vicu_restaurante_save_delivery_zone' ); ?>
			<label><?php echo esc_html__( 'Nombre', 'vicunav-restaurante' ); ?> <input name="name" value="<?php echo esc_attr( $zone['name'] ); ?>" required></label>
			<label><?php echo esc_html__( 'Tarifa en unidad menor', 'vicunav-restaurante' ); ?> <input type="number" min="0" name="fee_minor" value="<?php echo esc_attr( (string) $zone['fee_minor'] ); ?>" required></label>
			<label><?php echo esc_html__( 'ETA mínimo', 'vicunav-restaurante' ); ?> <input type="number" min="0" name="eta_min_minutes" value="<?php echo esc_attr( (string) $zone['eta_min_minutes'] ); ?>" required></label>
			<label><?php echo esc_html__( 'ETA máximo', 'vicunav-restaurante' ); ?> <input type="number" min="0" name="eta_max_minutes" value="<?php echo esc_attr( (string) $zone['eta_max_minutes'] ); ?>" required></label>
			<label><?php echo esc_html__( 'Orden', 'vicunav-restaurante' ); ?> <input type="number" min="0" name="display_order" value="<?php echo esc_attr( (string) $zone['display_order'] ); ?>" required></label>
			<label><input type="checkbox" name="active" value="1" <?php checked( $zone['active'] ); ?>> <?php echo esc_html__( 'Activa', 'vicunav-restaurante' ); ?></label>
			<button class="button button-primary" type="submit"><?php echo esc_html__( 'Guardar', 'vicunav-restaurante' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Renderiza formulario de descuento.
	 *
	 * @param array<string, mixed>|null $discount Regla o creación.
	 * @return void
	 */
	private static function render_discount_form( ?array $discount ): void {
		$discount = $discount ?? array(
			'public_id'              => '',
			'code'                   => '',
			'type'                   => 'percent',
			'value'                  => 0,
			'active'                 => false,
			'valid_from'             => '',
			'valid_until'            => '',
			'minimum_subtotal_minor' => 0,
			'max_uses'               => '',
			'uses_count'             => 0,
			'revision'               => 0,
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1rem 0;padding:1rem;background:#fff">
			<input type="hidden" name="action" value="vicu_restaurante_save_discount"><input type="hidden" name="public_id" value="<?php echo esc_attr( $discount['public_id'] ); ?>"><input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) $discount['revision'] ); ?>">
			<?php wp_nonce_field( 'vicu_restaurante_save_discount' ); ?>
			<label><?php echo esc_html__( 'Código', 'vicunav-restaurante' ); ?> <input name="code" value="<?php echo esc_attr( $discount['code'] ); ?>" required></label>
			<label><?php echo esc_html__( 'Tipo', 'vicunav-restaurante' ); ?> <select name="type">
			<?php
			foreach ( DiscountService::TYPES as $type ) :
				?>
				<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $type, $discount['type'] ); ?>><?php echo esc_html( $type ); ?></option><?php endforeach; ?></select></label>
			<label><?php echo esc_html__( 'Valor', 'vicunav-restaurante' ); ?> <input type="number" min="1" name="value" value="<?php echo esc_attr( (string) $discount['value'] ); ?>" required></label>
			<label><?php echo esc_html__( 'Subtotal mínimo', 'vicunav-restaurante' ); ?> <input type="number" min="0" name="minimum_subtotal_minor" value="<?php echo esc_attr( (string) $discount['minimum_subtotal_minor'] ); ?>" required></label>
			<label><?php echo esc_html__( 'Válido desde UTC', 'vicunav-restaurante' ); ?> <input placeholder="YYYY-MM-DD HH:MM:SS" name="valid_from" value="<?php echo esc_attr( (string) $discount['valid_from'] ); ?>"></label>
			<label><?php echo esc_html__( 'Válido hasta UTC', 'vicunav-restaurante' ); ?> <input placeholder="YYYY-MM-DD HH:MM:SS" name="valid_until" value="<?php echo esc_attr( (string) $discount['valid_until'] ); ?>"></label>
			<label><?php echo esc_html__( 'Máximo de usos', 'vicunav-restaurante' ); ?> <input type="number" min="1" name="max_uses" value="<?php echo esc_attr( (string) $discount['max_uses'] ); ?>"></label>
			<span><?php echo esc_html( sprintf( /* translators: %d: usos consumidos. */ __( 'Usos: %d', 'vicunav-restaurante' ), $discount['uses_count'] ) ); ?></span>
			<label><input type="checkbox" name="active" value="1" <?php checked( $discount['active'] ); ?>> <?php echo esc_html__( 'Activo', 'vicunav-restaurante' ); ?></label>
			<button class="button button-primary" type="submit"><?php echo esc_html__( 'Guardar', 'vicunav-restaurante' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Lee texto escalar después del nonce.
	 *
	 * @param string $key Clave POST.
	 * @return string
	 */
	private static function request_text( string $key ): string {
		// Todos los callers verifican nonce antes de leer el formulario.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $value;
	}

	/**
	 * Exige capability antes de renderizar o mutar.
	 *
	 * @param string $capability Capability.
	 * @return void
	 */
	private static function require_capability( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'No autorizado.', 'vicunav-restaurante' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirige con un código no sensible.
	 *
	 * @param string $page   Página.
	 * @param string $status Resultado.
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
