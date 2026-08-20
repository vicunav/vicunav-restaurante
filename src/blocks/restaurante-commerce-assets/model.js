/**
 * Convierte una línea devuelta por REST en una selección que REST revalidará.
 *
 * @param {Object} item     Línea pública del carrito.
 * @param {number} quantity Cantidad candidata.
 * @return {Object} Selección completa sin importes.
 */
export function cartItemPayload( item, quantity ) {
	const selection = JSON.parse( JSON.stringify( item.selection ) );

	if ( item.type === 'pizza' ) {
		selection.configuration.quantity = quantity;
	} else {
		selection.quantity = quantity;
	}

	return { type: item.type, ...selection };
}

/**
 * Formatea unidades menores solo para presentación.
 *
 * @param {number} amountMinor Importe en unidad menor.
 * @param {string} currency    Moneda ISO 4217.
 * @param {string} locale      Locale del sitio.
 * @return {string} Importe localizado.
 */
export function formatMoney( amountMinor, currency, locale ) {
	return new Intl.NumberFormat( locale, {
		style: 'currency',
		currency,
	} ).format( Number( amountMinor ) / 100 );
}

/** Genera una clave opaca para reintentos idempotentes. */
export function idempotencyKey() {
	if ( typeof crypto.randomUUID === 'function' ) {
		return crypto.randomUUID();
	}

	const bytes = new Uint8Array( 16 );
	crypto.getRandomValues( bytes );
	return Array.from( bytes, ( byte ) =>
		byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );
}

/**
 * Devuelve un mensaje REST como texto o un fallback local.
 *
 * @param {Object} payload  Respuesta candidata.
 * @param {string} fallback Mensaje local seguro.
 * @return {string} Texto para la región de error.
 */
export function responseMessage( payload, fallback ) {
	return payload &&
		typeof payload.message === 'string' &&
		payload.message.trim()
		? payload.message.trim()
		: fallback;
}
