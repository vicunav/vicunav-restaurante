import {
	getContext,
	getElement,
	store,
	withSyncEvent,
} from '@wordpress/interactivity';

import {
	cartItemCount,
	cartItemPayload,
	formatMoney,
	idempotencyKey,
	responseMessage,
	shouldLoadCart,
} from './model';

let currentCart = null;
let currentOrder = null;
let deliveryZones = [];
const initialized = new WeakSet();
const memoryTokens = new Map();

const root = () => getElement().ref.closest( '[data-vicu-commerce-role]' );
const allRoots = ( role ) =>
	document.querySelectorAll( `[data-vicu-commerce-role="${ role }"]` );

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
		throw error;
	}
	return payload;
};

const privateHeaders = ( element, cart = currentCart ) => {
	if ( element.dataset.restNonce ) {
		return { 'X-WP-Nonce': element.dataset.restNonce };
	}
	return cart?.csrf_token ? { 'X-Vicu-Csrf': cart.csrf_token } : {};
};

const setStatus = ( element, message ) => {
	const target = element.querySelector( '[data-commerce-status]' );
	if ( target ) {
		target.textContent = message;
	}
};

const setError = ( element, message = '' ) => {
	const target = element.querySelector( '[data-commerce-error]' );
	if ( target ) {
		target.textContent = message;
		target.hidden = ! message;
	}
};

const node = ( tag, text = '', className = '' ) => {
	const result = document.createElement( tag );
	result.textContent = text;
	if ( className ) {
		result.className = className;
	}
	return result;
};

const button = ( text, action, lineId = '' ) => {
	const result = node( 'button', text );
	result.type = 'button';
	result.dataset.commerceAction = action;
	if ( lineId ) {
		result.dataset.lineId = lineId;
	}
	return result;
};

const loadCart = async ( element, silent = false ) => {
	if ( ! silent ) {
		setStatus( element, element.dataset.loadingMessage );
	}
	try {
		currentCart = await request( element.dataset.restCart, {
			headers: element.dataset.restNonce
				? { 'X-WP-Nonce': element.dataset.restNonce }
				: {},
		} );
		setError( element );
	} catch ( error ) {
		if ( error.status === 401 || error.status === 404 ) {
			currentCart = null;
		} else {
			setError( element, error.message || element.dataset.errorMessage );
		}
	}
	renderCommerce();
	return currentCart;
};

const loadZones = async ( element ) => {
	try {
		const result = await request( element.dataset.restZones );
		deliveryZones = Array.isArray( result.zones ) ? result.zones : [];
	} catch {
		deliveryZones = [];
	}
};

const renderCommerce = () => {
	allRoots( 'cart' ).forEach( renderCart );
	allRoots( 'checkout' ).forEach( renderCheckout );
	allRoots( 'header' ).forEach( renderHeader );
	if ( currentOrder ) {
		allRoots( 'order' ).forEach( ( element ) =>
			renderOrder( element, currentOrder )
		);
	}
};

const renderHeader = ( element ) => {
	const badge = element.querySelector( '[data-header-cart-count]' );
	const link = element.querySelector( '[data-header-cart-link]' );
	const announcement = element.querySelector( '[data-header-cart-announce]' );
	const count = cartItemCount( currentCart );
	const label =
		count > 0
			? element.dataset.cartWithItemsLabel.replace( '%d', count )
			: element.dataset.cartEmptyLabel;

	if ( badge ) {
		badge.textContent = String( count );
		badge.hidden = count === 0;
	}
	if ( link ) {
		link.setAttribute( 'aria-label', label );
	}
	if ( announcement ) {
		announcement.textContent = label;
	}
};

/**
 * Resume una pizza guardada en el carrito a partir de sus componentes
 * cotizados (únicos datos con nombre legible que expone el snapshot).
 *
 * @param {Object} components Componentes cotizados de la pizza.
 * @return {string} Resumen legible o cadena vacía.
 */
const pizzaSummary = ( components ) => {
	if ( ! components ) {
		return '';
	}
	const parts = [
		components.size?.name,
		components.crust?.name,
		components.sauce?.name,
		components.cheese?.name,
	].filter( Boolean );
	if ( Array.isArray( components.toppings ) && components.toppings.length ) {
		parts.push(
			components.toppings.map( ( topping ) => topping.name ).join( ', ' )
		);
	}
	return parts.join( ' · ' );
};

