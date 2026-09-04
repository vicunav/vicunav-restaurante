/**
 * Mejora progresiva del bloque de menú.
 */

/**
 * Construye la selección que REST revalidará para agregar un plato normal
 * (sin configuración de pizza) al carrito.
 *
 * @param {string} publicId UUID público del item de menú.
 * @param {number} quantity Cantidad candidata.
 * @return {Object} Selección aceptada por CartLinePricing::quote().
 */
export function buildAddToCartPayload( publicId, quantity = 1 ) {
	return { type: 'menu', menu_item_id: publicId, quantity };
}

/**
 * Normaliza copy para una búsqueda tolerante a mayúsculas y acentos.
 *
 * @param {string} value Texto candidato.
 * @return {string} Texto comparable.
 */
export function normalizeText( value ) {
	return String( value || '' )
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.toLocaleLowerCase()
		.trim();
}

/**
 * Comprueba filtros visuales sin decidir disponibilidad ni importes.
 *
 * @param {Object} item    Item público.
 * @param {Object} filters Filtros seleccionados.
 * @return {boolean} Si el item coincide.
 */
export function matchesMenuItem( item, filters ) {
	const tags = Array.isArray( item.dietary_tags ) ? item.dietary_tags : [];
	const searchable = normalizeText(
		`${ item.name || '' } ${ item.description || '' }`
	);

	return (
		( ! filters.category || item.category === filters.category ) &&
		( ! filters.search ||
			searchable.includes( normalizeText( filters.search ) ) ) &&
		filters.dietary.every( ( tag ) => tags.includes( tag ) )
	);
}

/**
 * Deriva los filtros desde controles nativos del bloque.
 *
 * @param {HTMLElement} root Raíz del bloque.
 * @return {Object} Filtros activos.
 */
function currentFilters( root ) {
	const selected = root.querySelector(
		'[data-menu-category][aria-pressed="true"]'
	);
	return {
		category: selected ? selected.dataset.menuCategory || '' : '',
		search: root.querySelector( '[data-menu-search]' )?.value || '',
		dietary: Array.from(
			root.querySelectorAll( '[data-menu-dietary]:checked' )
		).map( ( input ) => input.value ),
	};
}

/**
 * Filtra el DOM ya validado por el servidor y anuncia el resultado.
 *
 * @param {HTMLElement} root Raíz del bloque.
 * @return {number} Cantidad visible.
 */
export function applyFilters( root ) {
	const filters = currentFilters( root );
	const items = Array.from( root.querySelectorAll( '[data-menu-item]' ) );
	let visible = 0;

	items.forEach( ( card ) => {
		const item = {
			name: card.querySelector( 'h3' )?.textContent || '',
			description:
				card.querySelector( '.vicu-restaurante-menu__item-content > p' )
					?.textContent || '',
			category: card.dataset.category || '',
			dietary_tags: ( card.dataset.dietaryTags || '' )
				.split( ' ' )
				.filter( Boolean ),
		};
		const matches = matchesMenuItem( item, filters );
		card.hidden = ! matches;
		visible += matches ? 1 : 0;
	} );

	const empty = root.querySelector( '[data-menu-empty]' );
	if ( empty ) {
		empty.hidden = visible > 0;
		empty.textContent = root.dataset.emptyMessage || '';
	}

	const status = root.querySelector( '[data-menu-status]' );
	if ( status && ! root.getAttribute( 'aria-busy' ) ) {
		status.textContent = `${ visible } ${
			visible === 1
				? root.dataset.resultSingular
				: root.dataset.resultPlural
		}`;
	}

	return visible;
}

/**
 * Crea un elemento con clase y texto sin interpretar HTML remoto.
 *
 * @param {string} tagName   Etiqueta HTML.
 * @param {string} className Clase opcional.
 * @param {string} text      Texto opcional.
 * @return {HTMLElement} Elemento seguro.
 */
function createElement( tagName, className, text = '' ) {
	const node = document.createElement( tagName );
	if ( className ) {
		node.className = className;
	}
	if ( text ) {
		node.textContent = text;
	}
	return node;
}

/**
 * Formatea el importe informativo que ya entregó el servidor.
 *
 * @param {Object} item Item público.
 * @return {string} Importe localizado.
 */
function price( item ) {
	try {
		return new Intl.NumberFormat( document.documentElement.lang || 'es', {
			style: 'currency',
			currency: item.currency,
		} ).format( item.price_minor / 100 );
	} catch {
		return `${ ( item.price_minor / 100 ).toFixed( 2 ) } ${
			item.currency
		}`;
	}
}

/**
 * Construye una tarjeta desde el contrato REST validado.
 *
 * @param {Object}      item Item público.
 * @param {HTMLElement} root Raíz del bloque con copy localizado.
 * @return {HTMLLIElement} Tarjeta segura.
 */
