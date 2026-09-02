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

const DOT_PALETTE = [
	'#a8432b',
	'#6b7f4a',
	'#c98a3a',
	'#8a4b6b',
	'#3f6b7a',
	'#b5432b',
	'#7a5a3f',
	'#4d673b',
];
const DOT_SHAPES = [ 'circle', 'square', 'triangle' ];
const WHOLE_SPOTS = [
	[ 0, 26 ],
	[ 90, 40 ],
	[ 180, 30 ],
	[ 260, 44 ],
];
const HALF_SPOTS = [
	[ 0, 22 ],
	[ 140, 34 ],
];

/**
 * Deriva un entero estable de un UUID para asignarle color/forma/ángulo sin
 * depender de una tabla de colores por ingrediente (el catálogo es editable
 * desde el admin, no una lista fija como en el diseño de origen).
 *
 * @param {string} value Cadena de entrada, típicamente un UUID.
 * @return {number} Entero positivo estable para ese valor.
 */
export function stableHash( value ) {
	let hash = 0;
	for ( let index = 0; index < value.length; index += 1 ) {
		hash = ( hash * 31 + value.charCodeAt( index ) ) % 1000000007;
	}
	return hash;
}

/**
 * CSS de una forma de topping sobre la pizza.
 *
 * @param {string} shape Una de DOT_SHAPES.
 * @param {string} color Color CSS ya resuelto.
 * @return {string} Declaraciones CSS de tamaño/forma/color.
 */
function dotShapeCss( shape, color ) {
	switch ( shape ) {
		case 'square':
			return `width:7%;height:7%;background:${ color };border-radius:15%;`;
		case 'triangle':
			return `width:7.5%;height:7.5%;background:${ color };clip-path:polygon(50% 0%,100% 50%,50% 100%,0% 50%);`;
		default:
			return `width:8%;height:8%;border-radius:50%;background:${ color };`;
	}
}

/**
 * Calcula los puntos visuales de los toppings seleccionados sobre la pizza,
 * distribuidos según su zona (completa/mitad izquierda/mitad derecha).
 *
 * @param {Object} toppings Selección actual, UUID -> zona.
 * @return {Array<{key: string, style: string}>} Puntos listos para pintar.
 */
export function pizzaDots( toppings ) {
	const dots = [];

	Object.keys( toppings ).forEach( ( id ) => {
		const zone = toppings[ id ];
		const hash = stableHash( id );
		const color = DOT_PALETTE[ hash % DOT_PALETTE.length ];
		const shape =
			DOT_SHAPES[
				Math.floor( hash / DOT_PALETTE.length ) % DOT_SHAPES.length
			];
		const baseAngle = hash % 360;
		const spots = zone === 'whole' ? WHOLE_SPOTS : HALF_SPOTS;

		spots.forEach( ( [ offset, radius ], index ) => {
			let angle = baseAngle + offset;
			if ( zone === 'left' ) {
				angle = 90 + ( ( ( angle % 180 ) - 90 + 180 ) % 180 );
			} else if ( zone === 'right' ) {
				angle = ( ( ( angle % 180 ) - 90 + 360 ) % 180 ) - 90;
			}
			const radians = ( angle * Math.PI ) / 180;
			const x = 50 + radius * Math.cos( radians );
			const y = 50 + radius * Math.sin( radians );
			const rotate = ( angle + 40 * index ) % 360;

			dots.push( {
				key: `${ id }-${ zone }-${ index }`,
				style: `position:absolute;left:${ x }%;top:${ y }%;transform:translate(-50%,-50%) rotate(${ rotate }deg);${ dotShapeCss(
					shape,
					color
				) }`,
			} );
		} );
	} );

	return dots;
}

/**
 * Escala visual del círculo de pizza a partir del diámetro embebido en el
 * nombre del tamaño (ej. "Mediana (30 cm)"), normalizado sobre una
 * referencia de 30cm. Es genérico: cualquier nombre de tamaño que declare su
 * diámetro en cm escala correctamente, sin tabla fija por opción.
 *
 * @param {string} sizeName Nombre del tamaño seleccionado.
 * @return {number} Factor de escala entre 0.7 y 1.3.
 */
export function pizzaSizeScale( sizeName = '' ) {
	const match = /(\d+(?:\.\d+)?)\s*cm/i.exec( sizeName );
	if ( ! match ) {
		return 1;
	}
	const cm = parseFloat( match[ 1 ] );
	return Math.min( 1.3, Math.max( 0.7, cm / 30 ) );
}

