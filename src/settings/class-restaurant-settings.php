<?php
/**
 * Ajustes autoritativos propios del vertical.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Settings;

use Vicu\Core\Settings;
use Vicu\Restaurante\Commerce\PricingRevision;

/**
 * Conserva moneda operativa fuera de los ajustes compartidos de core.
 */
final class RestaurantSettings {
	public const OPTION_NAME                      = 'vicu_restaurante_settings';
	public const DEFAULT_TAX_RATE_BPS             = 800;
	public const DEFAULT_TIP_RATES_BPS            = array( 0, 1000, 1500, 2000 );
	public const DEFAULT_CART_LIFETIME_HOURS      = 72;
	public const DEFAULT_PAYMENT_LIFETIME_MINUTES = 30;

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
		add_action( 'updated_option', array( self::class, 'option_updated' ), 10, 3 );
		add_action( 'added_option', array( self::class, 'option_added' ), 10, 2 );
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
		$settings = self::all();
		$currency = is_array( $settings ) ? self::sanitize_currency( $settings['currency'] ?? '' ) : '';

		return '' === $currency ? 'USD' : $currency;
	}

	/**
	 * Devuelve la tasa fiscal vigente en puntos base.
	 *
	 * @return int
	 */
	public static function tax_rate_bps(): int {
		$settings = self::all();
		$rate     = self::rate( $settings['tax_rate_bps'] ?? null );

		return null === $rate ? self::DEFAULT_TAX_RATE_BPS : $rate;
	}

	/**
	 * Devuelve opciones de propina, incluida siempre la opción cero.
	 *
	 * @return int[]
	 */
	public static function tip_rates_bps(): array {
		$settings = self::all();
		$rates    = self::sanitize_tip_rates( $settings['tip_rates_bps'] ?? array() );

		return array() === $rates ? self::DEFAULT_TIP_RATES_BPS : $rates;
	}

	/**
	 * Devuelve la vigencia de un carrito no convertido.
	 *
	 * @return int
	 */
	public static function cart_lifetime_hours(): int {
		$settings = self::all();
		$value    = self::bounded_integer( $settings['cart_lifetime_hours'] ?? null, 1, 720 );

		return null === $value ? self::DEFAULT_CART_LIFETIME_HOURS : $value;
	}

	/**
	 * Devuelve el vencimiento congelable de una solicitud de pago.
	 *
	 * @return int
	 */
	public static function payment_lifetime_minutes(): int {
		$settings = self::all();
		$value    = self::bounded_integer( $settings['payment_lifetime_minutes'] ?? null, 5, 1440 );

		return null === $value ? self::DEFAULT_PAYMENT_LIFETIME_MINUTES : $value;
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
				'default'           => array(
					'currency'                 => 'USD',
					'tax_rate_bps'             => self::DEFAULT_TAX_RATE_BPS,
					'tip_rates_bps'            => self::DEFAULT_TIP_RATES_BPS,
					'cart_lifetime_hours'      => self::DEFAULT_CART_LIFETIME_HOURS,
					'payment_lifetime_minutes' => self::DEFAULT_PAYMENT_LIFETIME_MINUTES,
				),
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

		add_settings_field(
			'vicu_restaurante_cart_lifetime_hours',
			__( 'Vigencia del carrito (horas)', 'vicunav-restaurante' ),
			array( self::class, 'render_cart_lifetime' ),
			self::PAGE,
			'vicu_restaurante_commerce',
			array( 'label_for' => 'vicu_restaurante_cart_lifetime_hours' )
		);

		add_settings_field(
			'vicu_restaurante_payment_lifetime_minutes',
			__( 'Vigencia del pago (minutos)', 'vicunav-restaurante' ),
			array( self::class, 'render_payment_lifetime' ),
			self::PAGE,
			'vicu_restaurante_commerce',
			array( 'label_for' => 'vicu_restaurante_payment_lifetime_minutes' )
		);

		add_settings_field(
			'vicu_restaurante_tax_rate_bps',
			__( 'Impuesto (puntos base)', 'vicunav-restaurante' ),
			array( self::class, 'render_tax_rate' ),
			self::PAGE,
			'vicu_restaurante_commerce',
			array( 'label_for' => 'vicu_restaurante_tax_rate_bps' )
		);

		add_settings_field(
			'vicu_restaurante_tip_rates_bps',
			__( 'Propinas (puntos base)', 'vicunav-restaurante' ),
			array( self::class, 'render_tip_rates' ),
			self::PAGE,
			'vicu_restaurante_commerce',
			array( 'label_for' => 'vicu_restaurante_tip_rates_bps' )
		);
	}

	/**
	 * Sanitiza el option completo.
	 *
	 * @param mixed $input Datos candidatos.
	 * @return array{currency: string, tax_rate_bps: int, tip_rates_bps: int[], cart_lifetime_hours: int, payment_lifetime_minutes: int}
	 */
	public static function sanitize( mixed $input ): array {
		$currency         = is_array( $input ) ? self::sanitize_currency( $input['currency'] ?? '' ) : '';
		$tax_rate         = is_array( $input ) ? self::rate( $input['tax_rate_bps'] ?? null ) : null;
		$tip_rates        = is_array( $input ) ? self::sanitize_tip_rates( $input['tip_rates_bps'] ?? array() ) : array();
		$lifetime         = is_array( $input ) ? self::bounded_integer( $input['cart_lifetime_hours'] ?? null, 1, 720 ) : null;
		$payment_lifetime = is_array( $input ) ? self::bounded_integer( $input['payment_lifetime_minutes'] ?? null, 5, 1440 ) : null;

		if ( '' === $currency ) {
			add_settings_error(
				self::OPTION_NAME,
				'vicu_restaurante_invalid_currency',
				__( 'La moneda debe usar tres letras ISO 4217.', 'vicunav-restaurante' )
			);
			$currency = self::currency();
		}

		if ( null === $tax_rate ) {
			add_settings_error( self::OPTION_NAME, 'vicu_restaurante_invalid_tax_rate', __( 'La tasa fiscal debe estar entre 0 y 10000 puntos base.', 'vicunav-restaurante' ) );
			$tax_rate = self::tax_rate_bps();
		}

		if ( array() === $tip_rates ) {
			add_settings_error( self::OPTION_NAME, 'vicu_restaurante_invalid_tip_rates', __( 'Las propinas deben ser puntos base entre 0 y 10000 e incluir cero.', 'vicunav-restaurante' ) );
			$tip_rates = self::tip_rates_bps();
		}

		if ( null === $lifetime ) {
			add_settings_error( self::OPTION_NAME, 'vicu_restaurante_invalid_cart_lifetime', __( 'La vigencia del carrito debe estar entre 1 y 720 horas.', 'vicunav-restaurante' ) );
			$lifetime = self::cart_lifetime_hours();
		}

		if ( null === $payment_lifetime ) {
			add_settings_error( self::OPTION_NAME, 'vicu_restaurante_invalid_payment_lifetime', __( 'La vigencia del pago debe estar entre 5 y 1440 minutos.', 'vicunav-restaurante' ) );
			$payment_lifetime = self::payment_lifetime_minutes();
		}

		return array(
			'currency'                 => $currency,
			'tax_rate_bps'             => $tax_rate,
			'tip_rates_bps'            => $tip_rates,
			'cart_lifetime_hours'      => $lifetime,
			'payment_lifetime_minutes' => $payment_lifetime,
		);
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
		echo '<p>' . esc_html__( 'La cotización, el carrito, los pedidos y pagos usan estas reglas autoritativas.', 'vicunav-restaurante' ) . '</p>';
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
	 * Renderiza la tasa fiscal en puntos base.
	 *
	 * @return void
	 */
	public static function render_tax_rate(): void {
		printf(
			'<input type="number" min="0" max="10000" id="vicu_restaurante_tax_rate_bps" name="%1$s[tax_rate_bps]" value="%2$d" required>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( (string) self::tax_rate_bps() )
		);
	}

	/**
	 * Renderiza tasas de propina como lista controlada.
	 *
	 * @return void
	 */
	public static function render_tip_rates(): void {
		printf(
			'<input class="regular-text" id="vicu_restaurante_tip_rates_bps" name="%1$s[tip_rates_bps]" value="%2$s" required><p class="description">%3$s</p>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( implode( ',', self::tip_rates_bps() ) ),
			esc_html__( 'Valores separados por coma. Debe incluir 0.', 'vicunav-restaurante' )
		);
	}

	/**
	 * Renderiza la vigencia operativa del carrito.
	 *
	 * @return void
	 */
	public static function render_cart_lifetime(): void {
		printf(
			'<input type="number" min="1" max="720" id="vicu_restaurante_cart_lifetime_hours" name="%1$s[cart_lifetime_hours]" value="%2$d" required>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( (string) self::cart_lifetime_hours() )
		);
	}

	/**
	 * Renderiza el vencimiento que se congelará al crear el pedido.
	 *
	 * @return void
	 */
	public static function render_payment_lifetime(): void {
		printf(
			'<input type="number" min="5" max="1440" id="vicu_restaurante_payment_lifetime_minutes" name="%1$s[payment_lifetime_minutes]" value="%2$d" required>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( (string) self::payment_lifetime_minutes() )
		);
	}

	/**
	 * Invalida pricing después de cambiar el option propietario.
	 *
	 * @param string $option    Nombre del option.
	 * @param mixed  $old_value Valor anterior.
	 * @param mixed  $value     Valor nuevo.
	 * @return void
	 */
	public static function option_updated( string $option, mixed $old_value, mixed $value ): void {
		if ( self::OPTION_NAME === $option && $old_value !== $value && false !== get_option( PricingRevision::OPTION_NAME, false ) ) {
			PricingRevision::bump();
		}
	}

	/**
	 * Invalida pricing al crear por primera vez el option propietario.
	 *
	 * @param string $option Nombre del option.
	 * @param mixed  $value  Valor nuevo.
	 * @return void
	 */
	public static function option_added( string $option, mixed $value ): void {
		unset( $value );

		if ( self::OPTION_NAME === $option && false !== get_option( PricingRevision::OPTION_NAME, false ) ) {
			PricingRevision::bump();
		}
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

	/**
	 * Lee el option sin imponer defaults prematuramente.
	 *
	 * @return array<string, mixed>
	 */
	private static function all(): array {
		$settings = get_option( self::OPTION_NAME, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Valida puntos base.
	 *
	 * @param mixed $value Valor candidato.
	 * @return int|null
	 */
	private static function rate( mixed $value ): ?int {
		if ( ! is_scalar( $value ) || is_float( $value ) || ! is_numeric( $value ) || trim( (string) $value ) !== (string) (int) $value ) {
			return null;
		}

		$rate = (int) $value;

		return 0 <= $rate && 10000 >= $rate ? $rate : null;
	}

	/**
	 * Valida un entero acotado sin aceptar coerciones parciales.
	 *
	 * @param mixed $value Valor candidato.
	 * @param int   $min   Límite inferior.
	 * @param int   $max   Límite superior.
	 * @return int|null
	 */
	private static function bounded_integer( mixed $value, int $min, int $max ): ?int {
		if ( ! is_scalar( $value ) || is_float( $value ) || ! is_numeric( $value ) || trim( (string) $value ) !== (string) (int) $value ) {
			return null;
		}

		$value = (int) $value;

		return $min <= $value && $max >= $value ? $value : null;
	}

	/**
	 * Normaliza una lista única de propinas e incluye cero.
	 *
	 * @param mixed $value Lista o CSV.
	 * @return int[]
	 */
	private static function sanitize_tip_rates( mixed $value ): array {
		$values = is_string( $value ) ? array_map( 'trim', explode( ',', $value ) ) : $value;

		if ( ! is_array( $values ) ) {
			return array();
		}

		$rates = array();

		foreach ( $values as $candidate ) {
			$rate = self::rate( $candidate );

			if ( null === $rate ) {
				return array();
			}

			$rates[] = $rate;
		}

		$rates = array_values( array_unique( $rates ) );
		sort( $rates, SORT_NUMERIC );

		return in_array( 0, $rates, true ) ? $rates : array();
	}
}