const stepperNode = ( item ) => {
	const wrapper = node( 'div', '', 'vicu-restaurante-cart__item-qty' );
	wrapper.setAttribute( 'role', 'group' );
	wrapper.setAttribute(
		'aria-label',
		`Cantidad de ${ item.snapshot.name || 'producto' }`
	);
	const decrement = button( '−', 'decrement', item.line_id );
	decrement.setAttribute( 'aria-label', 'Quitar uno' );
	const count = node( 'span', String( item.quantity ) );
	count.setAttribute( 'aria-live', 'polite' );
	const increment = button( '+', 'increment', item.line_id );
	increment.setAttribute( 'aria-label', 'Agregar uno' );
	wrapper.append( decrement, count, increment );
	return wrapper;
};

const cartLineNode = ( item, currency, locale ) => {
	const row = node( 'li', '', 'vicu-restaurante-cart__item' );
	const meta = node( 'div', '', 'vicu-restaurante-cart__item-meta' );
	meta.append(
		node(
			'p',
			item.snapshot.name || 'Producto',
			'vicu-restaurante-cart__item-name'
		)
	);

	const detail =
		item.type === 'pizza'
			? pizzaSummary( item.snapshot.components )
			: item.snapshot.note || '';
	if ( detail ) {
		meta.append(
			node( 'p', detail, 'vicu-restaurante-cart__item-detail' )
		);
	}

	if ( item.type === 'pizza' ) {
		const actions = node(
			'div',
			'',
			'vicu-restaurante-cart__item-actions'
		);
		actions.append(
			button( 'Duplicar', 'duplicate-pizza-line', item.line_id ),
			button( 'Quitar', 'remove-item', item.line_id )
		);
		meta.append( actions );
	}

	meta.append( stepperNode( item ) );

	const totalsCol = node( 'div', '', 'vicu-restaurante-cart__item-totals' );
	totalsCol.append(
		node(
			'p',
			formatMoney( item.line_total_minor, currency, locale ),
			'vicu-restaurante-cart__item-total'
		)
	);
	if ( item.type !== 'pizza' ) {
		const remove = button( 'Quitar', 'remove-item', item.line_id );
		remove.className = 'vicu-restaurante-cart__item-remove';
		totalsCol.append( remove );
	}

	row.append( meta, totalsCol );
	return row;
};

const renderCart = ( element ) => {
	const list = element.querySelector( '[data-cart-items]' );
	const empty = element.querySelector( '[data-cart-empty]' );
	const layout = element.querySelector( '[data-cart-layout]' );
	list.replaceChildren();

	if ( ! currentCart || currentCart.items.length === 0 ) {
		empty.hidden = false;
		layout.hidden = true;
		setStatus( element, '' );
		return;
	}

	empty.hidden = true;
	layout.hidden = false;
	const totals = element.querySelector( '[data-cart-totals]' );
	const currency = currentCart.totals.currency;
	const locale = element.dataset.locale;

	currentCart.items.forEach( ( item ) => {
		list.append( cartLineNode( item, currency, locale ) );
	} );

	element
		.querySelectorAll( '[data-commerce-action="set-fulfillment"]' )
		.forEach( ( control ) => {
			control.setAttribute(
				'aria-checked',
				control.dataset.fulfillment === currentCart.fulfillment
					? 'true'
					: 'false'
			);
		} );

	const zoneField = element.querySelector( '[data-cart-zone-field]' );
	const zone = element.querySelector( '[data-cart-zone]' );
	zoneField.hidden = currentCart.fulfillment !== 'delivery';
	zone.replaceChildren();
	deliveryZones.forEach( ( zoneItem ) => {
		const option = node(
			'option',
			`${ zoneItem.name } (${ formatMoney(
				zoneItem.fee_minor,
				currency,
				locale
			) })`
		);
		option.value = zoneItem.public_id;
		zone.append( option );
	} );
	zone.value = currentCart.delivery_zone_id || '';

	element
		.querySelectorAll( '[data-commerce-action="set-tip"]' )
		.forEach( ( control ) => {
			control.setAttribute(
				'aria-pressed',
				Number( control.dataset.tipRate ) === currentCart.tip_rate_bps
					? 'true'
					: 'false'
			);
		} );

	const discountApplied = element.querySelector(
		'[data-cart-discount-applied]'
	);
	const discountCode = element.querySelector( '[data-cart-discount-code]' );
	discountApplied.hidden = ! currentCart.discount_code;
	discountCode.textContent = currentCart.discount_code || '';

	renderTotals( totals, currentCart.totals, locale );
	setStatus( element, '' );
};

