<?php
/**
 * Funciones del theme Vicunav Bonasera.
 *
 * @package Vicunav_Bonasera
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra las categorías de patrones propias del theme.
 *
 * @return void
 */
function vicunav_bonasera_register_pattern_categories() {
	register_block_pattern_category(
		'vicunav-bonasera',
		array(
			'label' => __( 'Bonasera', 'vicunav-bonasera' ),
		)
	);
}
add_action( 'init', 'vicunav_bonasera_register_pattern_categories' );

/**
 * Conserva el tipo de contenido de las consultas verticales del theme.
 *
 * WordPress sustituye por entradas una consulta cuyo tipo todavía no está
 * registrado. El patrón debe mostrar su estado vacío hasta que el plugin
 * responsable registre el tipo de contenido.
 *
 * @param array    $query Argumentos preparados para WP_Query.
 * @param WP_Block $block Bloque Post Template que ejecuta la consulta.
 * @return array
 */
function vicunav_bonasera_preserve_vertical_query_post_type( $query, $block ) {
	$post_type = $block->context['query']['postType'] ?? '';

	if ( in_array( $post_type, array( 'vicu_testimonial', 'vicu_faq' ), true ) ) {
		$query['post_type'] = $post_type;
	}

	return $query;
}
add_filter( 'query_loop_block_query_vars', 'vicunav_bonasera_preserve_vertical_query_post_type', 10, 2 );

/**
 * Carga el comportamiento progresivo del acordeón de preguntas frecuentes.
 *
 * Solo se encola si el contenido de la entrada actual usa el pattern
 * `faq-accordion`, en vez de en toda página del sitio.
 *
 * @return void
 */
