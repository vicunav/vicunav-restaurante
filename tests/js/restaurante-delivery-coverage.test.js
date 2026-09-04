import {
	formatEta,
	formatFee,
	matchDeliveryZone,
	normalizeZoneText,
} from '../../src/blocks/restaurante-delivery-coverage/model';

const zones = [
	{
		public_id: '11111111-1111-4111-8111-111111111111',
		name: 'Maracaibo (Casco Central)',
		fee_minor: 150000,
		eta_min_minutes: 20,
		eta_max_minutes: 35,
		display_order: 1,
	},
	{
		public_id: '22222222-2222-4222-8222-222222222222',
		name: 'La Lago / Bella Vista',
		fee_minor: 0,
		eta_min_minutes: 25,
		eta_max_minutes: 25,
		display_order: 2,
	},
];

describe( 'normalizeZoneText', () => {
	test( 'ignora mayúsculas, acentos y espacios sobrantes', () => {
		expect( normalizeZoneText( '  La Láfeña  ' ) ).toBe( 'la lafena' );
		expect( normalizeZoneText() ).toBe( '' );
	} );
} );

describe( 'matchDeliveryZone', () => {
	test( 'encuentra una zona cuando el texto es un sector parcial', () => {
		expect( matchDeliveryZone( 'la lago', zones ) ).toBe( zones[ 1 ] );
	} );

	test( 'encuentra una zona cuando el texto es una dirección larga que la contiene', () => {
		expect(
			matchDeliveryZone(
				'Av. 5 de Julio, Casco Central, Maracaibo',
				zones
			)
		).toBe( zones[ 0 ] );
	} );

	test( 'devuelve null sin coincidencias, texto vacío o catálogo ausente', () => {
		expect( matchDeliveryZone( 'Cabimas', zones ) ).toBeNull();
		expect( matchDeliveryZone( '   ', zones ) ).toBeNull();
		expect( matchDeliveryZone( 'La Lago', undefined ) ).toBeNull();
	} );

	test( 'ante varias coincidencias, prioriza el menor display_order', () => {
		const overlapping = [
			{ ...zones[ 0 ], display_order: 5 },
			{
				...zones[ 0 ],
				name: 'Maracaibo (Casco Central) Norte',
				display_order: 1,
			},
		];
		expect( matchDeliveryZone( 'Casco Central', overlapping ) ).toBe(
			overlapping[ 1 ]
		);
	} );
} );

describe( 'formatEta', () => {
	test( 'muestra un rango o un valor único cuando ambos extremos coinciden', () => {
		expect( formatEta( zones[ 0 ] ) ).toBe( '20-35 min' );
		expect( formatEta( zones[ 1 ] ) ).toBe( '25 min' );
		expect( formatEta( null ) ).toBe( '' );
	} );
} );

describe( 'formatFee', () => {
	test( 'formatea la tarifa en la moneda vigente', () => {
		const expected = new Intl.NumberFormat( 'es-VE', {
			style: 'currency',
			currency: 'USD',
		} ).format( 1500 );
		expect( formatFee( zones[ 0 ], 'USD', 'es-VE' ) ).toBe( expected );
	} );

	test( 'devuelve null cuando la entrega es gratuita, para que el llamador elija el copy', () => {
		expect( formatFee( zones[ 1 ], 'USD' ) ).toBeNull();
		expect( formatFee( null, 'USD' ) ).toBeNull();
	} );
} );
