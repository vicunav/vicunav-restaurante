<?php
/**
 * Title: Hero fotográfico centrado
 * Slug: vicunav-bonasera/hero-centered
 * Categories: vicunav-bonasera
 * Description: Hero principal con media editable, título, texto y dos llamadas a la acción.
 * Inserter: yes
 *
 * @package Vicunav_Bonasera
 */

defined( 'ABSPATH' ) || exit;

?>

<!-- wp:cover {"dimRatio":70,"overlayColor":"vicunav-neutral-900","minHeight":580,"minHeightUnit":"px","isUserOverlayColor":true,"contentPosition":"bottom left","align":"full","className":"vicunav-pattern-hero","layout":{"type":"constrained"}} -->
<div class="wp-block-cover alignfull has-custom-content-position is-position-bottom-left vicunav-pattern-hero" style="min-height:580px"><span aria-hidden="true" class="wp-block-cover__background has-vicunav-neutral-900-background-color has-background-dim-70 has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:group {"className":"vicunav-pattern-hero__content","style":{"spacing":{"blockGap":"var:preset|spacing|vicunav-space-md"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group vicunav-pattern-hero__content"><!-- wp:heading {"level":1,"textColor":"vicunav-neutral-100","fontSize":"vicunav-xl","fontFamily":"vicunav-heading","className":"vicunav-pattern-hero__title"} -->
<h1 class="wp-block-heading vicunav-pattern-hero__title has-vicunav-neutral-100-color has-text-color has-vicunav-heading-font-family has-vicunav-xl-font-size">Una experiencia hecha para compartir</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"vicunav-neutral-300","fontSize":"vicunav-md","fontFamily":"vicunav-body","className":"vicunav-pattern-hero__lead"} -->
<p class="vicunav-pattern-hero__lead has-vicunav-neutral-300-color has-text-color has-vicunav-body-font-family has-vicunav-md-font-size">Presenta aquí la propuesta principal del negocio con un mensaje breve y una media propia.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"vicunav-neutral-100","textColor":"vicunav-neutral-900","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
<div class="wp-block-button has-custom-font-size has-vicunav-body-font-family has-vicunav-md-font-size"><a class="wp-block-button__link has-vicunav-neutral-900-color has-vicunav-neutral-100-background-color has-text-color has-background wp-element-button" href="#accion-principal">Acción principal</a></div>
<!-- /wp:button -->

<!-- wp:button {"textColor":"vicunav-neutral-100","className":"is-style-outline","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
<div class="wp-block-button is-style-outline has-custom-font-size has-vicunav-body-font-family has-vicunav-md-font-size"><a class="wp-block-button__link has-vicunav-neutral-100-color has-text-color wp-element-button" href="#contenido">Conocer más</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div></div>
<!-- /wp:cover -->
