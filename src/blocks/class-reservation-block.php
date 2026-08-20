<?php
/**
 * Render seguro del bloque público de reservas.
 *
 * @package Vicunav_Restaurante
 */

namespace Vicu\Restaurante\Blocks;

/** Publica únicamente estructura y endpoints; toda capacidad se decide en servidor. */
final class ReservationBlock {
	/** Renderiza consulta, creación, confirmación y cancelación propietaria. */
	public static function render(): string {
		$root_id    = wp_unique_id( 'vicu-restaurante-reservations-' );
		$attributes = get_block_wrapper_attributes(
			array(
				'id'                          => $root_id,
				'data-wp-interactive'         => 'vicunav/restaurante-reservations',
				'data-wp-context'             => wp_json_encode( new \stdClass() ),
				'data-wp-init'                => 'actions.initialize',
				'data-wp-on--click'           => 'actions.handleClick',
				'data-wp-on--submit'          => 'actions.handleSubmit',
				'data-vicu-reservations-root' => '',
				'data-rest-availability'      => esc_url_raw( rest_url( 'vicu/v1/restaurante/reservations/availability' ) ),
				'data-rest-reservations'      => esc_url_raw( rest_url( 'vicu/v1/restaurante/reservations' ) ),
				'data-rest-nonce'             => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'data-loading-message'        => __( 'Consultando disponibilidad.', 'vicunav-restaurante' ),
				'data-error-message'          => __( 'No pudimos completar la solicitud.', 'vicunav-restaurante' ),
				'data-no-slots-message'       => __( 'No hay horarios disponibles para esa fecha y grupo.', 'vicunav-restaurante' ),
				'data-alternatives-message'   => __( 'Ese horario ya no está disponible. Elige una alternativa cercana.', 'vicunav-restaurante' ),
				'data-reservation-saved'      => __( 'Reserva creada. Guarda tu código de confirmación.', 'vicunav-restaurante' ),
				'data-reservation-cancelled'  => __( 'La reserva fue cancelada.', 'vicunav-restaurante' ),
				'data-reservation-restored'   => __( 'Recuperamos tu última reserva en este dispositivo.', 'vicunav-restaurante' ),
			)
		);

		ob_start();
		?>
		<section <?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php esc_attr_e( 'Reservas del restaurante', 'vicunav-restaurante' ); ?>">
			<p data-reservation-status role="status" aria-live="polite" aria-atomic="true"></p>
			<p data-reservation-error role="alert" tabindex="-1" hidden></p>

			<form class="vicu-restaurante-reservations__availability" data-reservation-form="availability">
				<div class="vicu-restaurante-reservations__fields">
					<label for="<?php echo esc_attr( $root_id ); ?>-date"><?php esc_html_e( 'Fecha', 'vicunav-restaurante' ); ?></label>
					<input id="<?php echo esc_attr( $root_id ); ?>-date" name="date" type="date" min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required>
					<label for="<?php echo esc_attr( $root_id ); ?>-party-size"><?php esc_html_e( 'Personas', 'vicunav-restaurante' ); ?></label>
					<input id="<?php echo esc_attr( $root_id ); ?>-party-size" name="party_size" type="number" min="1" step="1" inputmode="numeric" value="2" required>
				</div>
				<button type="submit"><?php esc_html_e( 'Ver horarios disponibles', 'vicunav-restaurante' ); ?></button>
			</form>

			<form class="vicu-restaurante-reservations__booking" data-reservation-form="booking" hidden>
				<fieldset data-reservation-slots>
					<legend><?php esc_html_e( 'Horario', 'vicunav-restaurante' ); ?></legend>
					<div class="vicu-restaurante-reservations__slots" data-reservation-slot-list></div>
				</fieldset>
				<div class="vicu-restaurante-reservations__fields">
					<label for="<?php echo esc_attr( $root_id ); ?>-name"><?php esc_html_e( 'Nombre', 'vicunav-restaurante' ); ?></label>
					<input id="<?php echo esc_attr( $root_id ); ?>-name" name="guest_name" maxlength="100" autocomplete="name" required>
					<label for="<?php echo esc_attr( $root_id ); ?>-phone"><?php esc_html_e( 'Teléfono', 'vicunav-restaurante' ); ?></label>
					<input id="<?php echo esc_attr( $root_id ); ?>-phone" name="phone" maxlength="32" autocomplete="tel" required>
					<label for="<?php echo esc_attr( $root_id ); ?>-email"><?php esc_html_e( 'Correo electrónico (opcional)', 'vicunav-restaurante' ); ?></label>
					<input id="<?php echo esc_attr( $root_id ); ?>-email" name="email" type="email" maxlength="191" autocomplete="email">
					<label for="<?php echo esc_attr( $root_id ); ?>-zone"><?php esc_html_e( 'Preferencia de zona (opcional)', 'vicunav-restaurante' ); ?></label>
					<input id="<?php echo esc_attr( $root_id ); ?>-zone" name="zone_preference" maxlength="100" autocomplete="off">
					<label for="<?php echo esc_attr( $root_id ); ?>-notes"><?php esc_html_e( 'Notas (opcional)', 'vicunav-restaurante' ); ?></label>
					<textarea id="<?php echo esc_attr( $root_id ); ?>-notes" name="notes" maxlength="500"></textarea>
				</div>
				<div class="vicu-restaurante-reservations__actions">
					<button type="submit"><?php esc_html_e( 'Confirmar reserva', 'vicunav-restaurante' ); ?></button>
					<button type="button" data-reservation-action="change-date"><?php esc_html_e( 'Cambiar fecha o grupo', 'vicunav-restaurante' ); ?></button>
				</div>
			</form>

			<div class="vicu-restaurante-reservations__confirmation" data-reservation-confirmation hidden>
				<div data-reservation-detail></div>
				<div class="vicu-restaurante-reservations__actions">
					<button type="button" data-reservation-action="cancel"><?php esc_html_e( 'Cancelar reserva', 'vicunav-restaurante' ); ?></button>
					<button type="button" data-reservation-action="new"><?php esc_html_e( 'Hacer otra reserva', 'vicunav-restaurante' ); ?></button>
				</div>
			</div>
			<noscript><p><?php esc_html_e( 'Activa JavaScript para consultar capacidad y gestionar una reserva.', 'vicunav-restaurante' ); ?></p></noscript>
		</section>
		<?php

		return (string) ob_get_clean();
	}
}
