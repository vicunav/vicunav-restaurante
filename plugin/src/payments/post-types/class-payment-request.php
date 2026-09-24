<?php
/**
 * Tipo de contenido para solicitudes de pago.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Payments\PostTypes;

defined( 'ABSPATH' ) || exit;

use Vicu\Restaurante\Shared\PostType;
use Vicu\Restaurante\Payments\PaymentRequestState;
use Vicu\Restaurante\Payments\Rest\PaymentRequestController;

/**
 * Registra solicitudes privadas con REST administrativo protegido.
 */
final class PaymentRequest extends PostType {
	public const SLUG = 'vicu_payment_req';

	public const META_EXTERNAL_TYPE = 'vicu_external_type';

	public const META_EXTERNAL_ID = 'vicu_external_id';

	public const META_AMOUNT_MINOR = 'vicu_amount_minor';

	public const META_CURRENCY = 'vicu_currency';

	public const META_PROVIDER = 'vicu_payment_provider';

	public const META_STATE = 'vicu_payment_state';

	public const META_REVISION = 'vicu_payment_revision';

	public const META_EXPIRES_AT = 'vicu_expires_at';

	/**
	 * Devuelve el slug contractual del CPT.
	 *
	 * @return string
	 */
	protected function get_slug(): string {
		return self::SLUG;
	}

	/**
	 * Devuelve la configuración funcional y de permisos.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_args(): array {
		return array(
			'labels'                => array(
				'name'          => __( 'Solicitudes de pago', 'vicunav-restaurante' ),
				'singular_name' => __( 'Solicitud de pago', 'vicunav-restaurante' ),
				'add_new'       => __( 'Añadir nueva', 'vicunav-restaurante' ),
				'add_new_item'  => __( 'Añadir solicitud de pago', 'vicunav-restaurante' ),
				'edit_item'     => __( 'Editar solicitud de pago', 'vicunav-restaurante' ),
				'new_item'      => __( 'Nueva solicitud de pago', 'vicunav-restaurante' ),
				'view_item'     => __( 'Ver solicitud de pago', 'vicunav-restaurante' ),
				'search_items'  => __( 'Buscar solicitudes de pago', 'vicunav-restaurante' ),
				'not_found'     => __( 'No se encontraron solicitudes de pago.', 'vicunav-restaurante' ),
				'menu_name'     => __( 'Pagos', 'vicunav-restaurante' ),
			),
			'public'                => false,
			'publicly_queryable'    => false,
			'exclude_from_search'   => true,
			'show_ui'               => true,
			'show_in_menu'          => 'vicunav',
			'show_in_rest'          => true,
			'rest_base'             => 'vicu-payment-requests',
			'rest_controller_class' => PaymentRequestController::class,
			'has_archive'           => false,
			'rewrite'               => false,
			'query_var'             => false,
			'supports'              => array( 'title', 'custom-fields' ),
			'capability_type'       => array( 'vicu_payment_request', 'vicu_payment_requests' ),
			'map_meta_cap'          => true,
			'capabilities'          => array(
				'create_posts' => 'create_vicu_payment_requests',
			),
		);
	}

	/**
	 * Registra el schema inicial de persistencia y REST.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		register_post_meta(
			self::SLUG,
			self::META_EXTERNAL_TYPE,
			self::meta_args(
				'string',
				array( self::class, 'sanitize_external_type' ),
				array(
					'type'      => 'string',
					'maxLength' => 64,
					'pattern'   => '^[a-z0-9_]+$',
				)
			)
		);

		register_post_meta(
			self::SLUG,
			self::META_EXTERNAL_ID,
			self::meta_args(
				'string',
				array( self::class, 'sanitize_external_id' ),
				array(
					'type'      => 'string',
					'maxLength' => 191,
				)
			)
		);

		register_post_meta(
			self::SLUG,
			self::META_AMOUNT_MINOR,
			self::meta_args(
				'integer',
				array( self::class, 'sanitize_amount_minor' ),
				array(
					'type'    => 'integer',
					'minimum' => 1,
				)
			)
		);

		register_post_meta(
			self::SLUG,
			self::META_CURRENCY,
			self::meta_args(
				'string',
				array( self::class, 'sanitize_currency' ),
				array(
					'type'      => 'string',
					'minLength' => 3,
					'maxLength' => 3,
					'pattern'   => '^[A-Z]{3}$',
				)
			)
		);

		register_post_meta(
			self::SLUG,
			self::META_PROVIDER,
			self::meta_args(
				'string',
				'sanitize_key',
				array(
					'type' => 'string',
					'enum' => array( 'manual' ),
				)
			)
		);

		register_post_meta(
			self::SLUG,
			self::META_STATE,
			self::meta_args(
				'string',
				'sanitize_key',
				array(
					'type' => 'string',
					'enum' => PaymentRequestState::all(),
				)
			)
		);

		register_post_meta(
			self::SLUG,
			self::META_REVISION,
			self::meta_args(
				'integer',
				'absint',
				array(
					'type'    => 'integer',
					'minimum' => 1,
				)
			)
		);

		register_post_meta(
			self::SLUG,
			self::META_EXPIRES_AT,
			self::meta_args(
				'string',
				'sanitize_text_field',
				array(
					'type'   => 'string',
					'format' => 'date-time',
				)
			)
		);
	}

	/**
	 * Devuelve las claves que solo puede mutar el servicio de dominio.
	 *
	 * @return string[]
	 */
	public static function contract_meta_keys(): array {
		return array(
			self::META_EXTERNAL_TYPE,
			self::META_EXTERNAL_ID,
			self::META_AMOUNT_MINOR,
			self::META_CURRENCY,
			self::META_PROVIDER,
			self::META_STATE,
			self::META_REVISION,
			self::META_EXPIRES_AT,
		);
	}

