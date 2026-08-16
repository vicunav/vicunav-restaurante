<?php
/**
 * Carga técnica y validación contractual del plugin.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante;

use Vicu\Restaurante\Admin\MenuAdmin;
use Vicu\Restaurante\Admin\CatalogAdmin;
use Vicu\Restaurante\Admin\MenuRelationsAdmin;
use Vicu\Restaurante\Admin\CommerceAdmin;
use Vicu\Restaurante\Menu\MenuCategory;
use Vicu\Restaurante\Menu\MenuItemPostType;
use Vicu\Restaurante\Menu\MenuMeta;
use Vicu\Restaurante\Rest\MenuRoutes;
use Vicu\Restaurante\Rest\CatalogRoutes;
use Vicu\Restaurante\Rest\PizzaQuoteRoute;
use Vicu\Restaurante\Rest\DeliveryZonesRoute;
use Vicu\Restaurante\Settings\RestaurantSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carga una clase del namespace Vicu\Restaurante desde src/.
 *
 * @internal
 *
 * @param string $requested_class Nombre completo de la clase solicitada.
 * @return void
 */
function autoload( string $requested_class ): void {
	$prefix = __NAMESPACE__ . '\\';

	if ( 0 !== strpos( $requested_class, $prefix ) ) {
		return;
	}

	$relative_class = substr( $requested_class, strlen( $prefix ) );
	$parts          = explode( '\\', $relative_class );
	$short_name     = array_pop( $parts );
	$directories    = array_map( __NAMESPACE__ . '\\to_kebab_case', $parts );
	$file_name      = 'class-' . to_kebab_case( $short_name ) . '.php';
	$file           = VICU_RESTAURANTE_PATH . 'src/';

	if ( array() !== $directories ) {
		$file .= implode( '/', $directories ) . '/';
	}

	$file .= $file_name;

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}

/**
 * Convierte un segmento PascalCase a kebab-case.
 *
 * @internal
 *
 * @param string $value Segmento que se convertirá.
 * @return string
 */
function to_kebab_case( string $value ): string {
	$converted = preg_replace( '/(?<!^)[A-Z]/', '-$0', $value );

	return strtolower( (string) $converted );
}

/**
 * Inicia el contrato cuando sus dependencias públicas son compatibles.
 *
 * @internal
 *
 * @return void
 */
function bootstrap(): void {
	$dependencies = DependencyRequirements::inspect();
	$error_code   = DependencyRequirements::validate( $dependencies );

	if ( null === $error_code && ! Installer::maybe_upgrade() ) {
		register_admin_notice( Installer::ERROR_INSTALLATION );
		return;
	}

	bootstrap_with_dependencies( $dependencies );
}

/**
 * Instala el schema base y las capabilities iniciales.
 *
 * @internal
 *
 * @return void
 */
function activate(): void {
	if ( ! Installer::install() ) {
		wp_die(
			esc_html__( 'Vicunav Restaurante no pudo completar su instalación.', 'vicunav-restaurante' ),
			'',
			array( 'response' => 500 )
		);
	}

	Capabilities::grant_to_administrator();
}

/**
 * Ejecuta el bootstrap con un estado de dependencias verificable.
 *
 * @internal
 *
 * @param array<string, bool|string|null> $dependencies Estado de dependencias.
 * @return void
 */
function bootstrap_with_dependencies( array $dependencies ): void {
	static $loaded            = false;
	static $notice_registered = false;

	if ( $loaded ) {
		return;
	}

	$error_code = DependencyRequirements::validate( $dependencies );

	if ( null !== $error_code ) {
		if ( ! $notice_registered ) {
			register_admin_notice( $error_code );
			$notice_registered = true;
		}

		return;
	}

	$loaded = true;

	MenuCategory::register_hooks();
	( new MenuItemPostType() )->register_hooks();
	MenuMeta::register_hooks();
	MenuAdmin::register_hooks();
	CatalogAdmin::register_hooks();
	MenuRelationsAdmin::register_hooks();
	CommerceAdmin::register_hooks();
	MenuRoutes::register_hooks();
	CatalogRoutes::register_hooks();
	PizzaQuoteRoute::register_hooks();
	DeliveryZonesRoute::register_hooks();
	RestaurantSettings::register_hooks();

	/**
	 * Se ejecuta cuando el contrato base del vertical restaurante está disponible.
	 *
	 * @since 0.2.0
	 *
	 * @param string $plugin_version   Versión del plugin.
	 * @param string $contract_version Versión del contrato público.
	 */
	do_action(
		'vicu_restaurante_loaded',
		VICU_RESTAURANTE_VERSION,
		VICU_RESTAURANTE_CONTRACT_VERSION
	);
}

/**
 * Registra un aviso administrativo para un error de carga.
 *
 * @internal
 *
 * @param string $error_code Código interno del error.
 * @return void
 */
function register_admin_notice( string $error_code ): void {
	add_action(
		'admin_notices',
		static function () use ( $error_code ): void {
			render_dependency_notice( $error_code );
		}
	);
}

/**
 * Muestra un aviso seguro cuando una dependencia no satisface el contrato.
 *
 * @internal
 *
 * @param string $error_code Código interno de incompatibilidad.
 * @return void
 */
function render_dependency_notice( string $error_code ): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$messages = array(
		DependencyRequirements::ERROR_CORE_UNAVAILABLE   => esc_html__( 'Vicunav Restaurante requiere Vicunav Plugin Core activo y con sus APIs públicas disponibles.', 'vicunav-restaurante' ),
		DependencyRequirements::ERROR_CORE_INCOMPATIBLE  => esc_html__( 'Vicunav Restaurante requiere el contrato mayor 1 de Vicunav Plugin Core.', 'vicunav-restaurante' ),
		DependencyRequirements::ERROR_PAGOS_UNAVAILABLE  => esc_html__( 'Vicunav Restaurante requiere Vicunav Pagos activo y con sus APIs públicas disponibles.', 'vicunav-restaurante' ),
		DependencyRequirements::ERROR_PAGOS_INCOMPATIBLE => esc_html__( 'Vicunav Restaurante requiere el contrato de Vicunav Pagos desde 0.3.0 y anterior a 1.0.0.', 'vicunav-restaurante' ),
		Installer::ERROR_INSTALLATION                    => esc_html__( 'Vicunav Restaurante no pudo actualizar su schema. Revisa la salud del sitio antes de continuar.', 'vicunav-restaurante' ),
	);
	$message  = $messages[ $error_code ] ?? esc_html__( 'Vicunav Restaurante no pudo validar sus dependencias.', 'vicunav-restaurante' );

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		$message // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- El mapa usa esc_html__().
	);
}

spl_autoload_register( __NAMESPACE__ . '\\autoload' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap', 20 );