function itemElement( item, root ) {
	const card = createElement( 'li', 'vicu-restaurante-menu__item' );
	card.dataset.menuItem = '';
	card.dataset.category = item.category;
	card.dataset.search = normalizeText(
		`${ item.name } ${ item.description }`
	);
	card.dataset.dietaryTags = item.dietary_tags.join( ' ' );
	card.dataset.publicId = item.public_id;
	if ( ! item.available ) {
		card.classList.add( 'is-unavailable' );
	}

	if ( item.image?.url ) {
		const image = document.createElement( 'img' );
		image.className = 'vicu-restaurante-menu__image';
		image.src = item.image.url;
		image.alt = item.image.alt || '';
		image.width = item.image.width;
		image.height = item.image.height;
		image.loading = 'lazy';
		image.decoding = 'async';
		card.append( image );
	}

	const content = createElement(
		'div',
		'vicu-restaurante-menu__item-content'
	);
	const heading = createElement(
		'div',
		'vicu-restaurante-menu__item-heading'
	);
	heading.append( createElement( 'h3', '', item.name ) );
	const availability = createElement(
		'span',
		'vicu-restaurante-menu__availability',
		item.available
			? root.dataset.availableLabel
			: root.dataset.unavailableLabel
	);
	availability.dataset.menuAvailability = '';
	heading.append( availability );
	content.append( heading, createElement( 'p', '', item.description ) );

	const amount = createElement(
		'p',
		'vicu-restaurante-menu__price',
		price( item )
	);
	amount.dataset.priceMinor = String( item.price_minor );
	amount.dataset.currency = item.currency;
	content.append( amount );

	if ( item.dietary_tags.length ) {
		const labels = {
			spicy: root.dataset.spicyLabel,
			vegan: root.dataset.veganLabel,
			vegetarian: root.dataset.vegetarianLabel,
		};
		content.append(
			createElement(
				'p',
				'vicu-restaurante-menu__tags',
				item.dietary_tags
					.map( ( tag ) => labels[ tag ] || tag )
					.join( ' · ' )
			)
		);
	}
	if ( item.allergens.length ) {
		content.append(
			createElement(
				'p',
				'vicu-restaurante-menu__allergens',
				`${ root.dataset.allergensLabel } ${ item.allergens.join(
					', '
				) }`
			)
		);
	}

	const addButton = createElement(
		'button',
		'vicu-restaurante-menu__add',
		root.dataset.addLabel
	);
	addButton.type = 'button';
	addButton.dataset.menuAdd = '';
	addButton.dataset.publicId = item.public_id;
	addButton.disabled = ! item.available;
	content.append( addButton );

	card.append( content );
	return card;
}

/**
 * Encabezados de autorización del carrito: nonce si hay sesión, o el CSRF
 * opaco que el propio carrito entrega para invitados.
 *
 * @param {HTMLElement} root Raíz del bloque.
 * @param {Object}      cart Carrito ya resuelto, si existe.
 * @return {Object} Encabezados adicionales para la mutación.
 */
function cartHeaders( root, cart ) {
	if ( root.dataset.restNonce ) {
		return { 'X-WP-Nonce': root.dataset.restNonce };
	}

	return cart?.csrf_token ? { 'X-Vicu-Csrf': cart.csrf_token } : {};
}

/**
 * Obtiene el carrito activo propio o crea uno nuevo cuando todavía no existe.
 *
 * @param {HTMLElement} root    Raíz del bloque.
 * @param {Function}    request Implementación de fetch inyectable.
 * @return {Promise<Object>} Carrito resuelto.
 */
async function getOrCreateCart( root, request ) {
	const headers = root.dataset.restNonce
		? { 'X-WP-Nonce': root.dataset.restNonce }
		: {};

	const existing = await request( root.dataset.cartUrl, { headers } );
	if ( existing.ok ) {
		return existing.json();
	}
	if ( 401 !== existing.status && 404 !== existing.status ) {
		throw new Error( `HTTP ${ existing.status }` );
	}

	const created = await request( root.dataset.cartsUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', ...headers },
		body: '{}',
	} );
	if ( ! created.ok ) {
		throw new Error( `HTTP ${ created.status }` );
	}
	return created.json();
}

/**
 * Agrega un plato del menú al carrito activo, creándolo si hace falta.
 *
 * @param {HTMLElement} root     Raíz del bloque.
 * @param {string}      publicId UUID público del item de menú.
 * @param {Function}    request  Implementación de fetch inyectable.
 * @return {Promise<Object>} Carrito actualizado.
 */
export async function addItemToCart(
	root,
	publicId,
	request = window.fetch.bind( window )
) {
	const cart = await getOrCreateCart( root, request );
	const response = await request( root.dataset.cartItemsUrl, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			...cartHeaders( root, cart ),
		},
		body: JSON.stringify( {
			expected_revision: cart.revision,
			item: buildAddToCartPayload( publicId ),
		} ),
	} );
	const payload = await response.json().catch( () => ( {} ) );
	if ( ! response.ok ) {
		const error = new Error(
			payload?.message?.trim() || root.dataset.addErrorMessage
		);
		error.status = response.status;
		throw error;
	}
	return payload;
}

