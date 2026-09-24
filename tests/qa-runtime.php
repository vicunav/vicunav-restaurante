<?php
/**
 * Audita la composición aplicada sin modificar WordPress.
 *
 * Uso: wp eval-file tests/qa-runtime.php
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Restaurante\Catalog\IngredientService;
use Vicu\Restaurante\Catalog\PizzaOptionService;
use Vicu\Restaurante\Commerce\DeliveryZoneService;
use Vicu\Restaurante\Commerce\DiscountService;

// Los archivos son locales, las consultas por meta son acotadas y las variables
// viven en el alcance deliberadamente global de wp eval-file.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$repo_root = dirname( __DIR__ );
$content   = json_decode( (string) file_get_contents( $repo_root . '/content/bonasera.json' ), true );
$failures  = array();

/**
 * Registra una aserción fallida sin interrumpir las demás comprobaciones.
 *
 * @param bool   $condition Condición esperada.
 * @param string $message   Mensaje de diagnóstico.
 */
function vicu_demo_qa_assert( bool $condition, string $message ): void {
	global $failures;

	if ( ! $condition ) {
		$failures[] = $message;
	}
}

/**
 * Devuelve IDs de posts creados por una clave o prefijo del demo.
 *
 * @param string $post_type Tipo de contenido.
 * @param string $key       Clave o prefijo estable.
 * @param bool   $prefix    Si busca por prefijo.
 * @return int[]
 */
function vicu_demo_qa_posts( string $post_type, string $key, bool $prefix = false ): array {
	return get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_key'       => '_vicu_demo_key',
			'meta_value'     => $key,
			'meta_compare'   => $prefix ? 'LIKE' : '=',
			'fields'         => 'ids',
		)
	);
}

/**
 * Aplana nombres de bloques para comprobar la composición.
 *
 * @param array<int, array<string, mixed>> $blocks Árbol de bloques.
 * @return string[]
 */
function vicu_demo_qa_block_names( array $blocks ): array {
	$names = array();
	foreach ( $blocks as $block ) {
		if ( is_string( $block['blockName'] ?? null ) ) {
			$names[] = $block['blockName'];
		}
		if ( is_array( $block['innerBlocks'] ?? null ) ) {
			$names = array_merge( $names, vicu_demo_qa_block_names( $block['innerBlocks'] ) );
		}
	}

	return $names;
}

if ( ! is_array( $content ) || ! is_array( $manifest ) ) {
	WP_CLI::error( 'No se pudieron leer los manifiestos del demo.' );
}

$expected_blocks = array(
	'menu'       => 'vicunav/restaurante-menu',
	'pizzas'     => 'vicunav/restaurante-pizza-builder',
	'carrito'    => 'vicunav/restaurante-cart',
	'checkout'   => 'vicunav/restaurante-checkout',
	'pedido'     => 'vicunav/restaurante-order-status',
	'reservas'   => 'vicunav/restaurante-reservations',
	'mis-pizzas' => 'vicunav/restaurante-saved-pizzas',
);
$routes          = array( '/', '/menu/', '/pizzas/', '/carrito/', '/checkout/', '/pedido/', '/reservas/', '/mis-pizzas/', '/privacidad/' );
$flow_signatures = array(
	'/menu/'       => array( 'wp-block-vicunav-restaurante-menu', 'vicu-restaurante-menu__search' ),
	'/pizzas/'     => array( 'wp-block-vicunav-restaurante-pizza-builder', 'data-wp-on--click="actions.toggleTopping"' ),
	'/carrito/'    => array( 'wp-block-vicunav-restaurante-cart', 'data-commerce-form="discount"' ),
	'/checkout/'   => array( 'wp-block-vicunav-restaurante-checkout', 'data-commerce-form="checkout"' ),
	'/pedido/'     => array( 'wp-block-vicunav-restaurante-order-status', 'data-commerce-form="order-lookup"' ),
	'/reservas/'   => array( 'wp-block-vicunav-restaurante-reservations', 'data-reservation-form' ),
	'/mis-pizzas/' => array( 'wp-block-vicunav-restaurante-saved-pizzas', 'vicu-restaurante-saved-pizzas__authentication' ),
);
$route_results   = array();
$registry        = WP_Block_Type_Registry::get_instance();

vicu_demo_qa_assert( 'vicunav-bonasera' === get_stylesheet(), 'El theme activo no es vicunav-bonasera.' );
vicu_demo_qa_assert( is_plugin_active( 'vicunav-restaurante/vicunav-restaurante.php' ), 'El plugin vicunav-restaurante no está activo.' );
foreach ( $expected_blocks as $block_name ) {
	vicu_demo_qa_assert( $registry->is_registered( $block_name ), 'El bloque no está registrado: ' . $block_name );
}

$global_style_posts = get_posts(
	array(
		'post_type'   => 'wp_global_styles',
		'post_status' => 'publish',
		'numberposts' => 2,
		'name'        => 'wp-global-styles-' . get_stylesheet(),
		'tax_query'   => array(
			array(
				'taxonomy' => 'wp_theme',
				'field'    => 'slug',
				'terms'    => get_stylesheet(),
			),
		),
	)
);
vicu_demo_qa_assert( 1 === count( $global_style_posts ), 'La variación Bonasera no tiene un único post Global Styles asociado al theme.' );
if ( $global_style_posts ) {
	$global_style_data = json_decode( $global_style_posts[0]->post_content, true );
	vicu_demo_qa_assert( true === ( $global_style_data['isGlobalStylesUserThemeJSON'] ?? false ), 'La variación Bonasera no está marcada como JSON de usuario de Global Styles.' );
	vicu_demo_qa_assert( '#0D0D0D' === ( $global_style_data['settings']['color']['palette'][0]['color'] ?? null ), 'La variación Bonasera no conserva su paleta efectiva.' );
	vicu_demo_qa_assert( 'Big Shoulders Display' === ( $global_style_data['settings']['typography']['fontFamilies'][0]['fontFace'][0]['fontFamily'] ?? null ), 'La variación Bonasera no conserva su tipografía efectiva.' );
}

