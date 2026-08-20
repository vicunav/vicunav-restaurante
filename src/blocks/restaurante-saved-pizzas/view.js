import { getElement, store, withSyncEvent } from '@wordpress/interactivity';

import {
	catalogIndex,
	configurationSummary,
	currentConfiguration,
	responseMessage,
} from './model';

const initialized = new WeakSet();
const state = new WeakMap();
const root = () => getElement().ref.closest( '[data-vicu-saved-pizzas-root]' );

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

const privateHeaders = ( element ) => ( {
	'X-WP-Nonce': element.dataset.restNonce,
} );

const setStatus = ( element, message = '' ) => {
	element.querySelector( '[data-saved-pizzas-status]' ).textContent = message;
};

const setError = ( element, message = '' ) => {
	const target = element.querySelector( '[data-saved-pizzas-error]' );
	target.textContent = message;
	target.hidden = ! message;
	if ( message ) {
		target.focus();
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

const button = ( text, action ) => {
	const result = node( 'button', text );
	result.type = 'button';
	result.dataset.savedPizzaAction = action;
	return result;
};

const render = ( element ) => {
	const current = state.get( element );
	const list = element.querySelector( '[data-saved-pizzas-list]' );
	const empty = element.querySelector( '[data-saved-pizzas-empty]' );
	const index = catalogIndex( current.catalog );
	list.replaceChildren();
	empty.hidden = current.items.length > 0;

	current.items.forEach( ( item ) => {
		const row = node( 'li', '', 'vicu-restaurante-saved-pizzas__item' );
		row.dataset.publicId = item.public_id;
		const title = node( 'h3', item.name );
		const summary = node(
			'p',
			configurationSummary( item.configuration, index )
		);
		const updated = node(
			'p',
			`Actualizada: ${ new Intl.DateTimeFormat( element.dataset.locale, {
				dateStyle: 'medium',
			} ).format( new Date( item.updated_at ) ) }`
		);
		const rename = document.createElement( 'form' );
		rename.dataset.savedPizzaForm = 'rename';
		const label = document.createElement( 'label' );
		label.textContent = 'Nombre';
		const input = document.createElement( 'input' );
		input.name = 'name';
		input.value = item.name;
		input.maxLength = 100;
		input.required = true;
		label.append( input );
		const save = node( 'button', 'Guardar nombre' );
		save.type = 'submit';
		rename.append( label, save );

		const actions = node(
			'div',
			'',
			'vicu-restaurante-saved-pizzas__actions'
		);
		actions.append(
			button( 'Añadir al carrito', 'add-to-cart' ),
			button( 'Crear o reemplazar enlace', 'share' )
		);
		if ( current.pendingDeleteId === item.public_id ) {
			actions.append(
				button( 'Confirmar eliminación', 'confirm-delete' ),
				button( 'Conservar pizza', 'cancel-delete' )
			);
		} else {
			actions.append( button( 'Eliminar', 'request-delete' ) );
		}
		const share = node( 'div', '', 'vicu-restaurante-saved-pizzas__share' );
		share.dataset.savedPizzaShare = '';
		row.append( title, summary, updated, rename, actions, share );
		list.append( row );
	} );
	setStatus( element );
};

const load = async ( element, silent = false ) => {
	if ( ! silent ) {
		setStatus( element, element.dataset.loadingMessage );
	}
	setError( element );
	try {
		const [ collection, catalog ] = await Promise.all( [
			request( element.dataset.restSavedPizzas, {
				headers: privateHeaders( element ),
			} ),
			request( element.dataset.restPizzaOptions ),
		] );
		state.set( element, {
			items: Array.isArray( collection.items ) ? collection.items : [],
			catalog,
			pendingDeleteId: '',
		} );
		render( element );
	} catch ( error ) {
		setError( element, error.message || element.dataset.errorMessage );
		setStatus( element );
	}
};

const itemFor = ( element, control ) => {
	const publicId = control.closest( '[data-public-id]' )?.dataset.publicId;
	return state
		.get( element )
		.items.find( ( item ) => item.public_id === publicId );
};

const replaceItem = ( element, item ) => {
	const current = state.get( element );
	const safeItem = { ...item };
	delete safeItem.share_token;
	delete safeItem.share_path;
	current.items = current.items.map( ( candidate ) =>
		candidate.public_id === safeItem.public_id ? safeItem : candidate
	);
	current.pendingDeleteId = '';
	render( element );
};

const mutate = async ( element, item, path, method, payload, success ) => {
	setStatus( element, 'Guardando cambios.' );
	setError( element );
	try {
		const result = await request(
			`${ element.dataset.restSavedPizzas }/${ item.public_id }${ path }`,
			{
				method,
				headers: privateHeaders( element ),
				body: JSON.stringify( {
					expected_revision: item.revision,
					...payload,
				} ),
			}
		);
		replaceItem( element, result );
		setStatus( element, success );
		return result;
	} catch ( error ) {
		if ( error.status === 409 ) {
			await load( element, true );
			setError( element, element.dataset.conflictMessage );
		} else {
			setError( element, error.message || element.dataset.errorMessage );
		}
		setStatus( element );
		return null;
	}
};

const rename = ( element, form ) => {
	const item = itemFor( element, form );
	if ( item ) {
		void mutate(
			element,
			item,
			'',
			'PATCH',
			{ name: new FormData( form ).get( 'name' ) },
			element.dataset.renamedMessage
		);
	}
};

const remove = async ( element, item ) => {
	setStatus( element, 'Eliminando la pizza.' );
	setError( element );
	try {
		await request(
			`${ element.dataset.restSavedPizzas }/${ item.public_id }`,
			{
				method: 'DELETE',
				headers: privateHeaders( element ),
				body: JSON.stringify( { expected_revision: item.revision } ),
			}
		);
		const current = state.get( element );
		current.items = current.items.filter(
			( candidate ) => candidate.public_id !== item.public_id
		);
		current.pendingDeleteId = '';
		render( element );
		setStatus( element, element.dataset.deletedMessage );
	} catch ( error ) {
		if ( error.status === 409 ) {
			await load( element, true );
			setError( element, element.dataset.conflictMessage );
		} else {
			setError( element, error.message || element.dataset.errorMessage );
		}
		setStatus( element );
	}
};

const showShare = ( element, publicId, sharePath ) => {
	const card = Array.from(
		element.querySelectorAll( '[data-public-id]' )
	).find( ( candidate ) => candidate.dataset.publicId === publicId );
	const target = card?.querySelector( '[data-saved-pizza-share]' );
	if ( ! target ) {
		return;
	}
	const url = new URL( sharePath, window.location.origin ).href;
	const label = document.createElement( 'label' );
	label.textContent = 'Enlace público nuevo';
	const input = document.createElement( 'input' );
	input.readOnly = true;
	input.value = url;
	label.append( input );
	const copy = button( 'Copiar enlace', 'copy-share' );
	target.replaceChildren( label, copy );
};

const share = async ( element, item ) => {
	const result = await mutate(
		element,
		item,
		'/share',
		'POST',
		{},
		element.dataset.shareMessage
	);
	if ( result?.share_path ) {
		showShare( element, item.public_id, result.share_path );
	}
};

const getCart = async ( element ) => {
	try {
		return await request( element.dataset.restCart, {
			headers: privateHeaders( element ),
		} );
	} catch ( error ) {
		if ( ! [ 401, 404 ].includes( error.status ) ) {
			throw error;
		}
		return request( element.dataset.restCarts, {
			method: 'POST',
			headers: privateHeaders( element ),
			body: '{}',
		} );
	}
};

const addToCart = async ( element, item ) => {
	setStatus( element, 'Revalidando la pizza.' );
	setError( element );
	try {
		const configuration = currentConfiguration(
			item.configuration,
			state.get( element ).catalog.revision
		);
		const quote = await request( element.dataset.restPizzaQuote, {
			method: 'POST',
			body: JSON.stringify( { configuration } ),
		} );
		const cart = await getCart( element );
		await request( element.dataset.restCartItems, {
			method: 'POST',
			headers: privateHeaders( element ),
			body: JSON.stringify( {
				expected_revision: cart.revision,
				item: { type: 'pizza', configuration: quote.configuration },
			} ),
		} );
		setStatus( element, element.dataset.addedMessage );
	} catch ( error ) {
		setError( element, error.message || element.dataset.errorMessage );
		setStatus( element );
	}
};

const copyShare = async ( element, control ) => {
	const input = control.parentElement.querySelector( 'input' );
	if ( ! input ) {
		return;
	}
	try {
		await navigator.clipboard.writeText( input.value );
		setStatus( element, 'Enlace copiado.' );
	} catch {
		input.select();
		setStatus(
			element,
			'Seleccionamos el enlace para que puedas copiarlo.'
		);
	}
};

const initialize = async ( element ) => {
	if ( initialized.has( element ) || element.dataset.authenticated !== '1' ) {
		return;
	}
	initialized.add( element );
	state.set( element, { items: [], catalog: {}, pendingDeleteId: '' } );
	await load( element );
};

store( 'vicunav/restaurante-saved-pizzas', {
	actions: {
		*initialize() {
			yield initialize( root() );
		},
		handleSubmit: withSyncEvent( ( event ) => {
			const form = event.target.closest( '[data-saved-pizza-form]' );
			if ( ! form ) {
				return;
			}
			event.preventDefault();
			rename( root(), form );
		} ),
		handleClick( event ) {
			const control = event.target.closest( '[data-saved-pizza-action]' );
			if ( ! control ) {
				return;
			}
			const element = root();
			if ( control.dataset.savedPizzaAction === 'copy-share' ) {
				void copyShare( element, control );
				return;
			}
			const item = itemFor( element, control );
			if ( ! item ) {
				return;
			}
			const current = state.get( element );
			switch ( control.dataset.savedPizzaAction ) {
				case 'add-to-cart':
					void addToCart( element, item );
					break;
				case 'share':
					void share( element, item );
					break;
				case 'request-delete':
					current.pendingDeleteId = item.public_id;
					render( element );
					break;
				case 'cancel-delete':
					current.pendingDeleteId = '';
					render( element );
					break;
				case 'confirm-delete':
					void remove( element, item );
					break;
			}
		},
	},
} );
