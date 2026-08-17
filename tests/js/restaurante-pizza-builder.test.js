import {
	buildConfiguration,
	MAX_TOPPINGS,
	responseMessage,
	toggleTopping,
} from '../../src/blocks/restaurante-pizza-builder/model';

const context = {
	catalogRevision: 7,
	sizeId: '11111111-1111-4111-8111-111111111111',
	crustId: '22222222-2222-4222-8222-222222222222',
	sauceId: '33333333-3333-4333-8333-333333333333',
	cheeseId: '44444444-4444-4444-8444-444444444444',
	toppings: {
		'55555555-5555-4555-8555-555555555555': 'left',
	},
};

describe( 'modelo del constructor de pizzas', () => {
	test( 'genera únicamente configuración versionada y cantidad uno', () => {
		expect( buildConfiguration( context ) ).toEqual( {
			version: 1,
			catalog_revision: 7,
			size_id: context.sizeId,
			crust_id: context.crustId,
			sauce_id: context.sauceId,
			cheese_ingredient_id: context.cheeseId,
			toppings: context.toppings,
			quantity: 1,
		} );
		expect( buildConfiguration( context ) ).not.toHaveProperty(
			'total_minor'
		);
	} );

	test( 'selecciona, reasigna y elimina un topping sin duplicarlo', () => {
		const id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
		const selected = toggleTopping( {}, id, 'left' );
		expect( selected.toppings ).toEqual( { [ id ]: 'left' } );
		expect(
			toggleTopping( selected.toppings, id, 'right' ).toppings
		).toEqual( {
			[ id ]: 'right',
		} );
		expect(
			toggleTopping( selected.toppings, id, 'left' ).toppings
		).toEqual( {} );
	} );

	test( 'aplica el máximo global sin modificar la selección previa', () => {
		const toppings = Object.fromEntries(
			Array.from( { length: MAX_TOPPINGS }, ( _, index ) => [
				`ingredient-${ index }`,
				'whole',
			] )
		);
		const result = toggleTopping( toppings, 'seventh', 'right' );
		expect( result.error ).toBe( 'maximum-toppings' );
		expect( result.toppings ).toBe( toppings );
	} );

	test( 'rechaza zonas desconocidas y sanea mensajes ausentes', () => {
		expect( toggleTopping( {}, 'ingredient', 'center' ).error ).toBe(
			'invalid-zone'
		);
		expect(
			responseMessage( { message: '  No disponible. ' }, 'Error' )
		).toBe( 'No disponible.' );
		expect( responseMessage( { message: '<p></p>' }, 'Error' ) ).toBe(
			'<p></p>'
		);
		expect( responseMessage( {}, 'Error seguro' ) ).toBe( 'Error seguro' );
	} );
} );
