<?php
/**
 * Title: Testimonios en cuadrícula
 * Slug: vicunav-bonasera/testimonials-grid
 * Categories: vicunav-bonasera
 * Description: Cuadrícula de testimonios obtenidos del tipo de contenido vicu_testimonial.
 * Inserter: yes
 *
 * @package Vicunav_Bonasera
 */

defined( 'ABSPATH' ) || exit;

?>

<!-- wp:group {"tagName":"section","metadata":{"name":"Testimonios en cuadrícula"},"align":"full","className":"vicunav-pattern-section vicunav-testimonials-grid","backgroundColor":"vicunav-neutral-100","textColor":"vicunav-neutral-900","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull vicunav-pattern-section vicunav-testimonials-grid has-vicunav-neutral-900-color has-vicunav-neutral-100-background-color has-text-color has-background"><!-- wp:heading {"textColor":"vicunav-neutral-900","fontSize":"vicunav-xl","fontFamily":"vicunav-heading","className":"vicunav-section-heading"} -->
<h2 class="wp-block-heading vicunav-section-heading has-vicunav-neutral-900-color has-text-color has-vicunav-heading-font-family has-vicunav-xl-font-size">Lo que dicen nuestros clientes</h2>
<!-- /wp:heading -->

<!-- wp:query {"query":{"perPage":6,"pages":0,"offset":0,"postType":"vicu_testimonial","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->
<div class="wp-block-query"><!-- wp:post-template {"layout":{"type":"grid","columnCount":3}} -->
<!-- wp:group {"className":"vicunav-testimonials-grid__card","backgroundColor":"vicunav-neutral-300","textColor":"vicunav-neutral-900","style":{"spacing":{"padding":{"top":"var:preset|spacing|vicunav-space-lg","right":"var:preset|spacing|vicunav-space-lg","bottom":"var:preset|spacing|vicunav-space-lg","left":"var:preset|spacing|vicunav-space-lg"},"blockGap":"var:preset|spacing|vicunav-space-sm"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group vicunav-testimonials-grid__card has-vicunav-neutral-900-color has-vicunav-neutral-300-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--vicunav-space-lg);padding-right:var(--wp--preset--spacing--vicunav-space-lg);padding-bottom:var(--wp--preset--spacing--vicunav-space-lg);padding-left:var(--wp--preset--spacing--vicunav-space-lg)"><!-- wp:post-content {"textColor":"vicunav-neutral-800","fontSize":"vicunav-md","fontFamily":"vicunav-body","layout":{"type":"constrained"}} /-->

<!-- wp:post-title {"level":3,"isLink":false,"textColor":"vicunav-primary","fontSize":"vicunav-lg","fontFamily":"vicunav-heading"} /--></div>
<!-- /wp:group -->
<!-- /wp:post-template -->

<!-- wp:query-no-results -->
<!-- wp:group {"backgroundColor":"vicunav-neutral-200","textColor":"vicunav-neutral-700","style":{"spacing":{"padding":{"top":"var:preset|spacing|vicunav-space-lg","right":"var:preset|spacing|vicunav-space-lg","bottom":"var:preset|spacing|vicunav-space-lg","left":"var:preset|spacing|vicunav-space-lg"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-vicunav-neutral-700-color has-vicunav-neutral-200-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--vicunav-space-lg);padding-right:var(--wp--preset--spacing--vicunav-space-lg);padding-bottom:var(--wp--preset--spacing--vicunav-space-lg);padding-left:var(--wp--preset--spacing--vicunav-space-lg)"><!-- wp:paragraph {"align":"center","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
<p class="has-text-align-center has-vicunav-body-font-family has-vicunav-md-font-size">Aún no hay testimonios publicados. Este espacio se actualizará al añadir el primero.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<!-- /wp:query-no-results --></div>
<!-- /wp:query --></section>
<!-- /wp:group -->