	/**
	 * Construye argumentos comunes para un meta protegido.
	 *
	 * @param string   $type              Tipo registrado en WordPress.
	 * @param callable $sanitize_callback Callback de sanitización.
	 * @param array    $rest_schema       Schema JSON del campo.
	 * @return array<string, mixed>
	 */
	private static function meta_args( string $type, callable $sanitize_callback, array $rest_schema ): array {
		return array(
			'type'              => $type,
			'single'            => true,
			'sanitize_callback' => $sanitize_callback,
			'auth_callback'     => array( self::class, 'can_edit_meta' ),
			'show_in_rest'      => array( 'schema' => $rest_schema ),
		);
	}

	/**
	 * Autoriza meta solo mediante la capability mapeada del post.
	 *
	 * @param bool   $allowed  Resultado previo de autorización.
	 * @param string $meta_key Clave consultada.
	 * @param int    $post_id  Solicitud afectada.
	 * @return bool
	 */
	public static function can_edit_meta( bool $allowed, string $meta_key, int $post_id ): bool {
		unset( $allowed, $meta_key );

		return 0 < $post_id && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Normaliza el tipo de referencia externa.
	 *
	 * @param mixed $value Valor recibido.
	 * @return string
	 */
	public static function sanitize_external_type( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$external_type = str_replace( '-', '_', sanitize_key( (string) $value ) );

		return substr( $external_type, 0, 64 );
	}

	/**
	 * Normaliza el identificador opaco de la referencia.
	 *
	 * @param mixed $value Valor recibido.
	 * @return string
	 */
	public static function sanitize_external_id( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return substr( sanitize_text_field( wp_unslash( (string) $value ) ), 0, 191 );
	}

	/**
	 * Normaliza el monto expresado en unidad menor.
	 *
	 * @param mixed $value Valor recibido.
	 * @return int
	 */
	public static function sanitize_amount_minor( mixed $value ): int {
		return is_scalar( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Normaliza el código ISO 4217.
	 *
	 * @param mixed $value Valor recibido.
	 * @return string
	 */
	public static function sanitize_currency( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$currency = (string) $value;

		if ( 1 !== preg_match( '/^[A-Za-z]{3}$/', $currency ) ) {
			return '';
		}

		return strtoupper( $currency );
	}
}
