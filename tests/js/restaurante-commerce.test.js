import {
	cartItemPayload,
	formatMoney,
	idempotencyKey,
	responseMessage,
	shouldLoadCart,
} from '../../src/blocks/restaurante-commerce-assets/model';

describe( 'modelo cliente de comercio', () => {
	test( 'reconstruye líneas de menú y pizza sin copiar importes', () => {
		const menu = cartItemPayload(
			{
				type: 'menu',
				selection: {
					menu_item_id: 'menu-id',
					quantity: 1,
					options: [],
				},
				unit_price_minor: 999,
			},
			3
		);
		const pizza = cartItemPayload(
			{
				type: 'pizza',
				selection: { configuration: { version: 1, quantity: 1 } },
				line_total_minor: 999,
			},
			2
		);

		expect( menu ).toEqual( {
			type: 'menu',
			menu_item_id: 'menu-id',
			quantity: 3,
			options: [],
		} );
		expect( pizza.configuration.quantity ).toBe( 2 );
		expect( pizza ).not.toHaveProperty( 'line_total_minor' );
	} );

	test( 'formatea importes solo para presentación', () => {
		expect( formatMoney( 1250, 'USD', 'es-VE' ) ).toContain( '12,50' );
	} );

	test( 'genera una clave idempotente opaca', () => {
		const original = global.crypto;
		Object.defineProperty( global, 'crypto', {
			configurable: true,
			value: { randomUUID: () => '11111111-1111-4111-8111-111111111111' },
		} );
		expect( idempotencyKey() ).toBe(
			'11111111-1111-4111-8111-111111111111'
		);
		Object.defineProperty( global, 'crypto', {
			configurable: true,
			value: original,
		} );
	} );

	test( 'usa mensajes REST como texto y conserva fallback', () => {
		expect( responseMessage( { message: '  Conflicto. ' }, 'Error' ) ).toBe(
			'Conflicto.'
		);
		expect( responseMessage( {}, 'Error seguro' ) ).toBe( 'Error seguro' );
	} );

	test( 'solo recupera carrito cuando existe identidad observable', () => {
		expect( shouldLoadCart( 'cart', true ) ).toBe( true );
		expect( shouldLoadCart( 'checkout', true ) ).toBe( true );
		expect( shouldLoadCart( 'cart', false ) ).toBe( false );
		expect( shouldLoadCart( 'order', true ) ).toBe( false );
	} );
} );
