import {
	bookingPayload,
	idempotencyKey,
	responseAlternatives,
	responseMessage,
} from '../../src/blocks/restaurante-reservations/model';

describe( 'modelo cliente de reservas', () => {
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

	test( 'construye solo los campos aceptados por creación', () => {
		const form = new FormData();
		form.set( 'guest_name', 'Persona' );
		form.set( 'phone', '+58 414 0000000' );
		form.set( 'email', 'persona@example.com' );
		form.set( 'zone_preference', 'Terraza' );
		form.set( 'notes', 'Mesa accesible' );
		form.set( 'revision', '99' );
		const payload = bookingPayload( form, {
			date: '2027-01-20',
			time: '18:30',
			partySize: 2,
		} );

		expect( payload ).toEqual( {
			guest_name: 'Persona',
			phone: '+58 414 0000000',
			email: 'persona@example.com',
			zone_preference: 'Terraza',
			notes: 'Mesa accesible',
			date: '2027-01-20',
			time: '18:30',
			party_size: 2,
		} );
		expect( payload ).not.toHaveProperty( 'revision' );
		expect( payload ).not.toHaveProperty( 'capacity' );
	} );

	test( 'acepta solo alternativas reservables de la forma REST estable', () => {
		expect(
			responseAlternatives( {
				data: {
					alternatives: [
						{ time: '18:00', status: 'available' },
						{ time: '18:30', status: 'limited' },
						{ time: '19:00', status: 'unavailable' },
						{ status: 'available' },
					],
				},
			} )
		).toEqual( [
			{ time: '18:00', status: 'available' },
			{ time: '18:30', status: 'limited' },
		] );
	} );

	test( 'presenta mensajes como texto y conserva fallback', () => {
		expect( responseMessage( { message: '  Conflicto. ' }, 'Error' ) ).toBe(
			'Conflicto.'
		);
		expect( responseMessage( {}, 'Error seguro' ) ).toBe( 'Error seguro' );
	} );
} );