/**
 * Aproximación visual del color de la salsa a partir de su nombre. El
 * catálogo real no guarda un color por opción (es editable desde el admin);
 * esto es una aproximación razonable, no un valor de diseño exacto.
 *
 * @param {string} sauceName Nombre de la salsa seleccionada.
 * @return {string} Declaraciones CSS para la capa de salsa.
 */
export function pizzaSauceStyle( sauceName = '' ) {
	const name = sauceName.toLowerCase();
	if ( name.includes( 'sin salsa' ) ) {
		return 'display:none;';
	}
	if ( name.includes( 'pesto' ) ) {
		return 'background:#6b7f4a;';
	}
	if ( name.includes( 'blanca' ) || name.includes( 'ajo' ) ) {
		return 'background:#f3ead8;';
	}
	return 'background:#a8432b;';
}

/**
 * Aproximación visual del color/visibilidad del queso a partir de su
 * nombre, con el mismo criterio y límite que {@link pizzaSauceStyle}.
 *
 * @param {string} cheeseName Nombre del queso seleccionado.
 * @return {string} Declaraciones CSS para la capa de queso.
 */
export function pizzaCheeseStyle( cheeseName = '' ) {
	const name = cheeseName.toLowerCase();
	if ( name.includes( 'sin queso' ) ) {
		return 'display:none;';
	}
	return 'background:#f5e6a8;';
}

/**
 * Una masa se asume con gluten salvo que su nombre lo descarte
 * explícitamente ("sin gluten"), ya que el catálogo de opciones (a
 * diferencia del de ingredientes) no guarda alérgenos por ahora.
 *
 * @param {string} crustName Nombre de la masa seleccionada.
 * @return {boolean} Verdadero si la pizza debe marcarse con gluten.
 */
export function crustContainsGluten( crustName = '' ) {
	return ! /sin\s+gluten/i.test( crustName );
}

/**
 * Deriva las insignias dietarias de la pizza a partir de las etiquetas
 * reales del queso y los toppings seleccionados (catálogo de ingredientes).
 * La masa y la salsa no participan: el catálogo de opciones no guarda
 * alérgenos/dietas por ahora, así que no se puede afirmar nada sobre ellas.
 *
 * @param {string} cheeseId Ingrediente de queso seleccionado.
 * @param {Object} toppings Selección actual, UUID -> zona.
 * @param {Object} catalog  Mapa de ingredientes (queso + toppings) por UUID,
 *                          cada uno con `dietaryTags`/`allergens` opcionales.
 * @return {{isVegetarian: boolean, hasDairy: boolean}} Insignias derivadas.
 */
export function pizzaDietary( cheeseId, toppings, catalog = {} ) {
	const cheese = catalog[ cheeseId ];
	const toppingEntries = Object.keys( toppings )
		.map( ( id ) => catalog[ id ] )
		.filter( Boolean );

	const cheeseIsVegetarian =
		! cheese || ( cheese.dietaryTags || [] ).includes( 'vegetarian' );
	const toppingsAreVegetarian = toppingEntries.every( ( topping ) =>
		( topping.dietaryTags || [] ).includes( 'vegetarian' )
	);

	return {
		isVegetarian: cheeseIsVegetarian && toppingsAreVegetarian,
		hasDairy: Boolean(
			cheese && ( cheese.allergens || [] ).includes( 'milk' )
		),
	};
}

/**
 * Agrupa los toppings seleccionados por zona, resueltos a su nombre visible,
 * para las líneas de resumen ("Entera: ...", "Mitad izquierda: ...").
 *
 * @param {Object}                          toppings Selección actual, UUID -> zona.
 * @param {Object<string, {name?: string}>} catalog  Mapa de toppings por UUID.
 * @return {{whole: string, left: string, right: string}} Texto por zona, vacío si no aplica.
 */
export function pizzaToppingsByZone( toppings, catalog = {} ) {
	const byZone = { whole: [], left: [], right: [] };

	Object.keys( toppings ).forEach( ( id ) => {
		const zone = toppings[ id ];
		const name = catalog[ id ]?.name;
		if ( name && byZone[ zone ] ) {
			byZone[ zone ].push( name );
		}
	} );

	return {
		whole: byZone.whole.join( ', ' ),
		left: byZone.left.join( ', ' ),
		right: byZone.right.join( ', ' ),
	};
}
