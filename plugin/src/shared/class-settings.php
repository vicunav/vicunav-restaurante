<?php
/**
 * Acceso a los ajustes generales del sitio.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * Expone lecturas estables sobre la Options API de WordPress.
 */
class Settings {
	/**
	 * Option propietario de las capacidades base (Vicu\Restaurante\Shared).
	 *
	 * @var string
	 */
	private const OPTION_NAME = 'vicu_core_settings';

	/**
	 * Pestañas registradas para la página administrativa.
	 *
	 * @var array<string, array{label: string, render_callback: callable, capability: string}>
	 */
	private static array $tabs = array();

	/**
	 * Indica si los hooks administrativos ya se registraron.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	// El nombre $default pertenece a la firma del contrato público.
	// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound
	/**
	 * Lee una clave compartida y conserva la diferencia entre ausencia y vacío.
	 *
	 * @param string $key     Clave exacta que se consultará.
	 * @param mixed  $default Valor devuelto cuando la clave no existe.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$settings = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $settings ) || ! array_key_exists( $key, $settings ) ) {
			return $default;
		}

		return $settings[ $key ];
	}
	// phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound

	/**
	 * Registra o reemplaza una pestaña administrativa.
	 *
	 * @param string   $slug            Identificador de la pestaña.
	 * @param string   $label           Etiqueta visible.
	 * @param callable $render_callback Callback sin argumentos que renderiza el contenido.
	 * @param string   $capability      Capability necesaria para acceder.
	 * @return void
	 */
	public static function register_tab(
		string $slug,
		string $label,
		callable $render_callback,
		string $capability = 'manage_options'
	): void {
		$normalized_slug = sanitize_key( $slug );

		if ( '' === $normalized_slug ) {
			return;
		}

		self::$tabs[ $normalized_slug ] = array(
			'label'           => $label,
			'render_callback' => $render_callback,
			'capability'      => $capability,
		);
	}

	/**
	 * Registra la pestaña general y los hooks administrativos.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		self::register_tab(
			'general',
			__( 'General', 'vicunav-restaurante' ),
			array( self::class, 'render_general_tab' )
		);

		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		self::$hooks_registered = true;
	}

	/**
	 * Registra el menú superior Vicunav.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'Vicunav', 'vicunav-restaurante' ),
			__( 'Vicunav', 'vicunav-restaurante' ),
			'manage_options',
			'vicunav',
			array( self::class, 'render_page' ),
			'dashicons-admin-generic',
			58
		);
	}

	/**
	 * Registra el option y los campos de contacto con Settings API.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			'vicu_core_settings',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'vicu_core_contact',
			__( 'Información de contacto', 'vicunav-restaurante' ),
			array( self::class, 'render_contact_description' ),
			'vicu_core_general'
		);

		self::add_contact_field( 'phone', __( 'Teléfono', 'vicunav-restaurante' ), 'text' );
		self::add_contact_field( 'address', __( 'Dirección', 'vicunav-restaurante' ), 'textarea' );
		self::add_contact_field( 'business_hours', __( 'Horario de atención', 'vicunav-restaurante' ), 'textarea' );
	}

	/**
	 * Sanitiza únicamente las claves compartidas aprobadas.
	 *
	 * @internal
	 *
	 * @param mixed $input Valor recibido por Settings API.
	 * @return array<string, string>
	 */
	public static function sanitize_settings( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$sanitized = array();

		if ( array_key_exists( 'phone', $input ) ) {
			$phone              = self::scalar_to_string( $input['phone'] );
			$sanitized['phone'] = (string) preg_replace(
				'/[^0-9+(). \-]/',
				'',
				sanitize_text_field( wp_unslash( $phone ) )
			);
		}

		foreach ( array( 'address', 'business_hours' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$value             = self::scalar_to_string( $input[ $key ] );
				$sanitized[ $key ] = sanitize_textarea_field( wp_unslash( $value ) );
			}
		}

		return $sanitized;
	}

