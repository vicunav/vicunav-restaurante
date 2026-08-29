import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoDir = resolve(fileURLToPath(new URL('..', import.meta.url)));
const fail = (message) => {
	throw new Error(message);
};

const publicBlocks = [
	'restaurante-menu',
	'restaurante-pizza-builder',
	'restaurante-cart',
	'restaurante-checkout',
	'restaurante-order-status',
	'restaurante-reservations',
	'restaurante-saved-pizzas',
];

for (const block of publicBlocks) {
	const metadata = JSON.parse(
		readFileSync(resolve(repoDir, 'src/blocks', block, 'block.json'), 'utf8')
	);

	if (metadata.apiVersion !== 3 || !metadata.render) {
		fail(`${block} debe conservar API 3 y render dinámico.`);
	}

	if (
		!metadata.supports?.color?.background ||
		!metadata.supports?.color?.text ||
		!metadata.supports?.spacing?.margin ||
		!metadata.supports?.spacing?.padding
	) {
		fail(`${block} debe conservar supports visuales editables mediante FSE.`);
	}
}

const styleFiles = [
	'_visual-contract.scss',
	'restaurante-menu/style.scss',
	'restaurante-pizza-builder/style.scss',
	'restaurante-commerce-assets/commerce.scss',
	'restaurante-reservations/style.scss',
	'restaurante-saved-pizzas/style.scss',
];
const styles = styleFiles
	.map((file) => readFileSync(resolve(repoDir, 'src/blocks', file), 'utf8'))
	.join('\n');

for (const contract of [
	'--wp--preset--color--vicunav-primary',
	'--wp--preset--color--vicunav-neutral-100',
	'--wp--preset--color--vicunav-neutral-900',
	'--wp--preset--spacing--vicunav-space-md',
	'--wp--preset--font-family--vicunav-body',
	'min-height: 44px',
	'@media (min-width: 48rem)',
	'@media (min-width: 64rem)',
	'@media (prefers-reduced-motion: reduce)',
]) {
	if (!styles.includes(contract)) {
		fail(`El contrato visual no contiene ${contract}.`);
	}
}

if (/bonasera/iu.test(styles)) {
	fail('El contrato visual del plugin no puede contener tokens o identidad Bonasera.');
}

for (const identityColor of ['#faebd7', '#0d0d0d', '#4a3b33', '#9daaaa']) {
	if (styles.toLowerCase().includes(identityColor)) {
		fail(`El contrato visual contiene el color de identidad ${identityColor}.`);
	}
}

console.log('Contrato visual neutral de los siete bloques validado.');
