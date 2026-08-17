import { getContext, store, withSyncEvent } from '@wordpress/interactivity';

import { buildConfiguration, responseMessage, toggleTopping } from './model';

const request = async ( url, options = {} ) => {
	const response = await fetch( url, {
		credentials: 'same-origin',
		...options,
		headers: {
			'Content-Type': 'application/json',
			...( options.headers || {} ),
		},
	} );
	const payload = await response.json().catch( () => ( {} ) );

	if ( ! response.ok ) {
		const error = new Error(
			responseMessage( payload, 'La solicitud falló.' )
		);
		error.status = response.status;
		throw error;
	}

	return payload;
};

const quote = async ( context ) => {
	context.isBusy = true;
	context.errorMessage = '';
	context.successMessage = '';
	context.statusMessage = context.labels.quoting;
	context.hasQuote = false;

	try {
		const result = await request( context.quoteUrl, {
			method: 'POST',
			body: JSON.stringify( {
				configuration: buildConfiguration( context ),
			} ),
		} );
		context.quoteTotal = new Intl.NumberFormat( context.locale, {
			style: 'currency',
			currency: result.currency,
		} ).format( result.total_minor / 100 );
		context.hasQuote = true;
		context.statusMessage = context.labels.quoted;
	} catch ( error ) {
		context.errorMessage = error.message || context.labels.quoteError;
		context.statusMessage = '';
	} finally {
		context.isBusy = false;
	}
};

const cartHeaders = ( context, cart ) => {
	if ( context.restNonce ) {
		return { 'X-WP-Nonce': context.restNonce };
	}

	return cart.csrf_token ? { 'X-Vicu-Csrf': cart.csrf_token } : {};
};

const getCart = async ( context ) => {
	try {
		return await request( context.cartUrl, {
			headers: context.restNonce
				? { 'X-WP-Nonce': context.restNonce }
				: {},
		} );
	} catch ( error ) {
		if ( error.status !== 401 && error.status !== 404 ) {
			throw error;
		}

		return request( context.cartsUrl, {
			method: 'POST',
			headers: context.restNonce
				? { 'X-WP-Nonce': context.restNonce }
				: {},
			body: '{}',
		} );
	}
};

const { state, actions } = store( 'vicunav/restaurante-pizza-builder', {
	state: {
		get isZoneActive() {
			const context = getContext();
			return context.activeZone === context.zoneValue;
		},
		get isToppingSelected() {
			const context = getContext();
			return Boolean( context.toppings[ context.ingredientId ] );
		},
		get toppingZone() {
			const context = getContext();
			return context.toppings[ context.ingredientId ] || '';
		},
		get canAdd() {
			const context = getContext();
			return context.hasQuote && ! context.isBusy;
		},
	},
	actions: {
		*initialize() {
			const context = getContext();
			yield quote( context );
		},
		*selectOption( event ) {
			const context = getContext();
			const field = event.target.dataset.configurationField;
			if ( field && event.target.value ) {
				context[ field ] = event.target.value;
				yield quote( context );
			}
		},
		selectZone( event ) {
			const context = getContext();
			context.activeZone = event.target.dataset.zone;
		},
		*toggleTopping() {
			const context = getContext();
			const result = toggleTopping(
				context.toppings,
				context.ingredientId,
				context.activeZone
			);

			if ( result.error ) {
				context.errorMessage = context.labels.maximumToppings;
				return;
			}

			context.toppings = result.toppings;
			yield quote( context );
		},
		addToCart: withSyncEvent( ( event ) => {
			event.preventDefault();
			return actions.submitToCart();
		} ),
		*submitToCart() {
			const context = getContext();
			context.isBusy = true;
			context.errorMessage = '';
			context.successMessage = '';
			context.statusMessage = context.labels.adding;

			try {
				yield quote( context );
				if ( ! context.hasQuote ) {
					return;
				}
				context.isBusy = true;
				const cart = yield getCart( context );
				const updated = yield request( context.cartItemsUrl, {
					method: 'POST',
					headers: cartHeaders( context, cart ),
					body: JSON.stringify( {
						expected_revision: cart.revision,
						item: {
							type: 'pizza',
							configuration: buildConfiguration( context ),
						},
					} ),
				} );
				context.successMessage = context.labels.added.replace(
					'%d',
					String( updated.items.length )
				);
				context.statusMessage = '';
			} catch ( error ) {
				context.errorMessage =
					error.message || context.labels.cartError;
				context.statusMessage = '';
			} finally {
				context.isBusy = false;
			}
		},
		refreshCatalog() {
			window.location.reload();
		},
	},
} );

export { actions, state };
