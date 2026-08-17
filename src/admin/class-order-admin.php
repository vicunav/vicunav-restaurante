<?php
/**
 * Administración protegida de pedidos autoritativos.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Admin;

use Vicu\Restaurante\Order\OrderPostType;
use Vicu\Restaurante\Order\OrderProjection;
use Vicu\Restaurante\Order\OrderService;
use Vicu\Restaurante\Order\OrderStateMachine;
use Vicu\Restaurante\Order\PaymentEvidenceService;
use Vicu\Restaurante\Order\PaymentIntegration;
use Vicu\Pagos\ManualPaymentProvider;
use WP_Post;

/**
 * Renderiza snapshots y delega cada mutación al servicio con revisión.
 */
final class OrderAdmin {
	private const HEALTH_PAGE = 'vicu-restaurante-order-health';

	/**
	 * Evita hooks duplicados.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Registra columnas, detalle, transiciones y reparación.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'add_meta_boxes_' . OrderPostType::POST_TYPE, array( self::class, 'add_meta_box' ) );
		add_action( 'admin_post_vicu_restaurante_transition_order', array( self::class, 'transition' ) );
		add_action( 'admin_post_vicu_restaurante_rebuild_orders', array( self::class, 'rebuild' ) );
		add_action( 'admin_post_vicu_restaurante_reconcile_order', array( self::class, 'reconcile' ) );
		add_action( 'admin_post_vicu_restaurante_reconcile_payments', array( self::class, 'reconcile_all' ) );
		add_action( 'admin_menu', array( self::class, 'register_health_page' ), 30 );
		add_filter( 'manage_' . OrderPostType::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . OrderPostType::POST_TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		self::$hooks_registered = true;
	}

	/**
	 * Añade un detalle no editable.
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		add_meta_box( 'vicu_restaurante_order_detail', __( 'Pedido autoritativo', 'vicunav-restaurante' ), array( self::class, 'render_detail' ), OrderPostType::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Renderiza datos privados solo para capability de lectura.
	 *
	 * @param WP_Post $post Proyección.
	 * @return void
	 */
	public static function render_detail( WP_Post $post ): void {
		self::require_capability( 'view_vicu_restaurant_orders' );
		$public_id = (string) get_post_meta( $post->ID, OrderPostType::META_PUBLIC_ID, true );
		$order     = OrderService::admin_detail( $public_id );

		if ( null === $order ) {
			echo '<p>' . esc_html__( 'La proyección no encuentra su pedido autoritativo.', 'vicunav-restaurante' ) . '</p>';
			return;
		}
		?>
		<p><strong><?php echo esc_html( $order['order_number'] ); ?></strong> · <?php echo esc_html( $order['status'] ); ?> · <?php echo esc_html( $order['currency'] . ' ' . (string) $order['totals']['total'] ); ?></p>
		<p><?php echo esc_html( $order['customer']['name'] . ' · ' . $order['customer']['phone'] ); ?></p>
		<?php
		if ( null !== $order['customer']['email'] ) :
			?>
			<p><?php echo esc_html( $order['customer']['email'] ); ?></p><?php endif; ?>
		<?php
		if ( null !== $order['delivery_address'] ) :
			?>
			<p><?php echo esc_html( $order['delivery_address'] ); ?></p><?php endif; ?>
		<h3><?php echo esc_html__( 'Líneas', 'vicunav-restaurante' ); ?></h3>
		<ul>
		<?php
		foreach ( $order['items'] as $item ) :
			?>
			<li><?php echo esc_html( (string) $item['quantity'] . ' × ' . (string) $item['snapshot']['name'] . ' - ' . (string) $item['line_total_minor'] ); ?></li><?php endforeach; ?></ul>
		<h3><?php echo esc_html__( 'Eventos', 'vicunav-restaurante' ); ?></h3>
		<ol>
		<?php
		foreach ( $order['events'] as $event ) :
			?>
			<li><?php echo esc_html( $event['created_at'] . ' · ' . ( null === $event['from'] ? 'creación' : $event['from'] ) . ' → ' . $event['to'] ); ?></li><?php endforeach; ?></ol>
		<h3><?php echo esc_html__( 'Pago manual', 'vicunav-restaurante' ); ?></h3>
		<p><?php echo esc_html( $order['payment']['state'] ?? 'sin solicitud' ); ?> · <?php echo esc_html( $order['payment_sync_status'] ); ?> · <?php echo esc_html( (string) $order['payment']['revision'] ); ?></p>
		<?php
		if ( null !== $order['payment_last_error'] ) :
			?>
			<p class="notice notice-error inline"><?php echo esc_html( $order['payment_last_error'] ); ?></p><?php endif; ?>
		<?php self::render_evidence( $order ); ?>
		<?php self::render_reconciliation_form( $order ); ?>
		<?php self::render_transition_form( $order ); ?>
		<?php
	}

