<?php
/**
 * Title: Información de contacto
 * Slug: vicunav-bonasera/contact-info
 * Categories: vicunav-bonasera
 * Description: Teléfono, dirección y horario obtenidos de los ajustes compartidos.
 * Inserter: yes
 *
 * @package Vicunav_Bonasera
 */

defined( 'ABSPATH' ) || exit;

$vicunav_contact_values = array(
	'phone'          => 'Agrega aquí el teléfono del negocio',
	'address'        => 'Agrega aquí la dirección del negocio',
	'business_hours' => 'Agrega aquí el horario de atención',
);

if ( class_exists( '\\Vicu\\Restaurante\\Shared\\Settings' ) && is_callable( array( '\\Vicu\\Restaurante\\Shared\\Settings', 'get' ) ) ) {
	foreach ( array_keys( $vicunav_contact_values ) as $vicunav_contact_key ) {
		$vicunav_contact_value = \Vicu\Restaurante\Shared\Settings::get( $vicunav_contact_key );

		if ( is_scalar( $vicunav_contact_value ) && '' !== trim( (string) $vicunav_contact_value ) ) {
			$vicunav_contact_values[ $vicunav_contact_key ] = (string) $vicunav_contact_value;
		}
	}
}
?>

<!-- wp:group {"tagName":"section","metadata":{"name":"Información de contacto"},"align":"full","className":"vicunav-pattern-section vicunav-contact-info","backgroundColor":"vicunav-neutral-100","textColor":"vicunav-neutral-900","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull vicunav-pattern-section vicunav-contact-info has-vicunav-neutral-900-color has-vicunav-neutral-100-background-color has-text-color has-background"><!-- wp:heading {"textColor":"vicunav-neutral-900","fontSize":"vicunav-xl","fontFamily":"vicunav-heading","className":"vicunav-section-heading"} -->
<h2 class="wp-block-heading vicunav-section-heading has-vicunav-neutral-900-color has-text-color has-vicunav-heading-font-family has-vicunav-xl-font-size">Contáctanos</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"vicunav-neutral-700","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
<p class="has-vicunav-neutral-700-color has-text-color has-vicunav-body-font-family has-vicunav-md-font-size">Elige el canal que prefieras o consulta la información del negocio.</p>
<!-- /wp:paragraph -->

<!-- wp:columns {"className":"vicunav-contact-info__columns"} -->
<div class="wp-block-columns vicunav-contact-info__columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"className":"vicunav-contact-info__card vicunav-contact-info__card--primary","backgroundColor":"vicunav-neutral-900","textColor":"vicunav-neutral-100","style":{"spacing":{"padding":{"top":"var:preset|spacing|vicunav-space-lg","right":"var:preset|spacing|vicunav-space-lg","bottom":"var:preset|spacing|vicunav-space-lg","left":"var:preset|spacing|vicunav-space-lg"},"blockGap":"var:preset|spacing|vicunav-space-sm"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group vicunav-contact-info__card vicunav-contact-info__card--primary has-vicunav-neutral-100-color has-vicunav-neutral-900-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--vicunav-space-lg);padding-right:var(--wp--preset--spacing--vicunav-space-lg);padding-bottom:var(--wp--preset--spacing--vicunav-space-lg);padding-left:var(--wp--preset--spacing--vicunav-space-lg)"><!-- wp:heading {"level":3,"textColor":"vicunav-neutral-100","fontSize":"vicunav-lg","fontFamily":"vicunav-heading"} -->
<h3 class="wp-block-heading has-vicunav-neutral-100-color has-text-color has-vicunav-heading-font-family has-vicunav-lg-font-size">Teléfono</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"textColor":"vicunav-neutral-300","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
<p class="has-vicunav-neutral-300-color has-text-color has-vicunav-body-font-family has-vicunav-md-font-size"><?php echo esc_html( $vicunav_contact_values['phone'] ); ?></p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"vicunav-neutral-100","textColor":"vicunav-neutral-900","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
<div class="wp-block-button has-custom-font-size has-vicunav-body-font-family has-vicunav-md-font-size"><a class="wp-block-button__link has-vicunav-neutral-900-color has-vicunav-neutral-100-background-color has-text-color has-background wp-element-button" href="#contactar">Contactar</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"className":"vicunav-contact-info__card","backgroundColor":"vicunav-neutral-300","textColor":"vicunav-neutral-900","style":{"spacing":{"padding":{"top":"var:preset|spacing|vicunav-space-lg","right":"var:preset|spacing|vicunav-space-lg","bottom":"var:preset|spacing|vicunav-space-lg","left":"var:preset|spacing|vicunav-space-lg"},"blockGap":"var:preset|spacing|vicunav-space-md"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group vicunav-contact-info__card has-vicunav-neutral-900-color has-vicunav-neutral-300-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--vicunav-space-lg);padding-right:var(--wp--preset--spacing--vicunav-space-lg);padding-bottom:var(--wp--preset--spacing--vicunav-space-lg);padding-left:var(--wp--preset--spacing--vicunav-space-lg)"><!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:heading {"level":3,"textColor":"vicunav-neutral-900","fontSize":"vicunav-lg","fontFamily":"vicunav-heading"} -->
<h3 class="wp-block-heading has-vicunav-neutral-900-color has-text-color has-vicunav-heading-font-family has-vicunav-lg-font-size">Dirección</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"textColor":"vicunav-neutral-800","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
<p class="has-vicunav-neutral-800-color has-text-color has-vicunav-body-font-family has-vicunav-md-font-size"><?php echo esc_html( $vicunav_contact_values['address'] ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:heading {"level":3,"textColor":"vicunav-neutral-900","fontSize":"vicunav-lg","fontFamily":"vicunav-heading"} -->
<h3 class="wp-block-heading has-vicunav-neutral-900-color has-text-color has-vicunav-heading-font-family has-vicunav-lg-font-size">Horario</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"textColor":"vicunav-neutral-800","fontSize":"vicunav-md","fontFamily":"vicunav-body"} -->
<p class="has-vicunav-neutral-800-color has-text-color has-vicunav-body-font-family has-vicunav-md-font-size"><?php echo esc_html( $vicunav_contact_values['business_hours'] ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></section>
<!-- /wp:group -->
