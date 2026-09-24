<?php
/**
 * Registro de las capacidades compartidas del plugin.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Shared;

use Vicu\Restaurante\Shared\PostTypes\Faq;
use Vicu\Restaurante\Shared\PostTypes\Testimonial;

defined( 'ABSPATH' ) || exit;

/**
 * Enlaza los tipos de contenido y los ajustes que usan el theme y los demás módulos.
 *
 * @internal
 */
final class Module {
	/**
	 * Registra FAQ, testimonios y ajustes generales.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		( new Faq() )->register_hooks();
		( new Testimonial() )->register_hooks();
		Settings::register_hooks();
	}
}
