<?php
/**
 * Title: Preguntas frecuentes en acordeón
 * Slug: vicunav-bonasera/faq-accordion
 * Categories: vicunav-bonasera
 * Description: Acordeón progresivo obtenido del tipo de contenido vicu_faq.
 * Inserter: yes
 *
 * @package Vicunav_Bonasera
 */

defined( 'ABSPATH' ) || exit;

?>

<!-- wp:group {"tagName":"section","metadata":{"name":"Preguntas frecuentes en acordeón"},"align":"full","className":"vicunav-pattern-section vicunav-faq-accordion","backgroundColor":"vicunav-neutral-200","textColor":"vicunav-neutral-900","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull vicunav-pattern-section vicunav-faq-accordion has-vicunav-neutral-900-color has-vicunav-neutral-200-background-color has-text-color has-background"><!-- wp:heading {"textColor":"vicunav-neutral-900","fontSize":"vicunav-xl","fontFamily":"vicunav-heading","className":"vicunav-section-heading"} -->
<h2 class="wp-block-heading vicunav-section-heading has-vicunav-neutral-900-color has-text-color has-vicunav-heading-font-family has-vicunav-xl-font-size">Preguntas frecuentes</h2>
<!-- /wp:heading -->

<!-- wp:query {"query":{"perPage":10,"pages":0,"offset":0,"postType":"vicu_faq","order":"asc","orderBy":"menu_order","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->
<div class="wp-block-query"><!-- wp:post-template {"layout":{"type":"default"}} -->
<!-- wp:group {"className":"vicunav-faq-accordion__item","backgroundColor":"vicunav-neutral-300","textColor":"vicunav-neutral-900","style":{"spacing":{"padding":{"top":"var:preset|spacing|vicunav-space-md","right":"var:preset|spacing|vicunav-space-md","bottom":"var:preset|spacing|vicunav-space-md","left":"var:preset|spacing|vicunav-space-md"},"blockGap":"var:preset|spacing|vicunav-space-sm"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group vicunav-faq-accordion__item has-vicunav-neutral-900-color has-vicunav-neutral-300-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--vicunav-space-md);padding-right:var(--wp--preset--spacing--vicunav-space-md);padding-bottom:var(--wp--preset--spacing--vicunav-space-md);padding-left:var(--wp--preset--spacing--vicunav-space-md)"><!-- wp:group {"className":"vicunav-faq-accordion__question-row","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
<div class="wp-block-group vicunav-faq-accordion__question-row"><!-- wp:post-title {"level":3,"isLink":false,"textColor":"vicunav-neutral-900","fontSize":"vicunav-lg","fontFamily":"vicunav-heading"} /-->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"tagName":"button","type":"button","backgroundColor":"vicunav-primary","textColor":"vicunav-neutral-100","className":"vicunav-faq-accordion__toggle","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
<div class="wp-block-button vicunav-faq-accordion__toggle has-custom-font-size has-vicunav-body-font-family has-vicunav-md-font-size"><button type="button" class="wp-block-button__link has-vicunav-neutral-100-color has-vicunav-primary-background-color has-text-color has-background wp-element-button">Mostrar respuesta</button></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"vicunav-faq-accordion__answer","textColor":"vicunav-neutral-800","layout":{"type":"constrained"}} -->
<div class="wp-block-group vicunav-faq-accordion__answer has-vicunav-neutral-800-color has-text-color"><!-- wp:post-content {"fontSize":"vicunav-md","fontFamily":"vicunav-body","layout":{"type":"constrained"}} /--></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
<!-- /wp:post-template -->

<!-- wp:query-no-results -->
<!-- wp:paragraph {"align":"center","textColor":"vicunav-neutral-700","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
<p class="has-text-align-center has-vicunav-neutral-700-color has-text-color has-vicunav-body-font-family has-vicunav-md-font-size">Aún no hay preguntas frecuentes publicadas.</p>
<!-- /wp:paragraph -->
<!-- /wp:query-no-results --></div>
<!-- /wp:query --></section>
<!-- /wp:group -->
