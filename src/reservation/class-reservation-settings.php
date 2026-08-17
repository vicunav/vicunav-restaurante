<?php
/**
 * Configuración autoritativa de reservas.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Reservation;

use DateTimeZone;
use Vicu\Core\Settings;

/**
 * Conserva horarios y capacidad fuera de theme y contenido de demostración.
 */
final class ReservationSettings {
	public const OPTION_NAME   = 'vicu_restaurante_reservation_settings';
	public const REVISION_NAME = 'vicu_restaurante_reservation_settings_revision';

	private const GROUP = 'vicu_restaurante_reservation_settings';
	private const PAGE  = 'vicu_restaurante_reservation_settings';

	/**
	 * Evita registrar hooks duplicados.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/** Registra Settings API y control de revisión. */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'admin_init', array( self::class, 'register_admin' ) );
		add_action( 'updated_option', array( self::class, 'option_updated' ), 10, 3 );
		add_action( 'added_option', array( self::class, 'option_added' ), 10, 2 );
		self::$hooks_registered = true;
	}

	/** Registra el tab y todos los campos estructurados. */
	public static function register_admin(): void {
		Settings::register_tab( 'reservas', __( 'Reservas', 'vicunav-restaurante' ), array( self::class, 'render_tab' ), 'manage_vicu_restaurant_reservations' );
		register_setting(
			self::GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
		add_settings_section( 'vicu_restaurante_reservations', __( 'Reglas de reservas', 'vicunav-restaurante' ), array( self::class, 'render_description' ), self::PAGE );

		foreach ( self::fields() as $key => $label ) {
			add_settings_field(
				'vicu_restaurante_reservation_' . $key,
				$label,
				array( self::class, 'render_field' ),
				self::PAGE,
				'vicu_restaurante_reservations',
				array(
					'key'       => $key,
					'label_for' => 'vicu_restaurante_reservation_' . $key,
				)
			);
		}
	}

	/**
	 * Devuelve ajustes completos con defaults técnicos.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$value = get_option( self::OPTION_NAME, array() );

		return array_replace( self::defaults(), is_array( $value ) ? $value : array() );
	}

	/**
	 * Devuelve la revisión monotónica de la configuración.
	 *
	 * @return int
	 */
	public static function revision(): int {
		return max( 1, (int) get_option( self::REVISION_NAME, 1 ) );
	}

	/**
	 * Valida la configuración completa y conserva valores previos ante errores.
	 *
	 * @param mixed $input Entrada de Settings API.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$current  = self::get();
		$timezone = is_scalar( $input['timezone'] ?? null ) ? trim( sanitize_text_field( wp_unslash( (string) $input['timezone'] ) ) ) : '';

		if ( ! in_array( $timezone, DateTimeZone::listIdentifiers(), true ) ) {
			add_settings_error( self::OPTION_NAME, 'vicu_restaurante_invalid_reservation_timezone', __( 'La zona horaria debe ser un identificador IANA.', 'vicunav-restaurante' ) );
			$timezone = $current['timezone'];
		}

		$weekly = self::structured_input( $input['weekly_schedule'] ?? array() );
		$weekly = self::sanitize_weekly( $weekly );

		if ( null === $weekly ) {
			add_settings_error( self::OPTION_NAME, 'vicu_restaurante_invalid_weekly_schedule', __( 'El horario semanal no es válido.', 'vicunav-restaurante' ) );
			$weekly = $current['weekly_schedule'];
		}

		$exceptions = self::sanitize_exceptions( self::structured_input( $input['exceptions'] ?? array() ) );

		if ( null === $exceptions ) {
			add_settings_error( self::OPTION_NAME, 'vicu_restaurante_invalid_reservation_exceptions', __( 'Las excepciones de horario no son válidas.', 'vicunav-restaurante' ) );
			$exceptions = $current['exceptions'];
		}

		$closures = self::sanitize_closures( self::structured_input( $input['recurring_closures'] ?? array() ) );

		if ( null === $closures ) {
			add_settings_error( self::OPTION_NAME, 'vicu_restaurante_invalid_reservation_closures', __( 'Los cierres recurrentes no son válidos.', 'vicunav-restaurante' ) );
			$closures = $current['recurring_closures'];
		}

		$integers = array(
			'interval_minutes'      => array( 5, 240 ),
			'duration_minutes'      => array( 15, 720 ),
			'capacity'              => array( 1, 10000 ),
			'min_party_size'        => array( 1, 1000 ),
			'max_party_size'        => array( 1, 1000 ),
			'min_notice_minutes'    => array( 0, 525600 ),
			'limited_threshold_bps' => array( 0, 10000 ),
		);
		$result   = array(
			'timezone'           => $timezone,
			'weekly_schedule'    => $weekly,
			'exceptions'         => $exceptions,
			'recurring_closures' => $closures,
		);

		foreach ( $integers as $key => $bounds ) {
			$value = self::integer( $input[ $key ] ?? null, $bounds[0], $bounds[1] );

			if ( null === $value ) {
				add_settings_error( self::OPTION_NAME, 'vicu_restaurante_invalid_' . $key, __( 'Una regla numérica de reservas no es válida.', 'vicunav-restaurante' ) );
				$value = $current[ $key ];
			}

			$result[ $key ] = $value;
		}

		if ( $result['max_party_size'] < $result['min_party_size'] ) {
			$result['min_party_size'] = $current['min_party_size'];
			$result['max_party_size'] = $current['max_party_size'];
			add_settings_error( self::OPTION_NAME, 'vicu_restaurante_invalid_party_range', __( 'El tamaño máximo debe ser mayor o igual al mínimo.', 'vicunav-restaurante' ) );
		}

		$result['auto_confirm'] = self::boolean( $input['auto_confirm'] ?? false );

		return $result;
	}

	/** Renderiza el formulario del tab de reservas. */
	public static function render_tab(): void {
		?>
		<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post"><?php settings_fields( self::GROUP ); ?><?php do_settings_sections( self::PAGE ); ?><?php submit_button(); ?></form>
		<?php
	}

	/** Explica la semántica de solapamiento. */
	public static function render_description(): void {
		echo '<p>' . esc_html__( 'Los slots se calculan en la zona IANA y la capacidad se bloquea por cada intervalo solapado.', 'vicunav-restaurante' ) . '</p>';
	}

	/**
	 * Renderiza un campo según su tipo.
	 *
	 * @param array<string, string> $args Argumentos del campo.
	 */
	public static function render_field( array $args ): void {
		$key      = $args['key'];
		$settings = self::get();
		$id       = 'vicu_restaurante_reservation_' . $key;
		$name     = self::OPTION_NAME . '[' . $key . ']';

		if ( in_array( $key, array( 'weekly_schedule', 'exceptions', 'recurring_closures' ), true ) ) {
			printf( '<textarea class="large-text code" rows="8" id="%1$s" name="%2$s">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) wp_json_encode( $settings[ $key ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) );
			return;
		}

		if ( 'auto_confirm' === $key ) {
			printf( '<input type="hidden" name="%1$s" value="0"><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s>', esc_attr( $name ), esc_attr( $id ), checked( true, $settings[ $key ], false ) );
			return;
		}

		if ( 'timezone' === $key ) {
			printf( '<input class="regular-text" id="%1$s" name="%2$s" value="%3$s" required>', esc_attr( $id ), esc_attr( $name ), esc_attr( $settings[ $key ] ) );
			return;
		}

		printf( '<input type="number" id="%1$s" name="%2$s" value="%3$d" required>', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $settings[ $key ] ) );
	}

	/**
	 * Aumenta la revisión al cambiar los ajustes.
	 *
	 * @param string $option    Nombre de opción.
	 * @param mixed  $old_value Valor anterior.
	 * @param mixed  $value     Valor nuevo.
	 */
	public static function option_updated( string $option, mixed $old_value, mixed $value ): void {
		if ( self::OPTION_NAME === $option && $old_value !== $value ) {
			self::bump_revision();
		}
	}

	/**
	 * Inicializa la siguiente revisión al crear los ajustes.
	 *
	 * @param string $option Nombre de opción.
	 * @param mixed  $value  Valor nuevo.
	 */
	public static function option_added( string $option, mixed $value ): void {
		unset( $value );

		if ( self::OPTION_NAME === $option ) {
			self::bump_revision();
		}
	}

	/**
	 * Define defaults técnicos sin horarios ni contenido Bonasera.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaults(): array {
		return array(
			'timezone'              => 'UTC',
			'weekly_schedule'       => array_fill_keys( self::days(), array() ),
			'exceptions'            => array(),
			'recurring_closures'    => array(),
			'interval_minutes'      => 30,
			'duration_minutes'      => 90,
			'capacity'              => 40,
			'min_party_size'        => 1,
			'max_party_size'        => 12,
			'min_notice_minutes'    => 120,
			'limited_threshold_bps' => 2500,
			'auto_confirm'          => false,
		);
	}

	/**
	 * Define etiquetas de Settings API.
	 *
	 * @return array<string, string>
	 */
	private static function fields(): array {
		return array(
			'timezone'              => __( 'Zona horaria IANA', 'vicunav-restaurante' ),
			'weekly_schedule'       => __( 'Horario semanal (JSON)', 'vicunav-restaurante' ),
			'exceptions'            => __( 'Excepciones por fecha (JSON)', 'vicunav-restaurante' ),
			'recurring_closures'    => __( 'Cierres recurrentes (JSON)', 'vicunav-restaurante' ),
			'interval_minutes'      => __( 'Intervalo de slots (minutos)', 'vicunav-restaurante' ),
			'duration_minutes'      => __( 'Duración (minutos)', 'vicunav-restaurante' ),
			'capacity'              => __( 'Capacidad por intervalo', 'vicunav-restaurante' ),
			'min_party_size'        => __( 'Tamaño mínimo de grupo', 'vicunav-restaurante' ),
			'max_party_size'        => __( 'Tamaño máximo de grupo', 'vicunav-restaurante' ),
			'min_notice_minutes'    => __( 'Aviso mínimo (minutos)', 'vicunav-restaurante' ),
			'limited_threshold_bps' => __( 'Umbral limitado (puntos base)', 'vicunav-restaurante' ),
			'auto_confirm'          => __( 'Confirmación automática', 'vicunav-restaurante' ),
		);
	}

	/**
	 * Devuelve las claves ISO de días aceptadas.
	 *
	 * @return string[]
	 */
	private static function days(): array {
		return array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
	}

	/**
	 * Decodifica JSON de textarea sin aceptar objetos arbitrarios.
	 *
	 * @param mixed $value Entrada.
	 * @return mixed
	 */
	private static function structured_input( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			$value = json_decode( wp_unslash( $value ), true );
		}

		return $value;
	}

	/**
	 * Valida el mapa semanal completo.
	 *
	 * @param mixed $value Entrada.
	 * @return array<string, array<int, array{opens_at: string, closes_at: string}>>|null
	 */
	private static function sanitize_weekly( mixed $value ): ?array {
		if ( ! is_array( $value ) || array_keys( $value ) !== self::days() ) {
			return null;
		}

		$result = array();

		foreach ( self::days() as $day ) {
			$periods = self::sanitize_periods( $value[ $day ] );

			if ( null === $periods ) {
				return null;
			}

			$result[ $day ] = $periods;
		}

		return $result;
	}

	/**
	 * Valida excepciones únicas por fecha.
	 *
	 * @param mixed $value Entrada.
	 * @return array<int, array{date: string, closed: bool, periods: array<int, array{opens_at: string, closes_at: string}>}>|null
	 */
	private static function sanitize_exceptions( mixed $value ): ?array {
		if ( ! is_array( $value ) || 366 < count( $value ) ) {
			return null;
		}

		$result = array();
		$seen   = array();

		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || ! self::valid_date( $item['date'] ?? null ) || isset( $seen[ $item['date'] ] ) ) {
				return null;
			}

			$closed  = self::boolean( $item['closed'] ?? false );
			$periods = $closed ? array() : self::sanitize_periods( $item['periods'] ?? array() );

			if ( null === $periods ) {
				return null;
			}

			$seen[ $item['date'] ] = true;
			$result[]              = array(
				'date'    => $item['date'],
				'closed'  => $closed,
				'periods' => $periods,
			);
		}

		return $result;
	}

	/**
	 * Valida cierres recurrentes MM-DD.
	 *
	 * @param mixed $value Entrada.
	 * @return string[]|null
	 */
	private static function sanitize_closures( mixed $value ): ?array {
		if ( ! is_array( $value ) || 366 < count( $value ) ) {
			return null;
		}

		$result = array();

		foreach ( $value as $closure ) {
			if ( ! is_string( $closure ) || 1 !== preg_match( '/^\d{2}-\d{2}$/', $closure ) ) {
				return null;
			}

			list( $month, $day ) = array_map( 'intval', explode( '-', $closure ) );

			if ( ! checkdate( $month, $day, 2000 ) ) {
				return null;
			}

			$result[] = $closure;
		}

		return array_values( array_unique( $result ) );
	}

	/**
	 * Valida y ordena periodos no solapados.
	 *
	 * @param mixed $value Entrada.
	 * @return array<int, array{opens_at: string, closes_at: string}>|null
	 */
	private static function sanitize_periods( mixed $value ): ?array {
		if ( ! is_array( $value ) || 8 < count( $value ) ) {
			return null;
		}

		$result = array();

		foreach ( $value as $period ) {
			if ( ! is_array( $period ) || ! self::valid_time( $period['opens_at'] ?? null ) || ! self::valid_time( $period['closes_at'] ?? null ) || $period['closes_at'] <= $period['opens_at'] ) {
				return null;
			}

			$result[] = array(
				'opens_at'  => $period['opens_at'],
				'closes_at' => $period['closes_at'],
			);
		}

		usort( $result, static fn( array $left, array $right ): int => $left['opens_at'] <=> $right['opens_at'] );

		$result_count = count( $result );
		for ( $index = 1; $index < $result_count; ++$index ) {
			if ( $result[ $index ]['opens_at'] < $result[ $index - 1 ]['closes_at'] ) {
				return null;
			}
		}

		return $result;
	}

	/**
	 * Valida una fecha de calendario exacta.
	 *
	 * @param mixed $value Entrada.
	 * @return bool
	 */
	private static function valid_date( mixed $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
			return false;
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}

	/**
	 * Valida una hora HH:mm.
	 *
	 * @param mixed $value Entrada.
	 * @return bool
	 */
	private static function valid_time( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value );
	}

	/**
	 * Normaliza un entero acotado.
	 *
	 * @param mixed $value Entrada.
	 * @param int   $min   Mínimo.
	 * @param int   $max   Máximo.
	 * @return int|null
	 */
	private static function integer( mixed $value, int $min, int $max ): ?int {
		if ( ! is_scalar( $value ) || is_float( $value ) || ! is_numeric( $value ) || trim( (string) $value ) !== (string) (int) $value ) {
			return null;
		}

		$value = (int) $value;

		return $min <= $value && $max >= $value ? $value : null;
	}

	/**
	 * Normaliza un booleano de formulario.
	 *
	 * @param mixed $value Entrada.
	 * @return bool
	 */
	private static function boolean( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value;
	}

	/** Incrementa la revisión persistida. */
	private static function bump_revision(): void {
		update_option( self::REVISION_NAME, (string) ( self::revision() + 1 ), false );
	}
}
