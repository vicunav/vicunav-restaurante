import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';
import './style.scss';
import './editor.scss';

/**
 * Renderiza la vista previa dinámica y expone el recorte por categoría (ej.
 * la vitrina de pizzas listas de la página de pizzas) sin duplicar el estado
 * de negocio en el editor.
 *
 * @param {Object}   props               Props del bloque.
 * @param {Object}   props.attributes    Atributos actuales.
 * @param {Function} props.setAttributes Actualiza atributos.
 */
function Edit( { attributes, setAttributes } ) {
	const { category, showControls } = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Filtro de categoría', 'vicunav-restaurante' ) }
				>
					<TextControl
						label={ __(
							'Categoría (slug)',
							'vicunav-restaurante'
						) }
						help={ __(
							'Deja vacío para mostrar todo el menú. Usa el slug visible en los chips de categoría del frontend (ej. "pizze").',
							'vicunav-restaurante'
						) }
						value={ category }
						onChange={ ( value ) =>
							setAttributes( { category: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Mostrar buscador y filtros',
							'vicunav-restaurante'
						) }
						checked={ showControls }
						onChange={ ( value ) =>
							setAttributes( { showControls: value } )
						}
					/>
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
