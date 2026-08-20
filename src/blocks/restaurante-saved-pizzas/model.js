/**
 * Devuelve una configuración para revalidación, nunca un precio del cliente.
 *
 * @param {Object} configuration Snapshot guardado.
 * @param {number} revision      Revisión viva del catálogo.
 * @return {Object} Configuración candidata de cantidad uno.
 */
export const currentConfiguration = ( configuration, revision ) => ( {
	version: configuration.version,
	catalog_revision: Number( revision ),
	size_id: configuration.size_id,
	crust_id: configuration.crust_id,
	sauce_id: configuration.sauce_id,
	cheese_ingredient_id: configuration.cheese_ingredient_id,
	toppings: { ...( configuration.toppings || {} ) },
	quantity: 1,
} );

/**
 * Construye un índice solo para presentación de nombres y disponibilidad.
 *
 * @param {Object} catalog Catálogo agrupado.
 * @return {Map} Índice por UUID.
 */
export const catalogIndex = ( catalog ) => {
	const result = new Map();
	[ 'sizes', 'crusts', 'sauces', 'cheeses', 'toppings' ].forEach(
		( group ) => {
			( catalog?.[ group ] || [] ).forEach( ( item ) =>
				result.set( item.public_id, item )
			);
		}
	);
	return result;
};

/**
 * Resume selecciones por nombre sin validar ni calcular la configuración.
 *
 * @param {Object} configuration Configuración guardada.
 * @param {Map}    index         Catálogo indexado.
 * @return {string} Resumen textual.
 */
export const configurationSummary = ( configuration, index ) => {
	const ids = [
		configuration.size_id,
		configuration.crust_id,
		configuration.sauce_id,
		configuration.cheese_ingredient_id,
		...Object.keys( configuration.toppings || {} ),
	];
	const names = ids.map(
		( id ) => index.get( id )?.name || 'Selección no disponible'
	);
	return names.join( ' · ' );
};

/**
 * Extrae texto REST sin interpretar HTML.
 *
 * @param {Object} payload  Respuesta candidata.
 * @param {string} fallback Mensaje seguro.
 * @return {string} Texto presentable.
 */
export const responseMessage = ( payload, fallback ) =>
	typeof payload?.message === 'string' && payload.message.trim()
		? payload.message.trim()
		: fallback;
