<?php
/**
 * Title: Hero dividido con imagen
 * Slug: vicunav-bonasera/hero-split-image
 * Categories: vicunav-bonasera
 * Description: Presentación principal en dos columnas con contenido y media editable.
 * Inserter: yes
 *
 * @package Vicunav_Bonasera
 */

defined( 'ABSPATH' ) || exit;

?>

<!-- wp:group {"tagName":"section","metadata":{"name":"Hero dividido con imagen"},"align":"full","className":"vicunav-pattern-section vicunav-pattern-split-hero","backgroundColor":"vicunav-neutral-100","textColor":"vicunav-neutral-900","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull vicunav-pattern-section vicunav-pattern-split-hero has-vicunav-neutral-900-color has-vicunav-neutral-100-background-color has-text-color has-background"><!-- wp:columns {"verticalAlignment":"center","className":"vicunav-pattern-split-hero__columns"} -->
<div class="wp-block-columns are-vertically-aligned-center vicunav-pattern-split-hero__columns"><!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center"><!-- wp:group {"className":"vicunav-pattern-split-hero__copy","style":{"spacing":{"blockGap":"var:preset|spacing|vicunav-space-md"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group vicunav-pattern-split-hero__copy"><!-- wp:heading {"level":1,"textColor":"vicunav-neutral-900","fontSize":"vicunav-xl","fontFamily":"vicunav-heading"} -->
<h1 class="wp-block-heading has-vicunav-neutral-900-color has-text-color has-vicunav-heading-font-family has-vicunav-xl-font-size">Haz visible tu propuesta</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"vicunav-neutral-700","fontSize":"vicunav-lg","fontFamily":"vicunav-body"} -->
<p class="has-vicunav-neutral-700-color has-text-color has-vicunav-body-font-family has-vicunav-lg-font-size">Combina un mensaje claro con una imagen que represente la experiencia del negocio.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"vicunav-primary","textColor":"vicunav-neutral-100","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
<div class="wp-block-button has-custom-font-size has-vicunav-body-font-family has-vicunav-md-font-size"><a class="wp-block-button__link has-vicunav-neutral-100-color has-vicunav-primary-background-color has-text-color has-background wp-element-button" href="#accion-principal">Acción principal</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center"><!-- wp:cover {"dimRatio":0,"overlayColor":"vicunav-neutral-300","isDark":false,"minHeight":460,"minHeightUnit":"px","className":"vicunav-pattern-split-hero__media"} -->
<div class="wp-block-cover is-light vicunav-pattern-split-hero__media" style="min-height:460px"><span aria-hidden="true" class="wp-block-cover__background has-vicunav-neutral-300-background-color has-background-dim-0 has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph {"align":"center","textColor":"vicunav-neutral-700","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
<p class="has-text-align-center has-vicunav-neutral-700-color has-text-color has-vicunav-body-font-family has-vicunav-md-font-size">Selecciona una imagen para este espacio</p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></section>
<!-- /wp:group -->
