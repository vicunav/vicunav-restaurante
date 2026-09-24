import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';

const themeRoot = path.resolve(import.meta.dirname, '..');
const readJson = (relativePath) =>
	JSON.parse(readFileSync(path.join(themeRoot, relativePath), 'utf8'));
const assert = (condition, message) => {
	if (!condition) {
		throw new Error(message);
	}
};

const theme = readJson('theme.json');
const styleCss = readFileSync(path.join(themeRoot, 'style.css'), 'utf8');

assert(!/^Template:/m.test(styleCss), 'El theme no debe declarar un theme padre.');
assert(/Text Domain:\s*vicunav-bonasera/.test(styleCss), 'style.css debe declarar el text domain vicunav-bonasera.');
assert(theme.$schema === 'https://schemas.wp.org/wp/6.6/theme.json', 'Schema base incorrecto.');
assert(theme.version === 3, 'theme.json debe usar la versión 3.');

const paletteSlugs = theme.settings.color.palette.map((p) => p.slug);
for (const required of [
	'vicunav-primary', 'vicunav-secondary', 'vicunav-accent', 'vicunav-positive',
	'vicunav-warning', 'vicunav-danger', 'vicunav-info',
	'vicunav-neutral-100', 'vicunav-neutral-900',
]) {
	assert(paletteSlugs.includes(required), `Falta el slug de paleta ${required}.`);
}

const expectedFonts = new Map([
	['vicunav-heading', ['assets/fonts/big-shoulders-display-latin.woff2', '203dd8ba4ae61b19cd2e00c66708f0d0f6d8484cdfdb1d7e8be37260d36a99b1']],
	['vicunav-body', ['assets/fonts/jost-latin.woff2', '235d8f8964bfdf105fc0c3e4c77b5e70f31bee1dad611d59318b5f2a5cb64d90']],
]);
for (const family of theme.settings.typography.fontFamilies) {
	const expected = expectedFonts.get(family.slug);
	assert(expected, `Familia inesperada: ${family.slug}.`);
	const relativePath = family.fontFace[0].src[0].replace('file:./', '');
	assert(relativePath === expected[0], `Ruta de fuente inesperada: ${family.slug}.`);
	const absolutePath = path.join(themeRoot, relativePath);
	assert(existsSync(absolutePath), `Falta la fuente local ${relativePath}.`);
	const digest = createHash('sha256').update(readFileSync(absolutePath)).digest('hex');
	assert(digest === expected[1], `Checksum inesperado: ${relativePath}.`);
}
assert(expectedFonts.size === theme.settings.typography.fontFamilies.length, 'Faltan familias tipográficas.');

// Sin adyacencia dígito-letra en ningún slug nuevo (WordPress renombra ese tipo de slug al compilar el CSS.
const allSlugs = [
	...paletteSlugs,
	...theme.settings.spacing.spacingSizes.map((s) => s.slug),
	...theme.settings.typography.fontSizes.map((s) => s.slug),
	...theme.settings.shadow.presets.map((s) => s.slug),
];
for (const slug of allSlugs) {
	assert(!/[a-z][0-9]|[0-9][a-z]/i.test(slug), `El slug ${slug} tiene una frontera letra-dígito sin guion.`);
}

function luminance(hex) {
	const channels = hex.match(/[a-f\d]{2}/gi).map((channel) => Number.parseInt(channel, 16) / 255);
	const linear = channels.map((channel) =>
		channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
	);
	return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2];
}
function contrast(foreground, background) {
	const brighter = Math.max(luminance(foreground), luminance(background));
	const darker = Math.min(luminance(foreground), luminance(background));
	return (brighter + 0.05) / (darker + 0.05);
}

const cream = '#FAEBD7';
const contrastPairs = [
	['#0D0D0D', cream, 'tinta sobre crema'],
	['#4A3B33', cream, 'marrón sobre crema'],
	['#4D673B', cream, 'positivo sobre crema'],
	['#9F4527', cream, 'advertencia sobre crema'],
	['#A8432B', cream, 'peligro sobre crema'],
	['#557259', cream, 'información sobre crema'],
	['#0D0D0D', '#9DAAAA', 'tinta sobre salvia'],
];
for (const [foreground, background, label] of contrastPairs) {
	assert(contrast(foreground, background) >= 4.5, `Contraste AA insuficiente: ${label} (${contrast(foreground, background).toFixed(2)}).`);
}

console.log('Contrato visual del theme Bonasera validado.');
