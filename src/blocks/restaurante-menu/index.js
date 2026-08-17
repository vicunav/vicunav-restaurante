import { useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';
import './style.scss';
import './editor.scss';

/** Renderiza la vista previa dinámica dentro del editor. */
function Edit() {
	return (
		<div { ...useBlockProps() }>
			<ServerSideRender
				block={ metadata.name }
				skipBlockSupportAttributes
			/>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save() {
		return null;
	},
} );