const renderTotals = ( target, totals, locale ) => {
	target.replaceChildren();
	const taxBase = totals.subtotal_minor - totals.discount_total;
	const taxRatePercent =
		taxBase > 0 ? Math.round( ( totals.tax_total / taxBase ) * 100 ) : 0;
	const rows = [
		[ 'Subtotal', totals.subtotal_minor, false ],
		[ 'Descuento', -totals.discount_total, false ],
		[ `Impuesto (${ taxRatePercent }%)`, totals.tax_total, false ],
		[ 'Propina', totals.tip_total, false ],
		[ 'Delivery', totals.delivery_total, false ],
		[ 'Total', totals.total, true ],
	];
	rows.forEach( ( [ label, value, isTotal ] ) => {
		const row = node(
			'div',
			'',
			isTotal
				? 'vicu-restaurante-cart__totals-row is-total'
				: 'vicu-restaurante-cart__totals-row'
		);
		row.append(
			node( 'span', label ),
			node( 'span', formatMoney( value, totals.currency, locale ) )
		);
		target.append( row );
	} );
};

const renderCheckout = ( element ) => {
	const form = element.querySelector( '[data-commerce-form="checkout"]' );
	const summary = element.querySelector( '[data-checkout-summary]' );
	if ( ! currentCart || currentCart.items.length === 0 ) {
		form.hidden = true;
		summary.textContent = element.dataset.emptyMessage;
		setStatus( element, '' );
		return;
	}
	form.hidden = false;
	summary.textContent = `${
		currentCart.items.length
	} líneas. Total: ${ formatMoney(
		currentCart.totals.total,
		currentCart.totals.currency,
		element.dataset.locale
	) }`;
	const delivery = form.querySelector( '[data-delivery-fields]' );
	delivery.hidden = currentCart.fulfillment !== 'delivery';
	form.elements.delivery_address.required =
		currentCart.fulfillment === 'delivery';
	setStatus( element, '' );
};

const mutateCart = async ( element, url, method, payload = null ) => {
	if ( ! currentCart ) {
		return;
	}
	setStatus( element, element.dataset.loadingMessage );
	setError( element );
	try {
		currentCart = await request( url, {
			method,
			headers: privateHeaders( element ),
			body: JSON.stringify( {
				expected_revision: currentCart.revision,
				...( payload || {} ),
			} ),
		} );
		renderCommerce();
	} catch ( error ) {
		if ( error.status === 409 ) {
			await loadCart( element, true );
			setError( element, element.dataset.conflictMessage );
		} else {
			setError( element, error.message || element.dataset.errorMessage );
		}
		setStatus( element, '' );
	}
};

/**
 * Aplica un código de descuento y detecta el rechazo silencioso del
 * dominio: la API responde 200 con `discount_code` en null cuando el
 * código no existe o dejó de estar vigente, sin devolver un error propio.
 *
 * @param {Object} element Raíz del bloque.
 * @param {string} code    Código ingresado por la persona.
 */
const applyDiscount = async ( element, code ) => {
	const normalizedCode = ( code || '' ).trim().toUpperCase();
	await mutateCart( element, element.dataset.restCartDiscount, 'PUT', {
		code: normalizedCode,
	} );
	if (
		normalizedCode &&
		currentCart &&
		currentCart.discount_code !== normalizedCode
	) {
		setError( element, element.dataset.invalidDiscountMessage );
	}
};

const changeQuantity = ( element, lineId, delta ) => {
	const item = currentCart?.items.find(
		( candidate ) => candidate.line_id === lineId
	);
	if ( ! item ) {
		return;
	}
	if ( item.quantity + delta < 1 ) {
		return mutateCart(
			element,
			`${ element.dataset.restCartItems }/${ lineId }`,
			'DELETE'
		);
	}
	return mutateCart(
		element,
		`${ element.dataset.restCartItems }/${ lineId }`,
		'PATCH',
		{
			item: cartItemPayload( item, item.quantity + delta ),
		}
	);
};

const setFulfillment = ( element, fulfillment ) => {
	const deliveryZoneId =
		fulfillment === 'delivery'
			? currentCart?.delivery_zone_id ||
			  deliveryZones[ 0 ]?.public_id ||
			  ''
			: null;
	return mutateCart( element, element.dataset.restFulfillment, 'PUT', {
		fulfillment,
		delivery_zone_id: deliveryZoneId,
	} );
};

