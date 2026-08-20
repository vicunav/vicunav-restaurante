<?php
/**
 * Prepara datos técnicos mínimos para el gate E2E en un WordPress desechable.
 *
 * Se ejecuta después de activar core, pagos y restaurante:
 * wp eval-file tests/e2e/seed.php
 *
 * @package Vicunav_Restaurante
 */

use Vicu\Pagos\ManualPaymentProvider;
use Vicu\Restaurante\Catalog\IngredientService;
use Vicu\Restaurante\Catalog\PizzaOptionService;
use Vicu\Restaurante\Commerce\DeliveryZoneService;
use Vicu\Restaurante\Menu\MenuCategory;
use Vicu\Restaurante\Menu\MenuItemPostType;
use Vicu\Restaurante\Menu\MenuMeta;
use Vicu\Restaurante\Reservation\ReservationSettings;
use Vicu\Restaurante\Settings\RestaurantSettings;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$fail_on_error = static function ( mixed $result, string $operation ): mixed {
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( $operation . ': ' . $result->get_error_message() );
	}

	return $result;
};

$fail_on_error( ManualPaymentProvider::configure( array( 'enabled' => true ) ), 'Proveedor manual' );

if ( array() === PizzaOptionService::all() ) {
	foreach ( array( array( 'Mediana', 'size', 850 ), array( 'Clásica', 'crust', 0 ), array( 'Tomate', 'sauce', 0 ) ) as $option ) {
		$fail_on_error(
			PizzaOptionService::create(
				array(
					'name'                 => $option[0],
					'type'                 => $option[1],
					'price_modifier_minor' => $option[2],
					'available'            => true,
					'display_order'        => 1,
				)
			),
			'Opción de pizza'
		);
	}
}

if ( array() === IngredientService::all() ) {
	foreach ( array( array( 'Mozzarella', 'cheese', 75 ), array( 'Albahaca', 'topping', 100 ), array( 'Champiñón', 'topping', 120 ) ) as $ingredient ) {
		$fail_on_error(
			IngredientService::create(
				array(
					'name'                 => $ingredient[0],
					'category'             => $ingredient[1],
					'price_modifier_minor' => $ingredient[2],
					'available'            => true,
					'allergens'            => 'cheese' === $ingredient[1] ? array( 'milk' ) : array(),
					'dietary_tags'         => array( 'vegetarian' ),
				)
			),
			'Ingrediente'
		);
	}
}

if ( array() === DeliveryZoneService::all() ) {
	$fail_on_error(
		DeliveryZoneService::create(
			array(
				'name'            => 'Zona técnica',
				'active'          => true,
				'fee_minor'       => 250,
				'eta_min_minutes' => 20,
				'eta_max_minutes' => 35,
				'display_order'   => 1,
			)
		),
		'Zona de entrega'
	);
}

$category_result = term_exists( 'Platos técnicos', MenuCategory::TAXONOMY );
if ( ! $category_result ) {
	$category_result = wp_insert_term( 'Platos técnicos', MenuCategory::TAXONOMY, array( 'slug' => 'platos-tecnicos' ) );
}
$fail_on_error( $category_result, 'Categoría de menú' );
$term_id = (int) ( is_array( $category_result ) ? $category_result['term_id'] : $category_result );
update_term_meta( $term_id, MenuCategory::META_ORDER, 1 );
update_term_meta( $term_id, MenuCategory::META_VISIBLE, true );

$menu_item = get_page_by_path( 'plato-tecnico', OBJECT, MenuItemPostType::POST_TYPE );

if ( ! $menu_item ) {
	$item_id = wp_insert_post(
		array(
			'post_type'    => MenuItemPostType::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => 'Plato técnico',
			'post_name'    => 'plato-tecnico',
			'post_excerpt' => 'Elemento neutro para validar filtros, disponibilidad y carrito.',
		)
	);
	if ( is_wp_error( $item_id ) || 1 > $item_id ) {
		WP_CLI::error( 'No se pudo crear el elemento de menú.' );
	}
	wp_set_object_terms( $item_id, array( $term_id ), MenuCategory::TAXONOMY );
	update_post_meta( $item_id, MenuMeta::PUBLIC_ID, wp_generate_uuid4() );
	update_post_meta( $item_id, MenuMeta::PRICE_MINOR, 1250 );
	update_post_meta( $item_id, MenuMeta::CURRENCY, 'USD' );
	update_post_meta( $item_id, MenuMeta::AVAILABLE, true );
	update_post_meta( $item_id, MenuMeta::CALORIES_KCAL, 420 );
	update_post_meta( $item_id, MenuMeta::ALLERGENS, array( 'gluten', 'milk' ) );
	update_post_meta( $item_id, MenuMeta::DIETARY_TAGS, array( 'vegetarian' ) );
}

update_option(
	RestaurantSettings::OPTION_NAME,
	array(
		'currency'                    => 'USD',
		'tax_rate_bps'                => 800,
		'tip_rates_bps'               => array( 0, 1000, 1500, 2000 ),
		'cart_lifetime_hours'         => 72,
		'payment_lifetime_minutes'    => 30,
		'manual_payment_instructions' => 'Registra una referencia opaca para completar la validación técnica.',
	),
	false
);

$schedule = array_fill_keys( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ), array() );
foreach ( array_keys( $schedule ) as $day ) {
	$schedule[ $day ] = array(
		array(
			'opens_at'  => '00:00',
			'closes_at' => '23:59',
		),
	);
}
update_option(
	ReservationSettings::OPTION_NAME,
	array(
		'timezone'              => 'America/Caracas',
		'weekly_schedule'       => $schedule,
		'exceptions'            => array(),
		'recurring_closures'    => array(),
		'interval_minutes'      => 30,
		'duration_minutes'      => 90,
		'capacity'              => 20,
		'min_party_size'        => 1,
		'max_party_size'        => 12,
		'min_notice_minutes'    => 0,
		'limited_threshold_bps' => 2500,
		'auto_confirm'          => false,
	),
	false
);

$gate_page = get_page_by_path( 'gate-rest-02r' );
if ( ! $gate_page ) {
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Gate REST-02R',
			'post_name'    => 'gate-rest-02r',
			'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Menú</h2><!-- /wp:heading --><!-- wp:vicunav/restaurante-menu /--><!-- wp:heading --><h2 class="wp-block-heading">Constructor</h2><!-- /wp:heading --><!-- wp:vicunav/restaurante-pizza-builder /--><!-- wp:heading --><h2 class="wp-block-heading">Carrito</h2><!-- /wp:heading --><!-- wp:vicunav/restaurante-cart /--><!-- wp:heading --><h2 class="wp-block-heading">Checkout</h2><!-- /wp:heading --><!-- wp:vicunav/restaurante-checkout /--><!-- wp:heading --><h2 class="wp-block-heading">Pedido</h2><!-- /wp:heading --><!-- wp:vicunav/restaurante-order-status /--><!-- wp:heading --><h2 class="wp-block-heading">Reservas</h2><!-- /wp:heading --><!-- wp:vicunav/restaurante-reservations /--><!-- wp:heading --><h2 class="wp-block-heading">Pizzas guardadas</h2><!-- /wp:heading --><!-- wp:vicunav/restaurante-saved-pizzas /-->',
		)
	);
	if ( is_wp_error( $page_id ) || 1 > $page_id ) {
		WP_CLI::error( 'No se pudo crear la página del gate.' );
	}
}

WP_CLI::success( home_url( '/gate-rest-02r/' ) );
