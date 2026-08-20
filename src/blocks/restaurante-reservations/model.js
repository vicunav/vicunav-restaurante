/** Genera una clave opaca por intento; el backend conserva la autoridad idempotente. */
export const idempotencyKey = () => {
	if ( globalThis.crypto?.randomUUID ) {
		return globalThis.crypto.randomUUID();
	}

	const bytes = new Uint8Array( 16 );
	globalThis.crypto?.getRandomValues?.( bytes );
	return Array.from( bytes, ( value ) =>
		value.toString( 16 ).padStart( 2, '0' )
	).join( '' );
};

/**
 * Extrae un mensaje textual sin interpretar HTML recibido.
 *
 * @param {Object} payload  Respuesta REST.
 * @param {string} fallback Mensaje seguro.
 */
export const responseMessage = ( payload, fallback ) => {
	const message =
		typeof payload?.message === 'string' ? payload.message.trim() : '';
	return message || fallback;
};

/**
 * Lee alternativas únicamente desde la forma estable de errores REST.
 *
 * @param {Object} payload Respuesta REST.
 */
export const responseAlternatives = ( payload ) => {
	const alternatives = payload?.data?.alternatives;
	return Array.isArray( alternatives )
		? alternatives.filter(
				( slot ) =>
					typeof slot?.time === 'string' &&
					[ 'available', 'limited' ].includes( slot.status )
		  )
		: [];
};

/**
 * Construye la mutación sin aceptar capacidad, estado ni revisión de ajustes.
 *
 * @param {FormData} form      Campos privados.
 * @param {Object}   selection Selección autoritativamente comprobada.
 */
export const bookingPayload = ( form, selection ) => ( {
	guest_name: form.get( 'guest_name' ),
	phone: form.get( 'phone' ),
	email: form.get( 'email' ),
	zone_preference: form.get( 'zone_preference' ),
	notes: form.get( 'notes' ),
	date: selection.date,
	time: selection.time,
	party_size: selection.partySize,
} );