	/**
	 * Ejecuta solo transiciones operativas autorizadas.
	 *
	 * @return void
	 */
	public static function transition(): void {
		$target = self::request_text( 'target' );
		$cap    = 'cancelado' === $target ? 'manage_vicu_restaurant_orders' : 'fulfill_vicu_restaurant_orders';
		self::require_capability( $cap );
		check_admin_referer( 'vicu_restaurante_transition_order' );

		$allowed = array( 'en_preparacion', 'listo', 'en_reparto', 'completado', 'cancelado' );
		$result  = in_array( $target, $allowed, true )
			? OrderService::transition(
				self::request_text( 'public_id' ),
				absint( self::request_text( 'expected_revision' ) ),
				$target,
				'operator',
				get_current_user_id(),
				self::request_text( 'reason' )
			)
			: null;
		$status  = null === $result || is_wp_error( $result ) ? ( is_wp_error( $result ) ? $result->get_error_code() : 'vicu_restaurante_invalid_transition' ) : 'transitioned';

		$redirect_url = wp_get_referer();
		if ( false === $redirect_url ) {
			$redirect_url = admin_url( 'edit.php?post_type=' . OrderPostType::POST_TYPE );
		}
		wp_safe_redirect( add_query_arg( 'vicu_order_status', rawurlencode( $status ), $redirect_url ) );
		exit;
	}

	/**
	 * Reconstruye proyecciones de forma idempotente.
	 *
	 * @return void
	 */
	public static function rebuild(): void {
		self::require_capability( 'manage_vicu_restaurant_orders' );
		check_admin_referer( 'vicu_restaurante_rebuild_orders' );
		$result = OrderProjection::rebuild();
		wp_safe_redirect(
			add_query_arg(
				array(
					'synced' => $result['synced'],
					'failed' => $result['failed'],
				),
				admin_url( 'admin.php?page=' . self::HEALTH_PAGE )
			)
		);
		exit;
	}

	/**
	 * Reconcilia un pedido mediante el servicio público de pagos.
	 *
	 * @return void
	 */
	public static function reconcile(): void {
		self::require_capability( 'reconcile_vicu_restaurant_payments' );
		check_admin_referer( 'vicu_restaurante_reconcile_order' );
		$result = PaymentIntegration::reconcile_order( self::request_text( 'public_id' ) );
		$status = is_wp_error( $result ) ? $result->get_error_code() : 'reconciled';
		wp_safe_redirect( add_query_arg( 'vicu_payment_status', rawurlencode( $status ), admin_url( 'edit.php?post_type=' . OrderPostType::POST_TYPE ) ) );
		exit;
	}

