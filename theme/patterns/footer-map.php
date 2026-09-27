<?php
/**
 * Title: Mapa del footer
 * Slug: vicunav-bonasera/footer-map
 * Categories: vicunav-bonasera
 * Description: Imagen del mapa que acompaña la información de contacto del footer completo.
 * Inserter: no
 *
 * @package Vicunav_Bonasera
 */

defined( 'ABSPATH' ) || exit;

?>

<!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"vicunav-restaurant-footer__media"} -->
<figure class="wp-block-image size-full vicunav-restaurant-footer__media"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/mapa-maracaibo.webp' ) ); ?>" alt="<?php echo esc_attr_x( 'Mapa de Maracaibo', 'Texto alternativo del mapa del footer.', 'vicunav-bonasera' ); ?>"/></figure>
<!-- /wp:image -->