/**
 * Refresca el catálogo y conserva el fallback SSR si falla la red.
 *
 * @param {HTMLElement} root    Raíz del bloque.
 * @param {Function}    request Implementación de fetch inyectable.
 * @return {Promise<void>} Finalización del refresh.
 */
export async function refreshMenu(
	root,
	request = window.fetch.bind( window )
) {
	const status = root.querySelector( '[data-menu-status]' );
	const error = root.querySelector( '[data-menu-error]' );
	root.setAttribute( 'aria-busy', 'true' );
	if ( status ) {
		status.textContent = root.dataset.loadingMessage || '';
	}
	if ( error ) {
		error.hidden = true;
		error.textContent = '';
	}

	try {
		const response = await request( root.dataset.restUrl, {
			headers: { Accept: 'application/json' },
		} );
		if ( ! response.ok ) {
			throw new Error( `HTTP ${ response.status }` );
		}
		const catalog = await response.json();
		if (
			! Array.isArray( catalog.items ) ||
			! Array.isArray( catalog.categories )
		) {
			throw new Error( 'Respuesta de catálogo inválida.' );
		}

		const list = root.querySelector( '[data-menu-items]' );
		if ( list ) {
			list.replaceChildren(
				...catalog.items.map( ( item ) => itemElement( item, root ) )
			);
		}
		root.dataset.catalogRevision = String( catalog.revision );
	} catch {
		if ( error ) {
			error.hidden = false;
			error.textContent = root.dataset.errorMessage || '';
		}
	} finally {
		root.removeAttribute( 'aria-busy' );
		applyFilters( root );
	}
}

/**
 * Agrega un plato al hacer clic en su botón, con retroalimentación visible
 * y accesible durante la solicitud y ante error.
 *
 * @param {HTMLElement} root    Raíz del bloque.
 * @param {HTMLElement} button  Botón "Agregar" pulsado.
 * @param {Function}    request Implementación de fetch inyectable.
 * @return {Promise<void>} Finalización de la solicitud.
 */
async function handleAddToCart( root, button, request ) {
	const status = root.querySelector( '[data-menu-status]' );
	const error = root.querySelector( '[data-menu-error]' );
	const originalLabel = root.dataset.addLabel;

	button.disabled = true;
	button.textContent = root.dataset.addingLabel || originalLabel;
	if ( error ) {
		error.hidden = true;
		error.textContent = '';
	}

	try {
		await addItemToCart( root, button.dataset.publicId, request );
		button.textContent = root.dataset.addedLabel || originalLabel;
		if ( status ) {
			status.textContent = root.dataset.addedLabel || originalLabel;
		}
		setTimeout( () => {
			button.textContent = originalLabel;
			button.disabled = false;
		}, 1500 );
	} catch ( caught ) {
		button.textContent = originalLabel;
		button.disabled = false;
		if ( error ) {
			error.hidden = false;
			error.textContent = caught.message || root.dataset.addErrorMessage;
		}
	}
}

/**
 * Inicializa delegación de eventos y refresh una sola vez.
 *
 * @param {HTMLElement} root    Raíz del bloque.
 * @param {Function}    request Implementación de fetch opcional.
 * @return {Promise<void>} Finalización de la carga inicial.
 */
export function initializeMenu( root, request ) {
	if ( root.dataset.menuInitialized === 'true' ) {
		return Promise.resolve();
	}
	root.dataset.menuInitialized = 'true';

	root.addEventListener( 'input', ( event ) => {
		if (
			event.target.matches( '[data-menu-search], [data-menu-dietary]' )
		) {
			applyFilters( root );
		}
	} );
	root.addEventListener( 'click', ( event ) => {
		const categoryButton = event.target.closest( '[data-menu-category]' );
		if ( categoryButton && root.contains( categoryButton ) ) {
			root.querySelectorAll( '[data-menu-category]' ).forEach(
				( option ) => {
					option.setAttribute(
						'aria-pressed',
						String( option === categoryButton )
					);
				}
			);
			applyFilters( root );
			return;
		}

		const addButton = event.target.closest( '[data-menu-add]' );
		if ( addButton && root.contains( addButton ) && ! addButton.disabled ) {
			handleAddToCart( root, addButton, request );
		}
	} );

	return refreshMenu( root, request );
}

if ( typeof document !== 'undefined' ) {
	const initializeAll = () => {
		document
			.querySelectorAll( '[data-vicu-menu-root]' )
			.forEach( ( root ) => initializeMenu( root ) );
	};
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initializeAll, {
			once: true,
		} );
	} else {
		initializeAll();
	}
}
