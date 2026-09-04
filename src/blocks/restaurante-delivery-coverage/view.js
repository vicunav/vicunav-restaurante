import { getContext, store } from '@wordpress/interactivity';

import { formatEta, formatFee, matchDeliveryZone } from './model';

const request = async ( url ) => {
	const response = await fetch( url, {
		headers: { Accept: 'application/json' },
	} );

	if ( ! response.ok ) {
		throw new Error( `HTTP ${ response.status }` );
	}

	return response.json();
};

/**
 * Carga las zonas activas una sola vez y las guarda en el contexto para que
 * la verificación no dependa de una segunda petición.
 *
 * @param {Object} context Contexto reactivo del bloque.
 * @return {Promise<void>} Finalización de la carga.
 */
const loadZones = async ( context ) => {
	const catalog = await request( context.restUrl );
	context.zones = Array.isArray( catalog.zones ) ? catalog.zones : [];
	context.currency = catalog.currency || context.currency;
};

const { state, actions } = store( 'vicunav/restaurante-delivery-coverage', {
	state: {
		get isBusy() {
			return 'loading' === getContext().status;
		},
		get isResultHidden() {
			const { status } = getContext();
			return 'idle' === status || 'loading' === status;
		},
		get resultMessage() {
			const context = getContext();

			if ( 'found' === context.status && context.zone ) {
				const fee = formatFee(
					context.zone,
					context.currency,
					context.locale
				);
				const eta = formatEta( context.zone );
				const label = fee ? context.foundLabel : context.foundFreeLabel;

				return fee
					? `${ label } ${ context.zone.name } · ${ fee } · ${ eta }`
					: `${ label } ${ context.zone.name } · ${ eta }`;
			}

			if ( 'not-found' === context.status ) {
				return context.notFoundLabel;
			}

			if ( 'error' === context.status ) {
				return context.errorLabel;
			}

			return '';
		},
	},
	actions: {
		*initialize() {
			const context = getContext();

			try {
				yield loadZones( context );
			} catch {
				context.zones = [];
			}
		},
		updateQuery( event ) {
			getContext().query = event.target.value;
		},
		*verify( event ) {
			event.preventDefault();

			const context = getContext();

			if ( ! context.query.trim() ) {
				return;
			}

			context.status = 'loading';

			if ( ! context.zones.length ) {
				try {
					yield loadZones( context );
				} catch {
					context.status = 'error';
					return;
				}
			}

			const zone = matchDeliveryZone( context.query, context.zones );
			context.zone = zone;
			context.status = zone ? 'found' : 'not-found';
		},
	},
} );

export { actions, state };