const duplicatePizzaLine = ( element, lineId ) => {
	const item = currentCart?.items.find(
		( candidate ) => candidate.line_id === lineId
	);
	if ( ! item || item.type !== 'pizza' ) {
		return;
	}
	return mutateCart( element, element.dataset.restCartItems, 'POST', {
		item: cartItemPayload( item, 1 ),
	} );
};

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
		// El fallback en memoria no requiere limpieza persistente.
	}
};

const rememberOrder = ( order ) => {
	currentOrder = order;
	saveSession( 'vicu_restaurante_last_order', order.public_id );
	if ( order.access_token ) {
		memoryTokens.set( order.public_id, order.access_token );
		saveSession(
			`vicu_restaurante_order_token:${ order.public_id }`,
			order.access_token
		);
		delete order.access_token;
	}
};

const orderToken = ( publicId ) =>
	memoryTokens.get( publicId ) ||
	readSession( `vicu_restaurante_order_token:${ publicId }` );

const checkout = async ( element, form ) => {
	if ( ! currentCart ) {
		return;
	}
	setStatus( element, 'Creando el pedido.' );
	setError( element );
	const data = new FormData( form );
	const keyName = `vicu_restaurante_checkout_key:${ currentCart.public_id }`;
	const key = readSession( keyName ) || idempotencyKey();
	saveSession( keyName, key );
	const delivery = currentCart.fulfillment === 'delivery';
	try {
		const order = await request( element.dataset.restOrders, {
			method: 'POST',
			headers: { ...privateHeaders( element ), 'Idempotency-Key': key },
			body: JSON.stringify( {
				expected_revision: currentCart.revision,
				customer: {
					name: data.get( 'name' ),
					phone: data.get( 'phone' ),
					email: data.get( 'email' ),
				},
				delivery_address: delivery
					? data.get( 'delivery_address' )
					: '',
				delivery_instructions: delivery
					? data.get( 'delivery_instructions' )
					: '',
				customer_note: data.get( 'customer_note' ),
			} ),
		} );
		rememberOrder( order );
		currentCart = null;
		form.hidden = true;
		const result = element.querySelector( '[data-checkout-result]' );
		result.hidden = false;
		renderOrderSummary( result, order, element.dataset.locale );
		setStatus( element, element.dataset.orderSavedMessage );
		renderCommerce();
	} catch ( error ) {
		setError( element, error.message || element.dataset.errorMessage );
		setStatus( element, '' );
	}
};

const renderOrderSummary = ( target, order, locale ) => {
	target.replaceChildren();
	target.append( node( 'p', `Pedido ${ order.order_number }` ) );
	target.append(
		node( 'p', `Estado: ${ order.status.replaceAll( '_', ' ' ) }` )
	);
	target.append(
		node(
			'p',
			`${ order.items.length } líneas. Entrega: ${ order.fulfillment }.`
		)
	);
	target.append(
		node(
			'p',
			`Total: ${ formatMoney(
				order.totals.total,
				order.currency,
				locale
			) }`
		)
	);
	if ( order.payment.instructions ) {
		target.append( node( 'p', order.payment.instructions ) );
	}
};

const loadOrder = async ( element, publicId ) => {
	if ( ! publicId ) {
		return;
	}
	setStatus( element, element.dataset.loadingMessage );
	setError( element );
	const token = orderToken( publicId );
	let headers = {};
	if ( element.dataset.restNonce ) {
		headers = { 'X-WP-Nonce': element.dataset.restNonce };
	} else if ( token ) {
		headers = { 'X-Vicu-Order-Token': token };
	}
	try {
		const order = await request(
			`${ element.dataset.restOrders }/${ publicId }`,
			{ headers }
		);
		rememberOrder( order );
		renderOrder( element, order );
		setStatus( element, '' );
	} catch ( error ) {
		setError( element, error.message || element.dataset.errorMessage );
		setStatus( element, '' );
	}
};

const renderOrder = ( element, order ) => {
	const detail = element.querySelector( '[data-order-detail]' );
	const actions = element.querySelector( '[data-order-actions]' );
	const evidence = element.querySelector(
		'[data-commerce-form="payment-evidence"]'
	);
	detail.hidden = false;
	actions.hidden = false;
	renderOrderSummary( detail, order, element.dataset.locale );
	detail.append(
		node(
			'p',
			`Vence: ${ new Intl.DateTimeFormat( element.dataset.locale, {
				dateStyle: 'medium',
				timeStyle: 'short',
			} ).format( new Date( order.payment_expires_at ) ) }`
		)
	);
	evidence.hidden = ! (
		order.payment.provider_enabled && order.status === 'pendiente_pago'
	);
	const lookup = element.querySelector(
		'[data-commerce-form="order-lookup"] input'
	);
	if ( lookup ) {
		lookup.value = order.public_id;
	}
};