	/**
	 * Reconcilia el lote acotado desde salud.
	 *
	 * @return void
	 */
	public static function reconcile_all(): void {
		self::require_capability( 'reconcile_vicu_restaurant_payments' );
		check_admin_referer( 'vicu_restaurante_reconcile_payments' );
		$result = PaymentIntegration::reconcile_due();
		wp_safe_redirect(
			add_query_arg(
				array(
					'payments_synced' => $result['synced'],
					'payments_failed' => $result['failed'],
				),
				admin_url( 'admin.php?page=' . self::HEALTH_PAGE )
			)
		);
		exit;
	}

	/**
	 * Registra una superficie de salud reconstruible.
	 *
	 * @return void
	 */
	public static function register_health_page(): void {
		add_submenu_page( 'vicunav', __( 'Salud de pedidos', 'vicunav-restaurante' ), __( 'Salud de pedidos', 'vicunav-restaurante' ), 'manage_vicu_restaurant_orders', self::HEALTH_PAGE, array( self::class, 'render_health' ) );
	}

	/**
	 * Renderiza la acción segura de reparación.
	 *
	 * @return void
	 */
	public static function render_health(): void {
		self::require_capability( 'manage_vicu_restaurant_orders' );
		$pending        = count( array_filter( OrderService::admin_list(), static fn( array $order ): bool => 'pending' === ( OrderService::admin_detail( $order['public_id'] )['projection_status'] ?? '' ) ) );
		$payment_errors = count( array_filter( OrderService::admin_list(), static fn( array $order ): bool => 'error' === ( $order['payment_sync_status'] ?? '' ) ) );
		$manual         = ManualPaymentProvider::get_configuration();
		?>
		<div class="wrap"><h1><?php echo esc_html__( 'Salud de pedidos', 'vicunav-restaurante' ); ?></h1>
		<p><?php echo esc_html( sprintf( /* translators: %d: proyecciones pendientes. */ __( 'Proyecciones pendientes: %d', 'vicunav-restaurante' ), $pending ) ); ?></p>
		<p><?php echo esc_html( sprintf( /* translators: %d: pagos con error. */ __( 'Pagos con error: %d', 'vicunav-restaurante' ), $payment_errors ) ); ?></p>
		<p><?php echo esc_html( true === ( $manual['enabled'] ?? false ) ? __( 'Proveedor manual habilitado.', 'vicunav-restaurante' ) : __( 'Proveedor manual deshabilitado.', 'vicunav-restaurante' ) ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="vicu_restaurante_rebuild_orders"><?php wp_nonce_field( 'vicu_restaurante_rebuild_orders' ); ?><?php submit_button( __( 'Reconstruir proyecciones', 'vicunav-restaurante' ) ); ?></form>
		<?php
		if ( current_user_can( 'reconcile_vicu_restaurant_payments' ) ) :
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="vicu_restaurante_reconcile_payments"><?php wp_nonce_field( 'vicu_restaurante_reconcile_payments' ); ?><?php submit_button( __( 'Reconciliar pagos', 'vicunav-restaurante' ) ); ?></form><?php endif; ?></div>
		<?php
	}

	/**
	 * Añade columnas operativas.
	 *
	 * @param array<string, string> $columns Columnas.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		return array(
			'cb'          => $columns['cb'] ?? '<input type="checkbox">',
			'title'       => __( 'Pedido', 'vicunav-restaurante' ),
			'vicu_status' => __( 'Estado', 'vicunav-restaurante' ),
			'vicu_total'  => __( 'Total', 'vicunav-restaurante' ),
			'date'        => $columns['date'] ?? __( 'Fecha', 'vicunav-restaurante' ),
		);
	}

	/**
	 * Renderiza columnas desde la autoridad, no desde meta.
	 *
	 * @param string $column  Columna.
	 * @param int    $post_id Post.
	 * @return void
	 */
	public static function column( string $column, int $post_id ): void {
		$order = OrderService::admin_detail( (string) get_post_meta( $post_id, OrderPostType::META_PUBLIC_ID, true ) );

		if ( null === $order ) {
			return;
		}

		if ( 'vicu_status' === $column ) {
			echo esc_html( $order['status'] );
		} elseif ( 'vicu_total' === $column ) {
			echo esc_html( $order['currency'] . ' ' . (string) $order['totals']['total'] );
		}
	}

	/**
	 * Retira acciones de borrado y edición rápida.
	 *
	 * @param array<string, string> $actions Acciones.
	 * @param WP_Post               $post    Proyección.
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( OrderPostType::POST_TYPE === $post->post_type ) {
			unset( $actions['trash'], $actions['inline hide-if-no-js'] );
		}

		return $actions;
	}

	/**
	 * Formulario de transición con revisión.
	 *
	 * @param array<string, mixed> $order Pedido.
	 * @return void
	 */
	private static function render_transition_form( array $order ): void {
		if ( OrderStateMachine::is_terminal( $order['status'] ) || ( ! current_user_can( 'fulfill_vicu_restaurant_orders' ) && ! current_user_can( 'manage_vicu_restaurant_orders' ) ) ) {
			return;
		}
		?>
		<h3><?php echo esc_html__( 'Transición operativa', 'vicunav-restaurante' ); ?></h3><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="vicu_restaurante_transition_order"><input type="hidden" name="public_id" value="<?php echo esc_attr( $order['public_id'] ); ?>"><input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) $order['revision'] ); ?>"><?php wp_nonce_field( 'vicu_restaurante_transition_order' ); ?><select name="target">
		<?php
		foreach ( array( 'en_preparacion', 'listo', 'en_reparto', 'completado', 'cancelado' ) as $target ) :
			?>
			<option value="<?php echo esc_attr( $target ); ?>"><?php echo esc_html( $target ); ?></option><?php endforeach; ?></select><label><?php echo esc_html__( 'Motivo', 'vicunav-restaurante' ); ?> <input name="reason" maxlength="500"></label><?php submit_button( __( 'Aplicar transición', 'vicunav-restaurante' ), 'secondary', 'submit', false ); ?></form>
		<?php
	}

	/**
	 * Renderiza referencias privadas solo con capability específica.
	 *
	 * @param array<string, mixed> $order Pedido administrativo.
	 * @return void
	 */
	private static function render_evidence( array $order ): void {
		if ( ! current_user_can( 'view_vicu_restaurant_payment_evidence' ) ) {
			return;
		}

		$evidence = PaymentEvidenceService::admin_for_order( (int) $order['internal_id'] );

		if ( array() === $evidence ) {
			return;
		}
		?>
		<ul>
		<?php
		foreach ( $evidence as $item ) :
			?>
			<li><?php echo esc_html( $item['created_at'] . ' · ' . $item['status'] . ' · ' . $item['reference_text'] ); ?></li><?php endforeach; ?></ul>
		<?php
	}

	/**
	 * Renderiza retry protegido por nonce y capability.
	 *
	 * @param array<string, mixed> $order Pedido.
	 * @return void
	 */
	private static function render_reconciliation_form( array $order ): void {
		if ( ! current_user_can( 'reconcile_vicu_restaurant_payments' ) ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="vicu_restaurante_reconcile_order"><input type="hidden" name="public_id" value="<?php echo esc_attr( $order['public_id'] ); ?>"><?php wp_nonce_field( 'vicu_restaurante_reconcile_order' ); ?><?php submit_button( __( 'Reconciliar pago', 'vicunav-restaurante' ), 'secondary', 'submit', false ); ?></form>
		<?php
	}

	/**
	 * Lee texto después de verificar nonce en cada caller.
	 *
	 * @param string $key Clave.
	 * @return string
	 */
	private static function request_text( string $key ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $value;
	}

	/**
	 * Exige una capability primitiva.
	 *
	 * @param string $capability Capability.
	 * @return void
	 */
	private static function require_capability( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'No autorizado.', 'vicunav-restaurante' ), '', array( 'response' => 403 ) );
		}
	}
}
