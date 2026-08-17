import {
	applyFilters,
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
} );
