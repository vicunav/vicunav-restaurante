<?php
/**
 * Operación privada de reservas en wp-admin.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Admin;

use Vicu\Restaurante\Reservation\ReservationPostType;
use Vicu\Restaurante\Reservation\ReservationProjection;
use Vicu\Restaurante\Reservation\ReservationService;
use Vicu\Restaurante\Reservation\ReservationStateMachine;
use WP_Post;

/**
 * Renderiza datos autoritativos y aplica transiciones con nonce y revisión.
 */
final class ReservationAdmin {
	/**
	 * Evita registrar hooks duplicados.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/** Registra la superficie operativa. */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( 'add_meta_boxes_' . ReservationPostType::POST_TYPE, array( self::class, 'add_meta_box' ) );
		add_action( 'admin_post_vicu_restaurante_transition_reservation', array( self::class, 'transition' ) );
		add_action( 'admin_post_vicu_restaurante_rebuild_reservations', array( self::class, 'rebuild' ) );
		add_filter( 'manage_' . ReservationPostType::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . ReservationPostType::POST_TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		self::$hooks_registered = true;
	}

	/** Añade detalle no editable. */
	public static function add_meta_box(): void {
		add_meta_box( 'vicu_restaurante_reservation_detail', __( 'Reserva autoritativa', 'vicunav-restaurante' ), array( self::class, 'render_detail' ), ReservationPostType::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Renderiza datos privados solo para operadores.
	 *
	 * @param WP_Post $post Proyección.
	 */
	public static function render_detail( WP_Post $post ): void {
		self::require_capability();
		$reservation = ReservationService::admin_detail( (string) get_post_meta( $post->ID, ReservationPostType::META_PUBLIC_ID, true ) );

		if ( null === $reservation ) {
			echo '<p>' . esc_html__( 'La proyección no encuentra su reserva autoritativa.', 'vicunav-restaurante' ) . '</p>';
			return;
		}
		?>
		<p><strong><?php echo esc_html( $reservation['confirmation_code'] ); ?></strong> · <?php echo esc_html( $reservation['status'] ); ?> · <?php echo esc_html( $reservation['date'] . ' ' . $reservation['time'] . ' ' . $reservation['timezone'] ); ?></p>
		<p><?php echo esc_html( $reservation['guest_name'] . ' · ' . $reservation['guest_phone'] . ' · ' . (string) $reservation['party_size'] ); ?></p>
		<?php
		if ( null !== $reservation['guest_email'] ) :
			?>
			<p><?php echo esc_html( $reservation['guest_email'] ); ?></p><?php endif; ?>
		<?php
		if ( null !== $reservation['zone_preference'] ) :
			?>
			<p><?php echo esc_html( $reservation['zone_preference'] ); ?></p><?php endif; ?>
		<?php
		if ( null !== $reservation['notes'] ) :
			?>
			<p><?php echo esc_html( $reservation['notes'] ); ?></p><?php endif; ?>
		<h3><?php echo esc_html__( 'Eventos', 'vicunav-restaurante' ); ?></h3><ol>
		<?php
		foreach ( $reservation['events'] as $event ) :
			?>
			<li><?php echo esc_html( $event['created_at'] . ' · ' . ( null === $event['from'] ? 'creación' : $event['from'] ) . ' → ' . $event['to'] ); ?></li><?php endforeach; ?></ol>
		<?php self::render_transition_form( $reservation ); ?>
		<?php
	}

	/** Ejecuta una transición operativa autorizada. */
	public static function transition(): void {
		self::require_capability();
		check_admin_referer( 'vicu_restaurante_transition_reservation' );
		$target  = self::request_text( 'target' );
		$allowed = array( 'confirmada', 'completada', 'cancelada', 'no_asistio' );
		$result  = in_array( $target, $allowed, true )
			? ReservationService::transition( self::request_text( 'public_id' ), absint( self::request_text( 'expected_revision' ) ), $target, 'operator', get_current_user_id(), self::request_text( 'reason' ) )
			: null;
		$status  = null === $result || is_wp_error( $result ) ? ( is_wp_error( $result ) ? $result->get_error_code() : 'vicu_restaurante_invalid_transition' ) : 'transitioned';
		$url     = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'vicu_reservation_status', rawurlencode( $status ), false === $url ? admin_url( 'edit.php?post_type=' . ReservationPostType::POST_TYPE ) : $url ) );
		exit;
	}

