import {
	buildConfiguration,
	crustContainsGluten,
	MAX_TOPPINGS,
	pizzaCheeseStyle,
	pizzaDietary,
	pizzaDots,
	pizzaSauceStyle,
	pizzaSizeScale,
	pizzaToppingsByZone,
	responseMessage,
	stableHash,
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

describe( 'vista previa de la pizza', () => {
	test( 'stableHash es determinista para el mismo UUID', () => {
		const id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
		expect( stableHash( id ) ).toBe( stableHash( id ) );
		expect( stableHash( id ) ).not.toBe( stableHash( id + 'x' ) );
	} );

	test( 'pizzaDots coloca 4 puntos para una zona completa y 2 por mitad', () => {
		const whole = pizzaDots( { a: 'whole' } );
		expect( whole ).toHaveLength( 4 );
		whole.forEach( ( dot ) => {
			expect( dot.style ).toMatch( /position:absolute;left:/ );
		} );

		const halves = pizzaDots( { a: 'left', b: 'right' } );
		expect( halves ).toHaveLength( 4 );
	} );

	test( 'pizzaDots es estable entre llamadas para la misma selección', () => {
		const toppings = { a: 'whole', b: 'left' };
		expect( pizzaDots( toppings ) ).toEqual( pizzaDots( toppings ) );
	} );

	test( 'pizzaSizeScale lee el diámetro en cm del nombre y lo acota', () => {
		expect( pizzaSizeScale( 'Mediana (30 cm)' ) ).toBeCloseTo( 1 );
		expect( pizzaSizeScale( 'Personal (22 cm)' ) ).toBeCloseTo( 22 / 30 );
		expect( pizzaSizeScale( 'Familiar (60 cm)' ) ).toBeCloseTo( 1.3 );
		expect( pizzaSizeScale( 'Sin unidad declarada' ) ).toBe( 1 );
		expect( pizzaSizeScale() ).toBe( 1 );
	} );

	test( 'pizzaSauceStyle aproxima color por palabra clave y oculta "sin salsa"', () => {
		expect( pizzaSauceStyle( 'Sin salsa' ) ).toBe( 'display:none;' );
		expect( pizzaSauceStyle( 'Pesto' ) ).toContain( '#6b7f4a' );
		expect( pizzaSauceStyle( 'Blanca al ajo' ) ).toContain( '#f3ead8' );
		expect( pizzaSauceStyle( 'San Marzano' ) ).toContain( '#a8432b' );
	} );

	test( 'pizzaCheeseStyle oculta la capa solo cuando el nombre dice "sin queso"', () => {
		expect( pizzaCheeseStyle( 'Sin queso' ) ).toBe( 'display:none;' );
		expect( pizzaCheeseStyle( 'Mozzarella' ) ).toContain( 'background' );
	} );

	test( 'crustContainsGluten solo es falso ante "sin gluten" explícito', () => {
		expect( crustContainsGluten( 'Napolitana' ) ).toBe( true );
		expect( crustContainsGluten( 'Sin gluten' ) ).toBe( false );
		expect( crustContainsGluten( 'SIN GLUTEN' ) ).toBe( false );
	} );

	test( 'pizzaDietary combina dietas de queso y toppings seleccionados', () => {
		const catalog = {
			cheese1: { dietaryTags: [ 'vegetarian' ], allergens: [ 'milk' ] },
			veg1: { dietaryTags: [ 'vegetarian' ] },
			meat1: { dietaryTags: [] },
		};

		expect( pizzaDietary( 'cheese1', { veg1: 'whole' }, catalog ) ).toEqual(
			{ isVegetarian: true, hasDairy: true }
		);

		expect(
			pizzaDietary( 'cheese1', { meat1: 'whole' }, catalog )
		).toEqual( { isVegetarian: false, hasDairy: true } );

		expect( pizzaDietary( '', {}, catalog ) ).toEqual( {
			isVegetarian: true,
			hasDairy: false,
		} );
	} );

	test( 'pizzaToppingsByZone agrupa nombres por zona y omite vacías', () => {
		const catalog = {
			a: { name: 'Pepperoni' },
			b: { name: 'Aceitunas negras' },
			c: { name: 'Pimentón' },
		};

		expect(
			pizzaToppingsByZone(
				{ a: 'whole', b: 'whole', c: 'left' },
				catalog
			)
		).toEqual( {
			whole: 'Pepperoni, Aceitunas negras',
			left: 'Pimentón',
			right: '',
		} );
	} );
} );