	/**
	 * Renderiza la página y solo ejecuta callbacks autorizadas.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public static function render_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Seleccionar una pestaña no modifica datos.
		$requested_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'general' ) );
		$current_tab   = isset( self::$tabs[ $requested_tab ] ) ? $requested_tab : 'general';
		$tab           = self::$tabs[ $current_tab ] ?? null;

		if ( null === $tab || ! current_user_can( $tab['capability'] ) ) {
			wp_die(
				esc_html__( 'No tienes permisos para acceder a esta pestaña.', 'vicunav-restaurante' ),
				'',
				array( 'response' => 403 )
			);
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Vicunav', 'vicunav-restaurante' ); ?></h1>
			<?php settings_errors(); ?>
			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Secciones de ajustes de Vicunav', 'vicunav-restaurante' ); ?>">
				<?php foreach ( self::$tabs as $slug => $registered_tab ) : ?>
					<?php if ( current_user_can( $registered_tab['capability'] ) ) : ?>
						<a class="nav-tab <?php echo $slug === $current_tab ? 'nav-tab-active' : ''; ?>"
							href="<?php echo esc_url( self::get_tab_url( $slug ) ); ?>">
							<?php echo esc_html( $registered_tab['label'] ); ?>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</nav>
			<?php call_user_func( $tab['render_callback'] ); ?>
		</div>
		<?php
	}

	/**
	 * Renderiza el formulario de la pestaña general.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public static function render_general_tab(): void {
		?>
		<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
			<?php
			settings_fields( 'vicu_core_settings' );
			do_settings_sections( 'vicu_core_general' );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Explica el alcance de los campos generales.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public static function render_contact_description(): void {
		echo '<p>' . esc_html__( 'Datos compartidos que pueden consumir los themes y plugins Vicunav.', 'vicunav-restaurante' ) . '</p>';
	}

	/**
	 * Renderiza un campo registrado por la pestaña general.
	 *
	 * @internal
	 *
	 * @param array<string, string> $args Clave y tipo de control.
	 * @return void
	 */
	public static function render_field( array $args ): void {
		$key   = sanitize_key( $args['key'] ?? '' );
		$type  = $args['type'] ?? 'text';
		$value = self::get( $key, '' );
		$value = is_scalar( $value ) ? (string) $value : '';

		if ( 'textarea' === $type ) {
			printf(
				'<textarea class="large-text" rows="4" id="%1$s" name="vicu_core_settings[%1$s]">%2$s</textarea>',
				esc_attr( $key ),
				esc_textarea( $value )
			);
			return;
		}

		printf(
			'<input class="regular-text" type="text" id="%1$s" name="vicu_core_settings[%1$s]" value="%2$s">',
			esc_attr( $key ),
			esc_attr( $value )
		);
	}

	/**
	 * Registra un campo de contacto en la sección general.
	 *
	 * @param string $key   Clave persistida.
	 * @param string $label Etiqueta visible.
	 * @param string $type  Tipo de control.
	 * @return void
	 */
	private static function add_contact_field( string $key, string $label, string $type ): void {
		add_settings_field(
			'vicu_core_' . $key,
			$label,
			array( self::class, 'render_field' ),
			'vicu_core_general',
			'vicu_core_contact',
			array(
				'key'       => $key,
				'type'      => $type,
				'label_for' => $key,
			)
		);
	}

	/**
	 * Convierte únicamente valores escalares a texto.
	 *
	 * @param mixed $value Valor que se normalizará.
	 * @return string
	 */
	private static function scalar_to_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Construye la URL administrativa de una pestaña.
	 *
	 * @param string $slug Slug normalizado de la pestaña.
	 * @return string
	 */
	private static function get_tab_url( string $slug ): string {
		return add_query_arg(
			array(
				'page' => 'vicunav',
				'tab'  => $slug,
			),
			admin_url( 'admin.php' )
		);
	}
}
