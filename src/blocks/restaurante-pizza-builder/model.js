export const MAX_TOPPINGS = 6;
export const ZONES = [ 'whole', 'left', 'right' ];

/**
 * Construye el payload versionado que el servidor volverá a validar.
 *
 * @param {Object} context Estado local del constructor.
 * @return {Object} Configuración pública sin importes.
 */
export function buildConfiguration( context ) {
	return {
		version: 1,
		catalog_revision: Number( context.catalogRevision ),
		size_id: context.sizeId,
		crust_id: context.crustId,
		sauce_id: context.sauceId,
		cheese_ingredient_id: context.cheeseId,
		toppings: { ...context.toppings },
		quantity: 1,
	};
}

/**
 * Aplica el ciclo ausente, zona activa, ausente sin duplicar ingredientes.
 *
 * @param {Object} toppings     Selección actual por UUID.
 * @param {string} ingredientId UUID del topping.
 * @param {string} activeZone   Zona activa.
 * @return {Object} Resultado inmutable y error opcional.
 */
export function toggleTopping( toppings, ingredientId, activeZone ) {
	if ( ! ZONES.includes( activeZone ) ) {
		return { toppings, error: 'invalid-zone' };
	}

	const next = { ...toppings };
	if ( next[ ingredientId ] === activeZone ) {
		delete next[ ingredientId ];
		return { toppings: next, error: null };
	}

	if (
		! next[ ingredientId ] &&
		Object.keys( next ).length >= MAX_TOPPINGS
	) {
		return { toppings, error: 'maximum-toppings' };
	}

	next[ ingredientId ] = activeZone;
	return { toppings: next, error: null };
}

/**
 * Extrae un error REST sin confiar en propiedades ausentes.
 *
 * @param {Object} payload  Respuesta candidata.
 * @param {string} fallback Mensaje local seguro.
 * @return {string} Mensaje que mostrará la interfaz como texto.
 */
export function responseMessage( payload, fallback ) {
	return payload &&
		typeof payload.message === 'string' &&
		payload.message.trim()
		? payload.message.trim()
		: fallback;
}