const submitEvidence = async ( element, form ) => {
	if ( ! currentOrder ) {
		return;
	}
	const data = new FormData( form );
	const token = orderToken( currentOrder.public_id );
	const keyName = `vicu_restaurante_evidence_key:${ currentOrder.public_id }`;
	const key = readSession( keyName ) || idempotencyKey();
	saveSession( keyName, key );
	setStatus( element, 'Enviando la referencia.' );
	try {
		const result = await request(
			`${ element.dataset.restOrders }/${ currentOrder.public_id }/payment-evidence`,
			{
				method: 'POST',
				headers: {
					...( element.dataset.restNonce
						? { 'X-WP-Nonce': element.dataset.restNonce }
						: { 'X-Vicu-Order-Token': token } ),
					'Idempotency-Key': key,
				},
				body: JSON.stringify( { reference: data.get( 'reference' ) } ),
			}
		);
		rememberOrder( result.order );
		removeSession( keyName );
		renderOrder( element, result.order );
		form.reset();
		setStatus( element, 'Referencia enviada para revisión.' );
	} catch ( error ) {
		setError( element, error.message || element.dataset.errorMessage );
		setStatus( element, '' );
	}
};

const initialize = async ( element, role ) => {
	if ( initialized.has( element ) ) {
		return;
	}
	initialized.add( element );
	if ( role === 'cart' ) {
		await loadZones( element );
	}
	if ( shouldLoadCart( role, element.dataset.hasCartIdentity === '1' ) ) {
		await loadCart( element );
	} else if ( role === 'cart' || role === 'checkout' || role === 'header' ) {
		renderCommerce();
	}
	if ( role === 'order' ) {
		const publicId = readSession( 'vicu_restaurante_last_order' );
		if ( publicId ) {
			await loadOrder( element, publicId );
		}
	}
};

const { actions } = store( 'vicunav/restaurante-commerce', {
	actions: {
		*initialize() {
			const element = root();
			const { role } = getContext();
			yield initialize( element, role );
		},
		handleSubmit: withSyncEvent( ( event ) => {
			event.preventDefault();
			const form = event.target.closest( '[data-commerce-form]' );
			if ( ! form ) {
				return;
			}
			const element = root();
			if ( form.dataset.commerceForm === 'discount' ) {
				void applyDiscount(
					element,
					new FormData( form ).get( 'code' )
				);
			} else if ( form.dataset.commerceForm === 'checkout' ) {
				void checkout( element, form );
			} else if ( form.dataset.commerceForm === 'order-lookup' ) {
				void loadOrder(
					element,
					new FormData( form ).get( 'public_id' )
				);
			} else if ( form.dataset.commerceForm === 'payment-evidence' ) {
				void submitEvidence( element, form );
			}
		} ),
		handleClick( event ) {
			const control = event.target.closest( '[data-commerce-action]' );
			if ( ! control ) {
				return;
			}
			const element = root();
			const action = control.dataset.commerceAction;
			if ( action === 'increment' ) {
				void changeQuantity( element, control.dataset.lineId, 1 );
			}
			if ( action === 'decrement' ) {
				void changeQuantity( element, control.dataset.lineId, -1 );
			}
			if ( action === 'remove-item' ) {
				void mutateCart(
					element,
					`${ element.dataset.restCartItems }/${ control.dataset.lineId }`,
					'DELETE'
				);
			}
			if ( action === 'remove-discount' ) {
				void mutateCart(
					element,
					element.dataset.restCartDiscount,
					'DELETE'
				);
			}
			if ( action === 'set-fulfillment' ) {
				void setFulfillment( element, control.dataset.fulfillment );
			}
			if ( action === 'set-tip' ) {
				void mutateCart( element, element.dataset.restTip, 'PUT', {
					tip_rate_bps: Number( control.dataset.tipRate ),
				} );
			}
			if ( action === 'duplicate-pizza-line' ) {
				void duplicatePizzaLine( element, control.dataset.lineId );
			}
			if ( action === 'refresh-order' && currentOrder ) {
				void loadOrder( element, currentOrder.public_id );
			}
		},
		handleChange( event ) {
			const element = root();
			if ( event.target.matches( '[data-cart-zone]' ) ) {
				void mutateCart(
					element,
					element.dataset.restFulfillment,
					'PUT',
					{
						fulfillment: 'delivery',
						delivery_zone_id: event.target.value,
					}
				);
			}
		},
	},
} );

export { actions };
