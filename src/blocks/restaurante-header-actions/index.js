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
import './editor.scss';

/**
 * Expone los destinos editables del carrito y la cuenta sin duplicar estado
 * de negocio en el editor; el SSR privado sigue resolviendo carrito y sesión
 * únicamente por REST.
 *
 * @param {Object}   props               Props del bloque.
 * @param {Object}   props.attributes    Atributos actuales.
 * @param {Function} props.setAttributes Actualiza atributos.
 */
function Edit( { attributes, setAttributes } ) {
	const { cartUrl, accountUrl } = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Destinos de las acciones',
						'vicunav-restaurante'
					) }
				>
					<PanelRow>
						<div style={ { width: '100%' } }>
							<label htmlFor="vicu-restaurante-header-actions-cart-url">
								{ __(
									'Página del carrito',
									'vicunav-restaurante'
								) }
							</label>
							<URLInput
								id="vicu-restaurante-header-actions-cart-url"
								value={ cartUrl }
								onChange={ ( value ) =>
									setAttributes( { cartUrl: value || '' } )
								}
							/>
						</div>
					</PanelRow>
					<PanelRow>
						<div style={ { width: '100%' } }>
							<label htmlFor="vicu-restaurante-header-actions-account-url">
								{ __(
									'Página de cuenta o pizzas guardadas',
									'vicunav-restaurante'
								) }
							</label>
							<URLInput
								id="vicu-restaurante-header-actions-account-url"
								value={ accountUrl }
								onChange={ ( value ) =>
									setAttributes( { accountUrl: value || '' } )
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

registerBlockType( metadata.name, {
	edit: Edit,
	save() {
		return null;
	},
} );
