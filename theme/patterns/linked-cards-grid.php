<?php
/**
 * Title: Cuadrícula de enlaces visuales
 * Slug: vicunav-bonasera/linked-cards-grid
 * Categories: vicunav-bonasera
 * Description: Colección editorial de cuatro destinos con media y enlaces editables.
 * Inserter: yes
 *
 * @package Vicunav_Bonasera
 */

defined( 'ABSPATH' ) || exit;

?>

<!-- wp:group {"tagName":"section","metadata":{"name":"Cuadrícula de enlaces visuales"},"align":"full","className":"vicunav-pattern-section vicunav-linked-cards-grid","backgroundColor":"vicunav-neutral-200","textColor":"vicunav-neutral-900","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull vicunav-pattern-section vicunav-linked-cards-grid has-vicunav-neutral-900-color has-vicunav-neutral-200-background-color has-text-color has-background"><!-- wp:group {"className":"vicunav-linked-cards-grid__intro","style":{"spacing":{"blockGap":"var:preset|spacing|vicunav-space-sm"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group vicunav-linked-cards-grid__intro"><!-- wp:heading {"textColor":"vicunav-neutral-900","fontSize":"vicunav-xl","fontFamily":"vicunav-heading","className":"vicunav-section-heading"} -->
<h2 class="wp-block-heading vicunav-section-heading has-vicunav-neutral-900-color has-text-color has-vicunav-heading-font-family has-vicunav-xl-font-size">Explora nuestras propuestas</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"vicunav-neutral-700","fontSize":"vicunav-lg","fontFamily":"vicunav-body"} -->
<p class="has-vicunav-neutral-700-color has-text-color has-vicunav-body-font-family has-vicunav-lg-font-size">Presenta los destinos principales con una jerarquía clara y contenido editable.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:columns {"className":"vicunav-linked-cards-grid__columns"} -->
<div class="wp-block-columns vicunav-linked-cards-grid__columns">
<?php
$vicunav_linked_cards = array(
	array( 'Primera propuesta', 'destino-uno' ),
	array( 'Segunda propuesta', 'destino-dos' ),
	array( 'Tercera propuesta', 'destino-tres' ),
	array( 'Cuarta propuesta', 'destino-cuatro' ),
);

foreach ( $vicunav_linked_cards as $vicunav_linked_card ) :
	?>
	<!-- wp:column -->
	<div class="wp-block-column"><!-- wp:cover {"dimRatio":0,"overlayColor":"vicunav-neutral-300","isDark":false,"contentPosition":"bottom left","className":"vicunav-linked-card"} -->
	<div class="wp-block-cover is-light has-custom-content-position is-position-bottom-left vicunav-linked-card"><span aria-hidden="true" class="wp-block-cover__background has-vicunav-neutral-300-background-color has-background-dim-0 has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3,"textColor":"vicunav-neutral-100","fontSize":"vicunav-lg","fontFamily":"vicunav-heading"} -->
	<h3 class="wp-block-heading has-vicunav-neutral-100-color has-text-color has-vicunav-heading-font-family has-vicunav-lg-font-size"><?php echo esc_html( $vicunav_linked_card[0] ); ?></h3>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"textColor":"vicunav-neutral-300","fontSize":"vicunav-sm","fontFamily":"vicunav-body"} -->
	<p class="has-vicunav-neutral-300-color has-text-color has-vicunav-body-font-family has-vicunav-sm-font-size">Añade una descripción breve.</p>
	<!-- /wp:paragraph -->

	<!-- wp:buttons -->
	<div class="wp-block-buttons"><!-- wp:button {"textColor":"vicunav-neutral-100","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
	<div class="wp-block-button has-custom-font-size has-vicunav-body-font-family has-vicunav-md-font-size"><a class="wp-block-button__link has-vicunav-neutral-100-color has-text-color wp-element-button" href="#<?php echo esc_attr( $vicunav_linked_card[1] ); ?>">Explorar</a></div>
	<!-- /wp:button --></div>
	<!-- /wp:buttons --></div></div>
	<!-- /wp:cover --></div>
	<!-- /wp:column -->
	<?php
endforeach;
?>
</div>
<!-- /wp:columns --></section>
<!-- /wp:group -->
