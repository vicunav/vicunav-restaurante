import {
	InspectorControls,
	URLInput,
	useBlockProps,
} from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { PanelBody, PanelRow } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';
import './style.scss';
import './editor.scss';

/**
 * Expone los destinos editables del carrito vacío y del checkout sin
 * duplicar estado de negocio en el editor; el SSR privado sigue resolviendo
 * el carrito únicamente por REST.
 *
 * @param {Object}   props               Props del bloque.
 * @param {Object}   props.attributes    Atributos actuales.
 * @param {Function} props.setAttributes Actualiza atributos.
 */
function Edit( { attributes, setAttributes } ) {
	const { menuUrl, checkoutUrl } = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Destinos del carrito',
						'vicunav-restaurante'
					) }
				>
					<PanelRow>
						<div style={ { width: '100%' } }>
							<label htmlFor="vicu-restaurante-cart-menu-url">
								{ __(
									'Página del menú (carrito vacío)',
									'vicunav-restaurante'
								) }
							</label>
							<URLInput
								id="vicu-restaurante-cart-menu-url"
								value={ menuUrl }
								onChange={ ( value ) =>
									setAttributes( { menuUrl: value || '' } )
								}
							/>
						</div>
					</PanelRow>
					<PanelRow>
						<div style={ { width: '100%' } }>
							<label htmlFor="vicu-restaurante-cart-checkout-url">
								{ __(
									'Página de checkout',
									'vicunav-restaurante'
								) }
							</label>
							<URLInput
								id="vicu-restaurante-cart-checkout-url"
								value={ checkoutUrl }
								onChange={ ( value ) =>
									setAttributes( {
										checkoutUrl: value || '',
									} )
								}
							/>
						</div>
					</PanelRow>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
					skipBlockSupportAttributes
				/>
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
