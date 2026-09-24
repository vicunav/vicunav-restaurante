<?php
/**
 * Valida con el parser de WordPress los patterns reutilizables de restaurante.
 *
 * Ejecutar con:
 * wp eval-file tests/validate-wordpress-patterns.php
 *
 * @package Vicunav_Theme_Core
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'parse_blocks' ) ) {
	throw new RuntimeException( 'WordPress no expone el parser de bloques requerido.' );
}

$pattern_files = array(
	'hero-centered.php',
	'hero-split-image.php',
	'page-hero-banner.php',
	'linked-cards-grid.php',
	'editorial-story.php',
	'editorial-location.php',
	'testimonials-grid.php',
	'faq-accordion.php',
	'contact-info.php',
	'cta-simple.php',
);

/**
 * Recorre bloques y rechaza HTML opaco o nombres ajenos a core.
 *
 * @param array[] $blocks Bloques analizados por WordPress.
 * @return int Cantidad de bloques válidos.
 * @throws RuntimeException Si encuentra un bloque fuera del contrato.
 */
function vicunav_validate_restaurant_blocks( array $blocks ): int {
	$count = 0;

	foreach ( $blocks as $block ) {
		$block_name = $block['blockName'] ?? null;

		if ( null !== $block_name ) {
			if ( 'core/html' === $block_name || ! str_starts_with( $block_name, 'core/' ) ) {
				throw new RuntimeException( sprintf( 'Bloque no permitido: %s.', esc_html( $block_name ) ) );
			}

			++$count;
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$count += vicunav_validate_restaurant_blocks( $block['innerBlocks'] );
		}
	}

	return $count;
}

foreach ( $pattern_files as $pattern_file ) {
	$pattern_path = get_theme_file_path( 'patterns/' . $pattern_file );

	if ( ! is_readable( $pattern_path ) ) {
		throw new RuntimeException( sprintf( 'No se puede leer el pattern %s.', esc_html( $pattern_file ) ) );
	}

	ob_start();
	include $pattern_path;
	$markup = (string) ob_get_clean();
	$blocks = parse_blocks( $markup );
	$count  = vicunav_validate_restaurant_blocks( $blocks );

	if ( 0 === $count ) {
		throw new RuntimeException( sprintf( '%s no produjo bloques analizables.', esc_html( $pattern_file ) ) );
	}
}

WP_CLI::success( 'WordPress validó los diez patterns reutilizables de restaurante.' );
