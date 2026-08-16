<?php
/**
 * Plugin Name:       Vicunav Restaurante
 * Plugin URI:        https://github.com/vicunav/vicunav-restaurante
 * Description:       Native restaurant domain for the Vicunav WordPress ecosystem.
 * Version:           0.4.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Requires Plugins:  vicunav-plugin-core, vicunav-pagos
 * Author:            Vicunav
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vicunav-restaurante
 *
 * @package Vicunav_Restaurante
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VICU_RESTAURANTE_VERSION', '0.4.0' );
define( 'VICU_RESTAURANTE_CONTRACT_VERSION', '1.0.0' );
define( 'VICU_RESTAURANTE_DB_VERSION', '2' );
define( 'VICU_RESTAURANTE_PLUGIN_FILE', __FILE__ );
define( 'VICU_RESTAURANTE_PATH', __DIR__ . '/' );

require_once VICU_RESTAURANTE_PATH . 'src/bootstrap.php';

register_activation_hook( VICU_RESTAURANTE_PLUGIN_FILE, 'Vicu\Restaurante\activate' );
