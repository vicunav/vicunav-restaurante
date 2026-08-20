import {
	catalogIndex,
	configurationSummary,
	currentConfiguration,
	responseMessage,
} from '../../src/blocks/restaurante-saved-pizzas/model';

const configuration = {
	version: 1,
	catalog_revision: 4,
	size_id: 'size-id',
	crust_id: 'crust-id',
	sauce_id: 'sauce-id',
	cheese_ingredient_id: 'cheese-id',
	toppings: { 'topping-id': 'left' },
	quantity: 3,
	total_minor: 99999,
};

describe( 'modelo cliente de pizzas guardadas', () => {
	test( 'reconstruye una configuración actual sin importar precios ni cantidad guardada', () => {
		const current = currentConfiguration( configuration, 8 );
		expect( current ).toEqual( {
			version: 1,
			catalog_revision: 8,
			size_id: 'size-id',
			crust_id: 'crust-id',
			sauce_id: 'sauce-id',
			cheese_ingredient_id: 'cheese-id',
			toppings: { 'topping-id': 'left' },
			quantity: 1,
		} );
		expect( current ).not.toHaveProperty( 'total_minor' );
	} );

	test( 'indexa el catálogo agrupado y resume únicamente nombres', () => {
		const index = catalogIndex( {
			sizes: [ { public_id: 'size-id', name: 'Mediana' } ],
			crusts: [ { public_id: 'crust-id', name: 'Clásica' } ],
			sauces: [ { public_id: 'sauce-id', name: 'Tomate' } ],
			cheeses: [ { public_id: 'cheese-id', name: 'Mozzarella' } ],
			toppings: [ { public_id: 'topping-id', name: 'Albahaca' } ],
		} );
		expect( configurationSummary( configuration, index ) ).toBe(
			'Mediana · Clásica · Tomate · Mozzarella · Albahaca'
		);
	} );

	test( 'marca referencias ausentes sin inventar una selección', () => {
		expect( configurationSummary( configuration, new Map() ) ).toContain(
			'Selección no disponible'
		);
	} );

	test( 'presenta mensajes REST como texto y conserva fallback', () => {
		expect( responseMessage( { message: '  Conflicto. ' }, 'Error' ) ).toBe(
			'Conflicto.'
		);
		expect( responseMessage( {}, 'Error seguro' ) ).toBe( 'Error seguro' );
	} );
} );
