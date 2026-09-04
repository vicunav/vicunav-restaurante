import {
	addItemToCart,
	applyFilters,
	buildAddToCartPayload,
	initializeMenu,
	matchesMenuItem,
	normalizeText,
} from '../../src/blocks/restaurante-menu/view';

const item = {
	name: 'Penne all’Arrabbiata',
	description: 'Tomate y chile',
	category: 'pasta',
	dietary_tags: [ 'vegetarian', 'spicy' ],
};

describe( 'bloque de menú', () => {
	test( 'normaliza acentos y mayúsculas para buscar', () => {
		expect( normalizeText( '  MENÚ Ítalo  ' ) ).toBe( 'menu italo' );
	} );

	test( 'combina categoría, búsqueda y etiquetas sin tocar disponibilidad', () => {
		expect(
			matchesMenuItem( item, {
				category: 'pasta',
				search: 'arrabbiata',
				dietary: [ 'vegetarian', 'spicy' ],
			} )
		).toBe( true );
		expect(
			matchesMenuItem( item, {
				category: 'pizze',
				search: '',
				dietary: [],
			} )
		).toBe( false );
	} );

	test( 'filtra tarjetas y anuncia el conteo', () => {
		document.body.innerHTML = `
			<section data-vicu-menu-root data-empty-message="Sin resultados" data-result-singular="resultado" data-result-plural="resultados">
				<input data-menu-search value="tomate">
				<button data-menu-category="" aria-pressed="true">Todos</button>
				<input data-menu-dietary value="vegetarian" type="checkbox" checked>
				<div data-menu-status></div><p data-menu-empty hidden></p>
				<ul>
					<li data-menu-item data-category="pasta" data-dietary-tags="vegetarian spicy"><div class="vicu-restaurante-menu__item-content"><h3>Penne</h3><p>Tomate y chile</p></div></li>
					<li data-menu-item data-category="pizze" data-dietary-tags=""><div class="vicu-restaurante-menu__item-content"><h3>Diavola</h3><p>Tomate y salame</p></div></li>
				</ul>
			</section>`;
		const root = document.querySelector( '[data-vicu-menu-root]' );
		expect( applyFilters( root ) ).toBe( 1 );
		expect( root.querySelectorAll( '[data-menu-item]' )[ 0 ].hidden ).toBe(
			false
		);
		expect( root.querySelectorAll( '[data-menu-item]' )[ 1 ].hidden ).toBe(
			true
		);
		expect( root.querySelector( '[data-menu-status]' ).textContent ).toBe(
			'1 resultado'
		);
	} );

	test( 'conserva el SSR y muestra error si falla el refresh', async () => {
		document.body.innerHTML = `
			<section data-vicu-menu-root data-rest-url="/menu" data-loading-message="Cargando" data-error-message="Error seguro" data-empty-message="Vacío" data-result-singular="resultado" data-result-plural="resultados">
				<input data-menu-search><button data-menu-category="" aria-pressed="true">Todos</button>
				<div data-menu-status></div><p data-menu-error hidden></p><p data-menu-empty hidden></p>
				<ul data-menu-items><li data-menu-item data-category="pasta" data-dietary-tags=""><div class="vicu-restaurante-menu__item-content"><h3>Penne</h3><p>Tomate</p></div></li></ul>
			</section>`;
		const root = document.querySelector( '[data-vicu-menu-root]' );
		await initializeMenu(
			root,
			jest.fn().mockResolvedValue( { ok: false, status: 503 } )
		);
		await Promise.resolve();
		expect( root.querySelectorAll( '[data-menu-item]' ) ).toHaveLength( 1 );
		expect( root.querySelector( '[data-menu-error]' ).textContent ).toBe(
			'Error seguro'
		);
	} );

	test( 'construye la selección de carrito para un plato normal', () => {
		expect( buildAddToCartPayload( 'abc-123' ) ).toEqual( {
			type: 'menu',
			menu_item_id: 'abc-123',
			quantity: 1,
		} );
	} );

	test( 'agrega un plato reusando el carrito activo existente', async () => {
		document.body.innerHTML = `<section data-cart-url="/cart" data-carts-url="/carts" data-cart-items-url="/cart/items" data-rest-nonce="nonce-123"></section>`;
		const root = document.querySelector( 'section' );
		const request = jest
			.fn()
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { revision: 4, csrf_token: 'x' } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { revision: 5, items: [] } ),
			} );

		await addItemToCart( root, 'menu-item-1', request );

		expect( request ).toHaveBeenNthCalledWith( 1, '/cart', {
			headers: { 'X-WP-Nonce': 'nonce-123' },
		} );
		const [ , [ url, options ] ] = request.mock.calls;
		expect( url ).toBe( '/cart/items' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'nonce-123' );
		expect( JSON.parse( options.body ) ).toEqual( {
			expected_revision: 4,
			item: { type: 'menu', menu_item_id: 'menu-item-1', quantity: 1 },
		} );
	} );

	test( 'crea el carrito cuando todavía no existe uno propio', async () => {
		document.body.innerHTML = `<section data-cart-url="/cart" data-carts-url="/carts" data-cart-items-url="/cart/items"></section>`;
		const root = document.querySelector( 'section' );
		const request = jest
			.fn()
			.mockResolvedValueOnce( { ok: false, status: 404 } )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { revision: 1, csrf_token: 'y' } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { revision: 2, items: [] } ),
			} );

		await addItemToCart( root, 'menu-item-2', request );

		expect( request ).toHaveBeenNthCalledWith( 2, '/carts', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: '{}',
		} );
		const [ , , [ , options ] ] = request.mock.calls;
		expect( options.headers[ 'X-Vicu-Csrf' ] ).toBe( 'y' );
	} );

	test( 'propaga el mensaje de error del servidor al fallar la mutación', async () => {
		document.body.innerHTML = `<section data-cart-url="/cart" data-carts-url="/carts" data-cart-items-url="/cart/items" data-add-error-message="Error seguro"></section>`;
		const root = document.querySelector( 'section' );
		const request = jest
			.fn()
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { revision: 1, csrf_token: 'z' } ),
			} )
			.mockResolvedValueOnce( {
				ok: false,
				json: async () => ( {
					message: 'Ese plato ya no está disponible.',
				} ),
			} );

		await expect(
			addItemToCart( root, 'menu-item-3', request )
		).rejects.toThrow( 'Ese plato ya no está disponible.' );
	} );

	test( 'el clic en Agregar deshabilita, confirma y restaura el botón', async () => {
		jest.useFakeTimers();
		document.body.innerHTML = `
			<section data-vicu-menu-root data-rest-url="/menu" data-cart-url="/cart" data-carts-url="/carts" data-cart-items-url="/cart/items" data-add-label="Agregar" data-adding-label="Agregando" data-added-label="Agregado">
				<div data-menu-status></div><p data-menu-error hidden></p>
				<ul data-menu-items><li data-menu-item><button data-menu-add data-public-id="menu-item-4">Agregar</button></li></ul>
			</section>`;
		const root = document.querySelector( '[data-vicu-menu-root]' );
		const request = jest
			.fn()
			.mockResolvedValueOnce( { ok: false, status: 503 } )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { revision: 1, csrf_token: 'z' } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { revision: 2, items: [] } ),
			} );

		await initializeMenu( root, request );
		const button = root.querySelector( '[data-menu-add]' );
		button.click();
		for ( let tick = 0; tick < 8; tick += 1 ) {
			await Promise.resolve();
		}
		expect( button.disabled ).toBe( true );
		expect( button.textContent ).toBe( 'Agregado' );

		jest.advanceTimersByTime( 1500 );
		expect( button.disabled ).toBe( false );
		expect( button.textContent ).toBe( 'Agregar' );
		jest.useRealTimers();
	} );
} );