vicu_demo_qa_assert( 37 === count( vicu_demo_qa_posts( 'vicu_menu_item', 'menu-item:', true ) ), 'El catálogo Bonasera no contiene 37 platos marcados.' );
vicu_demo_qa_assert( 8 === count( vicu_demo_qa_posts( 'vicu_faq', 'faq:', true ) ), 'No hay ocho FAQ marcadas.' );
vicu_demo_qa_assert( 3 === count( vicu_demo_qa_posts( 'vicu_testimonial', 'testimonial:', true ) ), 'No hay tres testimonios marcados.' );

$attachments = get_posts(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'meta_key'       => '_vicu_demo_asset_id',
		'fields'         => 'ids',
	)
);
vicu_demo_qa_assert( 9 === count( $attachments ), 'No hay nueve medios Bonasera marcados.' );

$entity_map = get_option( 'vicu_demo_restaurante_entity_map', array() );
vicu_demo_qa_assert( is_array( $entity_map ) && 35 === count( $entity_map ), 'El mapa estable debe contener 35 entidades.' );
vicu_demo_qa_assert( 17 <= count( IngredientService::all() ), 'Faltan ingredientes del constructor.' );
vicu_demo_qa_assert( 10 <= count( PizzaOptionService::all() ), 'Faltan opciones de pizza.' );
vicu_demo_qa_assert( 6 <= count( DeliveryZoneService::all() ), 'Faltan zonas de entrega.' );
vicu_demo_qa_assert( 2 <= count( DiscountService::all() ), 'Faltan descuentos de demostración.' );

foreach ( $content['pages'] as $page_data ) {
	$page_id = vicu_demo_qa_posts( 'page', $page_data['id'] );
	vicu_demo_qa_assert( 1 === count( $page_id ), 'La página no es única: ' . $page_data['id'] );
	if ( ! $page_id ) {
		continue;
	}
	$post_content = (string) get_post_field( 'post_content', $page_id[0] );
	$names        = vicu_demo_qa_block_names( parse_blocks( $post_content ) );
	vicu_demo_qa_assert( ! in_array( 'core/missing', $names, true ), 'Hay un bloque faltante en ' . $page_data['id'] . '.' );
	if ( isset( $expected_blocks[ $page_data['id'] ] ) ) {
		vicu_demo_qa_assert( in_array( $expected_blocks[ $page_data['id'] ], $names, true ), 'Falta el bloque funcional de ' . $page_data['id'] . '.' );
	}
	vicu_demo_qa_assert( 'inicio' === $page_data['id'] ? 1 === substr_count( strtolower( $post_content ), '<h1' ) : 0 === substr_count( strtolower( $post_content ), '<h1' ), 'Jerarquía H1 incorrecta en ' . $page_data['id'] . '.' );
}

$front_template = get_block_template( get_stylesheet() . '//front-page', 'wp_template' );
$page_template  = get_block_template( get_stylesheet() . '//page', 'wp_template' );
vicu_demo_qa_assert( $front_template && 'custom' === $front_template->source, 'La portada no usa un override FSE editable.' );
vicu_demo_qa_assert( $page_template && 'custom' === $page_template->source, 'Las páginas no usan un override FSE editable.' );
vicu_demo_qa_assert( $front_template && str_contains( $front_template->content, '"align":"full"' ), 'La portada no permite secciones de ancho completo.' );
vicu_demo_qa_assert( $page_template && ! str_contains( $page_template->content, 'wp:post-title' ), 'El template interno duplica el H1 del banner editorial.' );

foreach ( $routes as $route ) {
	$response = wp_remote_get(
		home_url( $route ),
		array(
			'sslverify' => false,
			'timeout'   => 20,
		)
	);
	$status   = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
	$body     = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
	$h1_count = preg_match_all( '/<h1(?:\s|>)/i', $body );
	$hotlinks = preg_match_all( '#<img[^>]+src=["\']https?://(?!' . preg_quote( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '#' ) . ')[^"\']+#i', $body );
	vicu_demo_qa_assert( 200 === $status, 'La ruta no responde 200: ' . $route );
	vicu_demo_qa_assert( 1 === $h1_count, 'La ruta no contiene un H1 único: ' . $route );
	vicu_demo_qa_assert( 0 === $hotlinks, 'La ruta contiene una imagen remota: ' . $route );
	if ( isset( $flow_signatures[ $route ] ) ) {
		foreach ( $flow_signatures[ $route ] as $signature ) {
			vicu_demo_qa_assert( str_contains( $body, $signature ), 'Falta la firma funcional ' . $signature . ' en ' . $route );
		}
	}
	$route_results[ $route ] = array(
		'status'        => $status,
		'h1_count'      => $h1_count,
		'bytes'         => strlen( $body ),
		'remote_images' => $hotlinks,
	);
}

$report = array(
	'checked_at_utc'       => gmdate( 'c' ),
	'site_url'             => home_url( '/' ),
	'wordpress'            => get_bloginfo( 'version' ),
	'php'                  => PHP_VERSION,
	'demo_baseline_commit' => 'bdc0a1536c8cd7f80a85a1084dfa6c7194c57580',
	'routes'               => $route_results,
	'failures'             => $failures,
);

if ( $failures ) {
	WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	WP_CLI::error( 'El gate runtime detectó ' . count( $failures ) . ' fallos.' );
}

WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