function vicunav_bonasera_enqueue_scripts() {
	if ( ! vicunav_bonasera_singular_uses_faq_accordion() ) {
		return;
	}

	wp_enqueue_script(
		'vicunav-bonasera-faq-accordion',
		get_theme_file_uri( 'assets/js/faq-accordion.js' ),
		array(),
		wp_get_theme()->get( 'Version' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'vicunav_bonasera_enqueue_scripts' );

/**
 * Comprueba si el contenido de la entrada actual usa el pattern faq-accordion.
 *
 * @return bool
 */
function vicunav_bonasera_singular_uses_faq_accordion(): bool {
	return vicunav_bonasera_singular_content_has_block(
		static function ( array $block ): bool {
			if ( 'core/pattern' === ( $block['blockName'] ?? null ) ) {
				return 'vicunav-bonasera/faq-accordion' === ( $block['attrs']['slug'] ?? '' );
			}

			return str_contains( (string) ( $block['attrs']['className'] ?? '' ), 'vicunav-faq-accordion__item' );
		}
	);
}

/**
 * Comprueba si algún bloque del contenido de la entrada actual cumple una condición.
 *
 * Un pattern insertado por referencia (`<!-- wp:pattern {"slug":"..."} /-->`)
 * no copia su contenido en `post_content`: solo lo expande al renderizar. Por
 * eso no basta con buscar texto en `post_content`; hay que reconocer también
 * la referencia al pattern por su slug además de la clase CSS que tendría si
 * el contenido llegó a copiarse (por ejemplo, al desvincular el pattern en el
 * Editor).
 *
 * @param callable $matcher Evalúa un bloque parseado; recibe el array del
 *                          bloque y devuelve bool.
 * @return bool
 */
function vicunav_bonasera_singular_content_has_block( callable $matcher ): bool {
	if ( ! is_singular() ) {
		return false;
	}

	$post = get_post();

	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	return vicunav_bonasera_blocks_match( parse_blocks( $post->post_content ), $matcher );
}

/**
 * Recorre un árbol de bloques parseados buscando una coincidencia.
 *
 * @param array<int, array<string, mixed>> $blocks  Árbol de bloques.
 * @param callable                         $matcher Evalúa un bloque parseado; recibe el
 *                                                  array del bloque y devuelve bool.
 * @return bool
 */
function vicunav_bonasera_blocks_match( array $blocks, callable $matcher ): bool {
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		if ( $matcher( $block ) ) {
			return true;
		}

		if ( ! empty( $block['innerBlocks'] ) && vicunav_bonasera_blocks_match( $block['innerBlocks'], $matcher ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Registra el estilo scoped del chrome reutilizable de restaurante.
 *
 * @return void
 */
function vicunav_bonasera_register_restaurant_chrome_style() {
	$style_path = get_theme_file_path( 'assets/css/restaurant-chrome.css' );

	wp_enqueue_block_style(
		'core/navigation',
		array(
			'handle' => 'vicunav-bonasera-restaurant-chrome',
			'src'    => get_theme_file_uri( 'assets/css/restaurant-chrome.css' ),
			'path'   => $style_path,
			'ver'    => file_exists( $style_path ) ? (string) filemtime( $style_path ) : wp_get_theme()->get( 'Version' ),
		)
	);
}

/**
 * Registra el chrome de restaurante para el Editor sin restricción de plantilla.
 *
 * El Editor no dispara la acción `wp`, así que esta rama se mantiene
 * incondicional para no perder el estilo en su iframe de vista previa.
 *
 * @return void
 */
function vicunav_bonasera_register_restaurant_chrome_style_in_admin() {
	if ( is_admin() ) {
		vicunav_bonasera_register_restaurant_chrome_style();
	}
}
add_action( 'init', 'vicunav_bonasera_register_restaurant_chrome_style_in_admin' );

/**
 * Registra el chrome de restaurante en frontend solo donde se usa.
 *
 * Las partes de header/footer de restaurante solo las usan `front-page` y
 * `page`; `single`, `archive` e `index` usan el chrome genérico. Como estas
 * partes no viven en `post_content` (son template parts), se acota por
 * plantilla en vez de por contenido de la entrada. Se usa la acción `wp`, que
 * corre después de resolver la consulta principal (a diferencia de `init`) y
 * antes de `wp_enqueue_scripts`, para que `wp_enqueue_block_style()` siga
 * imprimiendo en el `<head>` en vez de diferir a `wp_footer`.
 *
 * @return void
 */
function vicunav_bonasera_register_restaurant_chrome_style_on_frontend() {
	if ( is_front_page() || is_page() ) {
		vicunav_bonasera_register_restaurant_chrome_style();
	}
}
add_action( 'wp', 'vicunav_bonasera_register_restaurant_chrome_style_on_frontend' );

/**
 * Registra el estilo scoped de los patterns editoriales de restaurante.
 *
 * @return void
 */
function vicunav_bonasera_register_restaurant_pattern_style() {
	$style_path = get_theme_file_path( 'assets/css/restaurant-patterns.css' );

	wp_enqueue_block_style(
		'core/group',
		array(
			'handle' => 'vicunav-bonasera-restaurant-patterns',
			'src'    => get_theme_file_uri( 'assets/css/restaurant-patterns.css' ),
			'path'   => $style_path,
			'ver'    => file_exists( $style_path ) ? (string) filemtime( $style_path ) : wp_get_theme()->get( 'Version' ),
		)
	);
}

/**
 * Comprueba si el contenido de la entrada actual usa algún pattern editorial.
 *
 * Igual que `vicunav_bonasera_singular_uses_faq_accordion()`, reconoce
 * tanto la referencia por slug (`wp:pattern`) como la clase CSS que quedaría
 * si el contenido del pattern se copió directamente en `post_content`.
 *
 * @return bool
 */
function vicunav_bonasera_singular_uses_restaurant_patterns(): bool {
	$class_markers = array(
		'vicunav-pattern-',
		'vicunav-linked-',
		'vicunav-editorial-',
		'vicunav-testimonials-',
		'vicunav-faq-',
		'vicunav-contact-',
	);

	return vicunav_bonasera_singular_content_has_block(
		static function ( array $block ) use ( $class_markers ): bool {
			if (
				'core/pattern' === ( $block['blockName'] ?? null ) &&
				str_starts_with( (string) ( $block['attrs']['slug'] ?? '' ), 'vicunav-bonasera/' )
			) {
				return true;
			}

			$class_name = (string) ( $block['attrs']['className'] ?? '' );

			foreach ( $class_markers as $marker ) {
				if ( str_contains( $class_name, $marker ) ) {
					return true;
				}
			}

			return false;
		}
	);
}

/**
 * Registra los patterns de restaurante para el Editor sin restricción de contenido.
 *
 * @return void
 */
function vicunav_bonasera_register_restaurant_pattern_style_in_admin() {
	if ( is_admin() ) {
		vicunav_bonasera_register_restaurant_pattern_style();
	}
}
add_action( 'init', 'vicunav_bonasera_register_restaurant_pattern_style_in_admin' );

/**
 * Registra los patterns de restaurante en frontend solo donde se usan.
 *
 * Cualquier pattern de este theme puede insertarse en cualquier tipo de
 * contenido, no solo en páginas, así que se acota por contenido de la
 * entrada en vez de por plantilla. Ver el comentario sobre la acción `wp`
 * en `vicunav_bonasera_register_restaurant_chrome_style_on_frontend()`.
 *
 * @return void
 */
function vicunav_bonasera_register_restaurant_pattern_style_on_frontend() {
	if ( vicunav_bonasera_singular_uses_restaurant_patterns() ) {
		vicunav_bonasera_register_restaurant_pattern_style();
	}
}
add_action( 'wp', 'vicunav_bonasera_register_restaurant_pattern_style_on_frontend' );

/**
 * Registra los estilos scoped de los motivos decorativos propios de Bonasera
 * (encabezado fantasma, tarjeta estilo ticket). Usa wp_enqueue_block_style() para
 * que carguen tanto en frontend como en el Editor del sitio sin depender de
 * add_editor_style().
 *
 * @return void
 */
function vicunav_bonasera_register_motif_style() {
	$style_path = get_theme_file_path( 'assets/css/bonasera-motifs.css' );

	wp_enqueue_block_style(
		'core/group',
		array(
			'handle' => 'vicunav-bonasera-motifs',
			'src'    => get_theme_file_uri( 'assets/css/bonasera-motifs.css' ),
			'path'   => $style_path,
			'ver'    => file_exists( $style_path ) ? (string) filemtime( $style_path ) : wp_get_theme()->get( 'Version' ),
		)
	);
}
add_action( 'init', 'vicunav_bonasera_register_motif_style' );

/**
 * Oculta el campo "Añadir título" del editor de bloques en las páginas de
 * este demo: cada página trae su propio encabezado diseñado (hero, banner
 * interior) y el campo de título nativo de wp-admin queda redundante,
 * pidiendo que "se vea el diseño directamente" tal como pidió el usuario.
 *
 * No usa remove_post_type_support('page', 'title'): eso quita el título
 * también de listados, Edición rápida y la API REST — más de lo pedido. Solo
 * oculta el campo visualmente en el editor de bloques, con la misma técnica
 * que usa el theme para neutralizar clamps del editor
 * (wp_add_inline_style() colgado de enqueue_block_editor_assets). El título
 * real se conserva en la base de datos: sigue siendo necesario para el
 * admin bar, el <title> del navegador y el editor de bloques internamente.
 *
 * @return void
 */
function vicunav_bonasera_hide_editor_title_field() {
	$screen = get_current_screen();
	if ( ! $screen || 'page' !== $screen->post_type ) {
		return;
	}

	wp_add_inline_style(
		'wp-edit-blocks',
		'.editor-post-title, .edit-post-visual-editor__post-title-wrapper { display: none !important; }'
	);
}
add_action( 'enqueue_block_editor_assets', 'vicunav_bonasera_hide_editor_title_field' );