	/** Reconstruye proyecciones de forma idempotente. */
	public static function rebuild(): void {
		self::require_capability();
		check_admin_referer( 'vicu_restaurante_rebuild_reservations' );
		$result = ReservationProjection::rebuild();
		wp_safe_redirect( add_query_arg( $result, admin_url( 'edit.php?post_type=' . ReservationPostType::POST_TYPE ) ) );
		exit;
	}

	/**
	 * Define columnas operativas.
	 *
	 * @param array<string, string> $columns Columnas existentes.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		return array(
			'cb'            => $columns['cb'] ?? '<input type="checkbox">',
			'title'         => __( 'Reserva', 'vicunav-restaurante' ),
			'vicu_status'   => __( 'Estado', 'vicunav-restaurante' ),
			'vicu_schedule' => __( 'Horario', 'vicunav-restaurante' ),
			'vicu_party'    => __( 'Personas', 'vicunav-restaurante' ),
			'date'          => $columns['date'] ?? __( 'Fecha', 'vicunav-restaurante' ),
		);
	}

	/**
	 * Renderiza una columna desde la autoridad.
	 *
	 * @param string $column  Columna.
	 * @param int    $post_id Post.
	 */
	public static function column( string $column, int $post_id ): void {
		$reservation = ReservationService::admin_detail( (string) get_post_meta( $post_id, ReservationPostType::META_PUBLIC_ID, true ) );
		if ( null === $reservation ) {
			return;
		}
		if ( 'vicu_status' === $column ) {
			echo esc_html( $reservation['status'] );
		} elseif ( 'vicu_schedule' === $column ) {
			echo esc_html( $reservation['date'] . ' ' . $reservation['time'] );
		} elseif ( 'vicu_party' === $column ) {
			echo esc_html( (string) $reservation['party_size'] );
		}
	}

	/**
	 * Retira acciones de borrado y edición rápida.
	 *
	 * @param array<string, string> $actions Acciones.
	 * @param WP_Post               $post    Post.
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( ReservationPostType::POST_TYPE === $post->post_type ) {
			unset( $actions['trash'], $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	/**
	 * Renderiza destinos operativos con revisión esperada.
	 *
	 * @param array<string, mixed> $reservation Reserva.
	 */
	private static function render_transition_form( array $reservation ): void {
		if ( ReservationStateMachine::is_terminal( $reservation['status'] ) ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="vicu_restaurante_transition_reservation"><input type="hidden" name="public_id" value="<?php echo esc_attr( $reservation['public_id'] ); ?>"><input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) $reservation['revision'] ); ?>"><?php wp_nonce_field( 'vicu_restaurante_transition_reservation' ); ?><select name="target">
		<?php
		foreach ( array( 'confirmada', 'completada', 'cancelada', 'no_asistio' ) as $target ) :
			?>
			<option value="<?php echo esc_attr( $target ); ?>"><?php echo esc_html( $target ); ?></option><?php endforeach; ?></select><label><?php echo esc_html__( 'Motivo', 'vicunav-restaurante' ); ?> <input name="reason" maxlength="500"></label><?php submit_button( __( 'Aplicar transición', 'vicunav-restaurante' ), 'secondary', 'submit', false ); ?></form>
		<?php
	}

	/**
	 * Lee texto de un POST ya protegido por nonce.
	 *
	 * @param string $key Clave.
	 */
	private static function request_text( string $key ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $value;
	}

	/** Exige la capability de reservas. */
	private static function require_capability(): void {
		if ( ! current_user_can( 'manage_vicu_restaurant_reservations' ) ) {
			wp_die( esc_html__( 'No puedes administrar reservas.', 'vicunav-restaurante' ), '', array( 'response' => 403 ) );
		}
	}
}
