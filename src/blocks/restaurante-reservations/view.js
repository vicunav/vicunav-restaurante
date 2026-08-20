import { getElement, store, withSyncEvent } from '@wordpress/interactivity';

import {
	bookingPayload,
	ensureReservationState,
	idempotencyKey,
	responseAlternatives,
	responseMessage,
} from './model';

const initialized = new WeakSet();
const state = new WeakMap();
const memoryTokens = new Map();

const root = () => getElement().ref.closest( '[data-vicu-reservations-root]' );
const currentState = ( element ) => ensureReservationState( state, element );

const saveSession = ( key, value ) => {
	try {
		sessionStorage.setItem( key, value );
	} catch {
		/* Se conserva solo en memoria. */
	}
};

const readSession = ( key ) => {
	try {
		return sessionStorage.getItem( key ) || '';
	} catch {
		return '';
	}
};

const removeSession = ( key ) => {
	try {
		sessionStorage.removeItem( key );
	} catch {
		/* El fallback en memoria no requiere limpieza persistente. */
	}
};

const request = async ( url, options = {} ) => {
	const headers = { ...( options.headers || {} ) };
	if ( options.body ) {
		headers[ 'Content-Type' ] = 'application/json';
	}
	const response = await fetch( url, {
		credentials: 'same-origin',
		...options,
		headers,
	} );
	const payload = await response.json().catch( () => ( {} ) );
	if ( ! response.ok ) {
		const error = new Error(
			responseMessage( payload, 'La solicitud falló.' )
		);
		error.status = response.status;
		error.alternatives = responseAlternatives( payload );
		throw error;
	}
	return payload;
};

const setStatus = ( element, message = '' ) => {
	element.querySelector( '[data-reservation-status]' ).textContent = message;
};

const setError = ( element, message = '' ) => {
	const target = element.querySelector( '[data-reservation-error]' );
	target.textContent = message;
	target.hidden = ! message;
	if ( message ) {
		target.focus();
	}
};

const privateHeaders = (
	element,
	reservation = currentState( element ).reservation
) => {
	if ( element.dataset.restNonce ) {
		return { 'X-WP-Nonce': element.dataset.restNonce };
	}
	const publicId = reservation?.public_id || '';
	const token =
		memoryTokens.get( publicId ) ||
		readSession( `vicu_restaurante_reservation_token:${ publicId }` );
	return token ? { 'X-Vicu-Reservation-Token': token } : {};
};

const remember = ( element, reservation ) => {
	const publicId = reservation.public_id;
	if ( reservation.access_token ) {
		memoryTokens.set( publicId, reservation.access_token );
		saveSession(
			`vicu_restaurante_reservation_token:${ publicId }`,
			reservation.access_token
		);
		delete reservation.access_token;
	}
	saveSession( 'vicu_restaurante_last_reservation', publicId );
	currentState( element ).reservation = reservation;
};

const node = ( tag, text = '', className = '' ) => {
	const result = document.createElement( tag );
	result.textContent = text;
	if ( className ) {
		result.className = className;
	}
	return result;
};

const renderSlots = ( element, slots, selected = '' ) => {
	const list = element.querySelector( '[data-reservation-slot-list]' );
	list.replaceChildren();
	const bookable = slots.filter( ( slot ) => slot.status !== 'unavailable' );
	bookable.forEach( ( slot, index ) => {
		const label = node(
			'label',
			'',
			'vicu-restaurante-reservations__slot'
		);
		const input = document.createElement( 'input' );
		input.type = 'radio';
		input.name = 'time';
		input.value = slot.time;
		input.required = true;
		input.checked = slot.time === selected || ( ! selected && index === 0 );
		label.append(
			input,
			node( 'span', slot.time ),
			node(
				'small',
				slot.status === 'limited' ? 'Pocos cupos' : 'Disponible'
			)
		);
		list.append( label );
	} );
	return bookable.length;
};

const resetToAvailability = ( element ) => {
	element.querySelector(
		'[data-reservation-form="availability"]'
	).hidden = false;
	element.querySelector( '[data-reservation-form="booking"]' ).hidden = true;
	element.querySelector( '[data-reservation-confirmation]' ).hidden = true;
	setError( element );
	setStatus( element );
};

const lookupAvailability = async ( element, form ) => {
	const data = new FormData( form );
	const selection = {
		date: String( data.get( 'date' ) || '' ),
		partySize: Number( data.get( 'party_size' ) ),
		time: '',
	};
	setStatus( element, element.dataset.loadingMessage );
	setError( element );
	try {
		const result = await request(
			`${ element.dataset.restAvailability }?${ new URLSearchParams( {
				date: selection.date,
				party_size: String( selection.partySize ),
			} ) }`
		);
		currentState( element ).selection = selection;
		const booking = element.querySelector(
			'[data-reservation-form="booking"]'
		);
		const count =
			result.status === 'ok' ? renderSlots( element, result.slots ) : 0;
		booking.hidden = count === 0;
		form.hidden = count > 0;
		setStatus(
			element,
			count > 0
				? `${ count } horarios disponibles.`
				: element.dataset.noSlotsMessage
		);
	} catch ( error ) {
		setError( element, error.message || element.dataset.errorMessage );
		setStatus( element );
	}
};

