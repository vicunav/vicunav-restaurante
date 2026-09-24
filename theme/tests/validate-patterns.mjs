import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoDir = resolve(fileURLToPath(new URL('..', import.meta.url)));

const patterns = new Map([
	['hero-centered.php', 'vicunav-pattern-hero'],
	['hero-split-image.php', 'vicunav-pattern-split-hero'],
	['page-hero-banner.php', 'vicunav-pattern-page-hero'],
	['linked-cards-grid.php', 'vicunav-linked-cards-grid'],
	['editorial-story.php', 'vicunav-editorial-story'],
	['editorial-location.php', 'vicunav-editorial-location'],
	['testimonials-grid.php', 'vicunav-testimonials-grid'],
	['faq-accordion.php', 'vicunav-faq-accordion'],
	['contact-info.php', 'vicunav-contact-info'],
	['cta-simple.php', 'vicunav-pattern-cta'],
]);

const fail = (message) => {
	throw new Error(message);
};

for (const [file, scopeClass] of patterns) {
	const source = readFileSync(resolve(repoDir, 'patterns', file), 'utf8');

	if (!source.includes(`className":"${scopeClass}`) && !source.includes(scopeClass)) {
		fail(`${file} no declara la clase scoped ${scopeClass}.`);
	}

	if (source.includes('<!-- wp:html')) {
		fail(`${file} contiene core/html.`);
	}

	if (/https?:\/\//u.test(source)) {
		fail(`${file} contiene un recurso o enlace remoto.`);
	}

	if (!/Slug: vicunav-bonasera\/[a-z0-9-]+/u.test(source)) {
		fail(`${file} no declara un slug público válido.`);
	}
}

const cards = readFileSync(resolve(repoDir, 'patterns/linked-cards-grid.php'), 'utf8');
const cardEntries = cards.match(/array\( '[^']+', 'destino-[^']+' \)/gu) ?? [];

if (cardEntries.length !== 4) {
	fail('linked-cards-grid debe publicar cuatro destinos editoriales iniciales.');
}

const pageHero = readFileSync(resolve(repoDir, 'patterns/page-hero-banner.php'), 'utf8');
if (
	!pageHero.includes('"dimRatio":70') ||
	!pageHero.includes('has-background-dim-70') ||
	pageHero.includes('has-background-dim-65')
) {
	fail('page-hero-banner debe conservar el dimRatio que valida core/cover en el Site Editor.');
}

const css = readFileSync(resolve(repoDir, 'assets/css/restaurant-patterns.css'), 'utf8');
for (const fragment of [
	'min-height: 44px',
	'@media (min-width: 30rem)',
	'@media (min-width: 48rem)',
	'@media (min-width: 64rem)',
	'@media (max-width: 29.99rem)',
	'@media (prefers-reduced-motion: reduce)',
	'.editor-styles-wrapper .vicunav-pattern-hero',
]) {
	if (!css.includes(fragment)) {
		fail(`restaurant-patterns.css no contiene el contrato: ${fragment}.`);
	}
}

const functionsSource = readFileSync(resolve(repoDir, 'functions.php'), 'utf8');
if (
	!functionsSource.includes("wp_enqueue_block_style(") ||
	!functionsSource.includes("assets/css/restaurant-patterns.css")
) {
	fail('functions.php no registra la hoja scoped de patterns mediante la API de bloques.');
}

for (const part of [
	'header-restaurant-home.html',
	'header-restaurant-inner.html',
	'footer-restaurant-full.html',
	'footer-restaurant-minimal.html',
]) {
	const source = readFileSync(resolve(repoDir, 'parts', part), 'utf8');

	if (/"url":"\//u.test(source) || /href="\//u.test(source)) {
		fail(`${part} contiene una ruta final.`);
	}
}

console.log('Contrato estructural de chrome y patterns de restaurante validado.');
