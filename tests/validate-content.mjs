import { createHash } from 'node:crypto';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';

const repoRoot = path.resolve(import.meta.dirname, '..');
const readJson = (relativePath) => JSON.parse(readFileSync(path.join(repoRoot, relativePath), 'utf8'));
const assert = (condition, message) => {
	if (!condition) {
		throw new Error(message);
	}
};

const contentPath = path.join(repoRoot, 'content', 'bonasera.json');
const contentRaw = readFileSync(contentPath, 'utf8');
const content = JSON.parse(contentRaw);
const media = readJson('config/media.json');

assert(content.schema_version === 1, 'El contenido no usa el schema 1.');
assert(
	content.source.commit === '1e1f62787e088c0ca9701500e764802499d1b253',
	'El contenido perdió la revisión auditada.'
);
assert(content.pages.length === 9, 'El inventario debe declarar nueve rutas reales (inicio + los ocho de issue #25).');
assert(new Set(content.pages.map(({ path: pagePath }) => pagePath)).size === 9, 'Hay rutas duplicadas.');
assert(content.pages.every(({ h1 }) => typeof h1 === 'string' && h1.trim()), 'Cada ruta necesita un H1.');
assert(content.menu.categories.length === 8, 'El catálogo debe conservar ocho categorías.');
assert(content.menu.items.length === 37, 'El catálogo debe conservar 37 platos.');
assert(content.faqs.length === 8, 'Deben existir ocho FAQ.');
assert(content.testimonials.items.length === 3, 'Deben existir tres testimonios ficticios.');
assert(content.payment.provider === 'manual', 'El demo debe usar el proveedor manual.');
assert(content.brand.email.endsWith('.invalid'), 'El correo demostrativo debe ser no enrutable.');
assert(!contentRaw.includes('\u2014'), 'El contenido contiene una raya tipográfica larga.');
assert(
	!/(Pago Móvil|Zelle|transferencia bancaria|efectivo)/i.test(contentRaw),
	'El contenido conserva métodos de pago teatrales.'
);
assert(!/https?:\/\//i.test(contentRaw), 'El contenido consumible contiene un hotlink.');

assert(media.schema_version === 2, 'El inventario de media no usa el schema 2.');
assert(media.license_checked_at === '2026-08-26', 'La fecha de revisión de licencias cambió.');
assert(media.assets.length === 11, 'El inventario debe contener once imágenes locales.');
assert(
	Object.keys(media.licenses).sort().join(',') === 'openstreetmap,pexels,unsplash',
	'Licencias inesperadas.'
);
assert(
	media.licenses.unsplash.url === 'https://unsplash.com/license',
	'La referencia oficial de la licencia Unsplash cambió.'
);
assert(
	media.licenses.pexels.url === 'https://www.pexels.com/legal-pages/license/',
	'La referencia oficial de la licencia Pexels cambió.'
);
assert(
	media.licenses.openstreetmap.url === 'https://www.openstreetmap.org/copyright',
	'La referencia oficial de la licencia OpenStreetMap cambió.'
);
assert(
	media.visual_contract.source_commit === '1e1f62787e088c0ca9701500e764802499d1b253',
	'El contrato visual perdió la revisión auditada.'
);
assert(
	media.visual_contract.final_gate === 'approved-with-differences',
	'El inventario no refleja el cierre aprobado del gate final.'
);
assert(
	media.visual_contract.human_approval_reference?.includes('DEMO-REST-02E issue #19'),
	'Falta la referencia de aprobación humana del gate final.'
);

const expectedSourceRefs = new Map([
	['antipasti', 'src/data/media.js:CATEGORY_IMG.antipasti'],
	['insalate', 'src/data/media.js:CATEGORY_IMG.insalate'],
	['pizze', 'src/data/media.js:CATEGORY_IMG.pizze'],
	['pasta', 'src/data/media.js:CATEGORY_IMG.pasta'],
	['secondi', 'src/data/media.js:CATEGORY_IMG.secondi'],
	['contorni', 'src/data/media.js:CATEGORY_IMG.contorni'],
	['bevande', 'src/data/media.js:CATEGORY_IMG.bevande'],
	['reservas', 'src/data/media.js:RESERVA_IMG'],
	['map-zulia', 'src/data/media.js:MAP_ZULIA_IMG'],
	['map-maracaibo', 'src/data/media.js:MAP_MARACAIBO_IMG'],
]);

const expectedPaths = new Set();
for (const asset of media.assets) {
	assert(!asset.path.startsWith('/') && !asset.path.includes('://'), `Ruta no local: ${asset.id}`);
	assert(asset.alt?.trim(), `Falta texto alternativo: ${asset.id}`);
	assert(asset.creator?.trim(), `Falta autor: ${asset.id}`);
	assert(asset.source_url?.startsWith('https://'), `Falta fuente HTTPS: ${asset.id}`);
	assert(media.licenses[asset.license], `Licencia desconocida: ${asset.id}`);
	if (asset.id === 'dolci') {
		assert(asset.visual_status === 'approved-substitute', 'El sustituto dolci perdió su aprobación.');
		assert(
			asset.source_ref === 'sustituto-seguro-de:src/data/media.js:CATEGORY_IMG.dolci',
			'La referencia del sustituto dolci cambió.'
		);
		assert(asset.approval?.authority === 'usuario', 'El sustituto dolci perdió su autoridad.');
		assert(asset.blocks_final_parity === false, 'El sustituto dolci sigue bloqueando paridad.');
	} else {
		assert(asset.visual_status === 'exact-source-recovered', `Original no recuperado: ${asset.id}`);
		assert(asset.source_ref === expectedSourceRefs.get(asset.id), `Referencia fuente inesperada: ${asset.id}`);
		assert(asset.blocks_final_parity === false, `Original recuperado bloquea paridad: ${asset.id}`);
	}
	assert(!expectedPaths.has(asset.path), `Ruta duplicada: ${asset.path}`);
	expectedPaths.add(asset.path);

	const absolutePath = path.join(repoRoot, asset.path);
	const bytes = readFileSync(absolutePath);
	const dimensions = readWebpDimensions(bytes);
	const digest = createHash('sha256').update(bytes).digest('hex');

	assert(statSync(absolutePath).size === asset.bytes, `Peso inesperado: ${asset.id}`);
	assert(asset.bytes <= 200_000, `La imagen supera 200 KB: ${asset.id}`);
	assert(dimensions.width === asset.width, `Ancho inesperado: ${asset.id}`);
	assert(dimensions.height === asset.height, `Alto inesperado: ${asset.id}`);
	assert(digest === asset.sha256, `Checksum inesperado: ${asset.id}`);
}

const actualPaths = readdirSync(path.join(repoRoot, 'assets', 'images'))
	.filter((filename) => filename.endsWith('.webp'))
	.map((filename) => `assets/images/${filename}`);
assert(actualPaths.length === expectedPaths.size, 'Hay imágenes sin inventariar.');
assert(actualPaths.every((assetPath) => expectedPaths.has(assetPath)), 'Hay imágenes ajenas al inventario.');
assert(expectedSourceRefs.size === 10, 'Deben existir diez originales recuperados.');

assert(media.missing.length === 0, 'Ya no debe quedar ningún activo sin entregar.');

assert(media.videos.length === 1, 'Debe registrarse un video local.');
for (const video of media.videos) {
	assert(!video.path.startsWith('/') && !video.path.includes('://'), `Ruta no local: ${video.id}`);
	assert(video.alt?.trim(), `Falta texto alternativo: ${video.id}`);
	assert(video.mime === 'video/mp4', `Tipo MIME inesperado: ${video.id}`);
	assert(video.visual_status === 'provided-by-owner', `Estado inesperado: ${video.id}`);
	assert(video.blocks_final_parity === false, `${video.id} sigue bloqueando paridad.`);
	assert(video.approval?.authority === 'usuario', `Falta aprobación del propietario: ${video.id}`);

	const absolutePath = path.join(repoRoot, video.path);
	const bytes = readFileSync(absolutePath);
	assert(statSync(absolutePath).size === video.bytes, `Peso inesperado: ${video.id}`);
	assert(
		createHash('sha256').update(bytes).digest('hex') === video.sha256,
		`Checksum inesperado: ${video.id}`
	);
}

assert(media.excluded.length === 5, 'El inventario de omisiones no está descompuesto por asset.');
assert(
	media.excluded.map(({ id }) => id).sort().join(',') ===
		'dolci-original,historia-original,testimonial-avatar-t1,testimonial-avatar-t2,testimonial-avatar-t3',
	'El conjunto de originales retenidos cambió.'
);
assert(
	media.excluded.every(
		({ visual_status: visualStatus, blocks_final_parity: blocksFinalParity, approval, license }) =>
			['approved-omission', 'approved-substitute'].includes(visualStatus) &&
			blocksFinalParity === false &&
			approval?.authority === 'usuario' &&
			media.licenses[license]
	),
	'Una omisión o sustitución perdió su aprobación o licencia.'
);
assert(
	media.excluded.filter(({ source }) => source.includes('AVATAR_MAP')).length === 3,
	'Falta excluir cada retrato de forma atómica.'
);

console.log('Contenido y media Bonasera validados.');

function readWebpDimensions(buffer) {
	assert(buffer.toString('ascii', 0, 4) === 'RIFF', 'La imagen no es RIFF.');
	assert(buffer.toString('ascii', 8, 12) === 'WEBP', 'La imagen no es WebP.');

	let offset = 12;
	while (offset + 8 <= buffer.length) {
		const chunk = buffer.toString('ascii', offset, offset + 4);
		const size = buffer.readUInt32LE(offset + 4);
		const data = offset + 8;

		if (chunk === 'VP8X') {
			return {
				width: 1 + buffer.readUIntLE(data + 4, 3),
				height: 1 + buffer.readUIntLE(data + 7, 3),
			};
		}
		if (chunk === 'VP8 ') {
			assert(buffer.readUIntLE(data + 3, 3) === 0x2a019d, 'Header VP8 inválido.');
			return {
				width: buffer.readUInt16LE(data + 6) & 0x3fff,
				height: buffer.readUInt16LE(data + 8) & 0x3fff,
			};
		}
		if (chunk === 'VP8L') {
			assert(buffer[data] === 0x2f, 'Header VP8L inválido.');
			const bits = buffer.readUInt32LE(data + 1);
			return {
				width: 1 + (bits & 0x3fff),
				height: 1 + ((bits >>> 14) & 0x3fff),
			};
		}

		offset = data + size + (size % 2);
	}

	throw new Error('No se encontraron dimensiones WebP.');
}