const createReservation = async ( element, form ) => {
	const current = currentState( element );
	const data = new FormData( form );
	current.selection.time = String( data.get( 'time' ) || '' );
	const keyName = `vicu_restaurante_reservation_key:${ current.selection.date }:${ current.selection.time }`;
	const key = readSession( keyName ) || idempotencyKey();
	saveSession( keyName, key );
	setStatus( element, 'Creando la reserva.' );
	setError( element );
	try {
		const reservation = await request( element.dataset.restReservations, {
			method: 'POST',
			headers: {
				...( element.dataset.restNonce
					? { 'X-WP-Nonce': element.dataset.restNonce }
					: {} ),
				'Idempotency-Key': key,
			},
			body: JSON.stringify( bookingPayload( data, current.selection ) ),
		} );
		remember( element, reservation );
		removeSession( keyName );
		renderReservation( element, reservation );
		setStatus( element, element.dataset.reservationSaved );
	} catch ( error ) {
		if ( error.status === 409 && error.alternatives.length ) {
			renderSlots(
				element,
				error.alternatives,
				error.alternatives[ 0 ].time
			);
			setError( element, element.dataset.alternativesMessage );
		} else {
			setError( element, error.message || element.dataset.errorMessage );
		}
		setStatus( element );
	}
};

const renderReservation = ( element, reservation ) => {
	element.querySelector(
		'[data-reservation-form="availability"]'
	).hidden = true;
	element.querySelector( '[data-reservation-form="booking"]' ).hidden = true;
	const confirmation = element.querySelector(
		'[data-reservation-confirmation]'
	);
	const detail = element.querySelector( '[data-reservation-detail]' );
	detail.replaceChildren(
		node( 'p', `Código: ${ reservation.confirmation_code }` ),
		node( 'p', `Estado: ${ reservation.status.replaceAll( '_', ' ' ) }` ),
		node( 'p', `${ reservation.date } a las ${ reservation.time }` ),
		node( 'p', `${ reservation.party_size } personas` )
	);
	confirmation.hidden = false;
	const cancel = confirmation.querySelector(
		'[data-reservation-action="cancel"]'
	);
	cancel.hidden = [ 'cancelada', 'completada', 'no_asistio' ].includes(
		reservation.status
	);
};

const loadReservation = async ( element, publicId ) => {
	if ( ! publicId ) {
		return;
	}
	currentState( element ).reservation = { public_id: publicId };
	try {
		const reservation = await request(
			`${ element.dataset.restReservations }/${ publicId }`,
			{ headers: privateHeaders( element ) }
		);
		remember( element, reservation );
		renderReservation( element, reservation );
		setStatus( element, element.dataset.reservationRestored );
	} catch {
		removeSession( 'vicu_restaurante_last_reservation' );
		currentState( element ).reservation = null;
	}
};

const cancelReservation = async ( element ) => {
	const reservation = currentState( element ).reservation;
	if ( ! reservation ) {
		return;
	}
	setStatus( element, 'Cancelando la reserva.' );
	setError( element );
	try {
		const cancelled = await request(
			`${ element.dataset.restReservations }/${ reservation.public_id }/cancel`,
			{
				method: 'POST',
				headers: privateHeaders( element, reservation ),
				body: JSON.stringify( {
					expected_revision: reservation.revision,
				} ),
			}
		);
		remember( element, cancelled );
		renderReservation( element, cancelled );
		setStatus( element, element.dataset.reservationCancelled );
	} catch ( error ) {
		if ( error.status === 409 ) {
			await loadReservation( element, reservation.public_id );
		}
		setError( element, error.message || element.dataset.errorMessage );
		setStatus( element );
	}
};

const initialize = async ( element ) => {
	if ( initialized.has( element ) ) {
		return;
	}
	initialized.add( element );
	currentState( element );
	await loadReservation(
		element,
		readSession( 'vicu_restaurante_last_reservation' )
	);
};

store( 'vicunav/restaurante-reservations', {
	actions: {
		*initialize() {
			yield initialize( root() );
		},
		handleSubmit: withSyncEvent( ( event ) => {
			const form = event.target.closest( '[data-reservation-form]' );
			if ( ! form ) {
				return;
			}
			event.preventDefault();
			const element = root();
			if ( form.dataset.reservationForm === 'availability' ) {
				void lookupAvailability( element, form );
			} else {
				void createReservation( element, form );
			}
		} ),
		handleClick( event ) {
			const control = event.target.closest( '[data-reservation-action]' );
			if ( ! control ) {
				return;
			}
			const element = root();
			if ( control.dataset.reservationAction === 'cancel' ) {
				void cancelReservation( element );
			} else if ( control.dataset.reservationAction === 'change-date' ) {
				resetToAvailability( element );
			} else if ( control.dataset.reservationAction === 'new' ) {
				removeSession( 'vicu_restaurante_last_reservation' );
				currentState( element ).reservation = null;
				resetToAvailability( element );
			}
		},
	},
} );
