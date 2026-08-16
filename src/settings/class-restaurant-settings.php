<?php
/**
 * Ajustes autoritativos propios del vertical.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Settings;

use Vicu\Core\Settings;

/**
 * Conserva moneda operativa fuera de los ajustes compartidos de core.
 */
final class RestaurantSettings {
	public const OPTION_NAME = 'vicu_restaurante_settings';

	private const GROUP = 'vicu_restaurante_settings';
	private const PAGE  = 'vicu_restaurante_settings';

	/**
	 * Evita hooks duplicados.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Registra pestaña y Settings API propias.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'admin_init', array( self::class, 'register_admin' ) );
		self::$hooks_registered = true;
	}

	/**
	 * Registra la pestaña cuando las APIs administrativas ya están disponibles.
	 *
	 * @return void
	 */
	public static function register_admin(): void {
		Settings::register_tab(
			'restaurante',
			__( 'Restaurante', 'vicunav-restaurante' ),
			array( self::class, 'render_tab' ),
			'manage_vicu_restaurant_settings'
		);
		self::register_settings();
	}

	/**
	 * Devuelve la moneda ISO 4217 vigente.
	 *
	 * @return string
	 */
	public static function currency(): string {
		$settings = get_option( self::OPTION_NAME, array() );
		$currency = is_array( $settings ) ? self::sanitize_currency( $settings['currency'] ?? '' ) : '';

		return '' === $currency ? 'USD' : $currency;
	}

	/**
	 * Registra el option y el campo inicial del vertical.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => array( 'currency' => 'USD' ),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'vicu_restaurante_commerce',
			__( 'Comercio', 'vicunav-restaurante' ),
			array( self::class, 'render_description' ),
			self::PAGE
		);

		add_settings_field(
			'vicu_restaurante_currency',
			__( 'Moneda operativa', 'vicunav-restaurante' ),
			array( self::class, 'render_currency' ),
			self::PAGE,
			'vicu_restaurante_commerce',
			array( 'label_for' => 'vicu_restaurante_currency' )
		);
	}

	/**
	 * Sanitiza el option completo.
	 *
	 * @param mixed $input Datos candidatos.
	 * @return array{currency: string}
	 */
	public static function sanitize( mixed $input ): array {
		$currency = is_array( $input ) ? self::sanitize_currency( $input['currency'] ?? '' ) : '';

		if ( '' === $currency ) {
			add_settings_error(
				self::OPTION_NAME,
				'vicu_restaurante_invalid_currency',
				__( 'La moneda debe usar tres letras ISO 4217.', 'vicunav-restaurante' )
			);
			$currency = self::currency();
		}

		return array( 'currency' => $currency );
	}

	/**
	 * Renderiza el formulario protegido por Settings API.
	 *
	 * @return void
	 */
	public static function render_tab(): void {
		?>
		<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
			<?php
			settings_fields( self::GROUP );
			do_settings_sections( self::PAGE );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Explica la autoridad del ajuste.
	 *
	 * @return void
	 */
	public static function render_description(): void {
		echo '<p>' . esc_html__( 'La cotización, el carrito, los pedidos y pagos usan esta moneda.', 'vicunav-restaurante' ) . '</p>';
	}

	/**
	 * Renderiza el campo ISO 4217.
	 *
	 * @return void
	 */
	public static function render_currency(): void {
		printf(
			'<input class="small-text" id="vicu_restaurante_currency" name="%1$s[currency]" value="%2$s" maxlength="3" required>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( self::currency() )
		);
	}

	/**
	 * Normaliza una moneda o devuelve vacío.
	 *
	 * @param mixed $value Valor candidato.
	 * @return string
	 */
	private static function sanitize_currency( mixed $value ): string {
		$currency = is_scalar( $value ) ? strtoupper( trim( sanitize_text_field( wp_unslash( (string) $value ) ) ) ) : '';

		return 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : '';
	}
}
