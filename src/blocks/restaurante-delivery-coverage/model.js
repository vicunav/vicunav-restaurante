/**
 * Lógica pura de cobertura de entrega, sin DOM ni Interactivity API.
 */

/**
 * Normaliza texto para una comparación tolerante a mayúsculas y acentos.
 *
 * @param {string} value Texto candidato.
 * @return {string} Texto comparable.
 */
export function normalizeZoneText( value ) {
	return String( value || '' )
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.toLocaleLowerCase()
		.trim();
}

/**
 * Separa un texto normalizado en palabras significativas.
 *
 * @param {string} value Texto candidato.
 * @return {Array<string>} Palabras sin puntuación ni espacios sobrantes.
 */
function words( value ) {
	return normalizeZoneText( value )
		.replace( /[^a-z0-9\s]/g, ' ' )
		.split( /\s+/ )
		.filter( Boolean );
}

/**
 * Busca la zona activa cuyo nombre coincide con el texto escrito. Coincide
 * tanto cuando el texto es un sector parcial contenido en un nombre
 * compuesto ("la lago" dentro de "La Lago / Bella Vista") como cuando es una
 * dirección larga que contiene el nombre completo de la zona ("Av. 5 de
 * Julio, Casco Central, Maracaibo" contiene "Maracaibo (Casco Central)").
 *
 * @param {string}        query Texto escrito por la persona.
 * @param {Array<Object>} zones Zonas activas publicadas por el servidor.
 * @return {Object|null} La zona coincidente de menor `display_order`, o null.
 */
export function matchDeliveryZone( query, zones ) {
	const queryWords = words( query );

	if ( ! queryWords.length || ! Array.isArray( zones ) ) {
		return null;
	}

	const querySet = new Set( queryWords );

	const candidates = zones
		.filter( ( zone ) => zone && typeof zone.name === 'string' )
		.map( ( zone ) => ( { zone, zoneWords: words( zone.name ) } ) )
		.filter( ( { zoneWords } ) => {
			if ( ! zoneWords.length ) {
				return false;
			}

			const zoneSet = new Set( zoneWords );

			return (
				queryWords.every( ( word ) => zoneSet.has( word ) ) ||
				zoneWords.every( ( word ) => querySet.has( word ) )
			);
		} );

	if ( ! candidates.length ) {
		return null;
	}

	candidates.sort( ( a, b ) => a.zone.display_order - b.zone.display_order );

	return candidates[ 0 ].zone;
}

/**
 * Formatea el tiempo estimado de entrega de una zona.
 *
 * @param {Object} zone Zona con `eta_min_minutes`/`eta_max_minutes`.
 * @return {string} Rango legible o valor único cuando coinciden.
 */
export function formatEta( zone ) {
	if ( ! zone ) {
		return '';
	}

	return zone.eta_min_minutes === zone.eta_max_minutes
		? `${ zone.eta_min_minutes } min`
		: `${ zone.eta_min_minutes }-${ zone.eta_max_minutes } min`;
}

/**
 * Formatea la tarifa de una zona, o null cuando la entrega es gratuita para
 * que el llamador elija el copy correspondiente en vez de mostrar "0".
 *
 * @param {Object} zone     Zona con `fee_minor`.
 * @param {string} currency Código ISO 4217 vigente.
 * @param {string} locale   Locale para el formateo numérico.
 * @return {string|null} Importe localizado, o null si es gratuita.
 */
export function formatFee( zone, currency, locale = 'es' ) {
	if ( ! zone || 0 === zone.fee_minor ) {
		return null;
	}

	try {
		return new Intl.NumberFormat( locale, {
			style: 'currency',
			currency,
		} ).format( zone.fee_minor / 100 );
	} catch {
		return `${ ( zone.fee_minor / 100 ).toFixed( 2 ) } ${ currency }`;
	}
}
