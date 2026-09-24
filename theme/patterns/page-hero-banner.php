<?php
/**
 * Title: Banner interior con imagen
 * Slug: vicunav-bonasera/page-hero-banner
 * Categories: vicunav-bonasera
 * Description: Banner compacto para vistas interiores con título y texto sobre media editable.
 * Inserter: yes
 *
 * @package Vicunav_Bonasera
 */

defined( 'ABSPATH' ) || exit;

?>

<!-- wp:cover {"dimRatio":70,"overlayColor":"vicunav-neutral-900","minHeight":210,"minHeightUnit":"px","isUserOverlayColor":true,"contentPosition":"center left","align":"full","className":"vicunav-pattern-page-hero","layout":{"type":"constrained"}} -->
<div class="wp-block-cover alignfull has-custom-content-position is-position-center-left vicunav-pattern-page-hero" style="min-height:210px"><span aria-hidden="true" class="wp-block-cover__background has-vicunav-neutral-900-background-color has-background-dim-70 has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:group {"className":"vicunav-pattern-page-hero__content","style":{"spacing":{"blockGap":"var:preset|spacing|vicunav-space-xs"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group vicunav-pattern-page-hero__content"><!-- wp:heading {"level":1,"textColor":"vicunav-neutral-100","fontSize":"vicunav-xl","fontFamily":"vicunav-heading","className":"vicunav-pattern-page-hero__title"} -->
<h1 class="wp-block-heading vicunav-pattern-page-hero__title has-vicunav-neutral-100-color has-text-color has-vicunav-heading-font-family has-vicunav-xl-font-size">Título de la página</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"vicunav-neutral-300","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
<p class="has-vicunav-neutral-300-color has-text-color has-vicunav-body-font-family has-vicunav-md-font-size">Describe brevemente el contenido de esta vista.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div></div>
<!-- /wp:cover -->
