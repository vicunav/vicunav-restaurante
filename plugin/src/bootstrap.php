<?php
/**
 * Carga técnica del plugin.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante;

use Vicu\Restaurante\Blocks\BlockRegistry;
use Vicu\Restaurante\Admin\MenuAdmin;
use Vicu\Restaurante\Admin\CatalogAdmin;
use Vicu\Restaurante\Admin\MenuRelationsAdmin;
use Vicu\Restaurante\Admin\CommerceAdmin;
use Vicu\Restaurante\Admin\OrderAdmin;
use Vicu\Restaurante\Admin\ReservationAdmin;
use Vicu\Restaurante\Menu\MenuCategory;
use Vicu\Restaurante\Menu\MenuItemPostType;
use Vicu\Restaurante\Menu\MenuMeta;
use Vicu\Restaurante\Rest\MenuRoutes;
use Vicu\Restaurante\Rest\CatalogRoutes;
use Vicu\Restaurante\Rest\PizzaQuoteRoute;
use Vicu\Restaurante\Rest\DeliveryZonesRoute;
use Vicu\Restaurante\Rest\CartRoutes;
use Vicu\Restaurante\Rest\OrderRoutes;
use Vicu\Restaurante\Rest\ReservationRoutes;
use Vicu\Restaurante\Rest\SavedPizzaRoutes;
use Vicu\Restaurante\Cart\CartService;
use Vicu\Restaurante\Order\OrderPostType;
use Vicu\Restaurante\Order\PaymentIntegration;
use Vicu\Restaurante\Privacy\PrivacyTools;
use Vicu\Restaurante\Reservation\ReservationPostType;
use Vicu\Restaurante\Reservation\ReservationSettings;
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
 * Inicia el plugin: aplica migraciones pendientes y registra todos los módulos.
 *
 * @internal
 *
 * @return void
 */
function bootstrap(): void {
	static $loaded = false;

	if ( $loaded ) {
		return;
	}

	if ( ! Installer::maybe_upgrade() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_installation_notice' );
		return;
	}

	$loaded = true;

	Shared\Module::register_hooks();
	Payments\Module::register_hooks();
	MenuCategory::register_hooks();
	( new MenuItemPostType() )->register_hooks();
	( new OrderPostType() )->register_hooks();
	( new ReservationPostType() )->register_hooks();
	MenuMeta::register_hooks();
	MenuAdmin::register_hooks();
	CatalogAdmin::register_hooks();
	MenuRelationsAdmin::register_hooks();
	CommerceAdmin::register_hooks();
	OrderAdmin::register_hooks();
	ReservationAdmin::register_hooks();
	MenuRoutes::register_hooks();
	CatalogRoutes::register_hooks();
	PizzaQuoteRoute::register_hooks();
	DeliveryZonesRoute::register_hooks();
	CartRoutes::register_hooks();
	OrderRoutes::register_hooks();
	ReservationRoutes::register_hooks();
	SavedPizzaRoutes::register_hooks();
	BlockRegistry::register_hooks();
	CartService::register_hooks();
	PaymentIntegration::register_hooks();
	RestaurantSettings::register_hooks();
	ReservationSettings::register_hooks();
	PrivacyTools::register_hooks();
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
	Payments\Module::activate();
	CartService::schedule_expiration();
	PaymentIntegration::schedule();
}

/**
 * Retira únicamente tareas programadas del plugin.
 *
 * @internal
 *
 * @return void
 */
function deactivate(): void {
	CartService::unschedule_expiration();
	PaymentIntegration::unschedule();
	Payments\Module::deactivate();
}

/**
 * Muestra un aviso seguro cuando el schema no pudo actualizarse.
 *
 * @internal
 *
 * @return void
 */
function render_installation_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Vicunav Restaurante no pudo actualizar su schema. Revisa la salud del sitio antes de continuar.', 'vicunav-restaurante' )
	);
}

spl_autoload_register( __NAMESPACE__ . '\\autoload' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap', 20 );
