# Theme `vicunav-bonasera`

Theme de bloques (Full Site Editing) independiente, sin theme padre: identidad visual
de Bonasera, chrome de restaurante y patterns editoriales. Solo presentación; no
consulta carrito, pedidos, pagos, reservas ni disponibilidad. Requiere WordPress 6.6+
y PHP 8.1+.

## Contenido del theme

| Ruta | Contenido |
| --- | --- |
| `theme.json` | Presets de color, tipografía y espaciado, gradiente de hero y estilos globales |
| `templates/` | `index`, `front-page`, `page`, `single`, `archive` |
| `parts/` | Header y footer (ver más abajo) |
| `patterns/` | Diez patterns `vicunav-bonasera/*` (categoría Bonasera) |
| `assets/css/` | `restaurant-chrome.css`, `restaurant-patterns.css`, `bonasera-motifs.css` |
| `assets/js/faq-accordion.js` | Acordeón de FAQ, encolado solo si la página usa el pattern |
| `assets/fonts/` | Big Shoulders Display y Jost autoalojadas (SIL OFL 1.1, licencias incluidas) |
| `functions.php` | Categoría de patterns, encolado condicional y preservación del `post_type` de Query Loop para `vicu_faq` y `vicu_testimonial` |
| `tests/` | Validadores de theme y patterns (`bash theme/tests/run.sh`) |

## Tokens

Los presets públicos `vicunav-*` (colores semánticos y neutros, ocho tamaños de
fuente, dos familias, diez pasos de espaciado) son los que consumen los bloques del
plugin; `bonasera-ink-soft` y `bonasera-dusty-rose` son presets propios de la marca.
Valores, contrastes y origen de cada token: [`../theme/docs/tokens.md`](../theme/docs/tokens.md).

## Chrome de restaurante

El diseño usa un chrome amplio para la portada y otro compacto para las vistas
internas, traducidos a cuatro template parts editables:

| Slug | Uso |
| --- | --- |
| `header-restaurant-home` | Portada, superficie clara y CTA de ordenar |
| `header-restaurant-inner` | Menú, pizzas, carrito, checkout, reservas y cuenta |
| `footer-restaurant-full` | Portada y páginas editoriales extensas |
| `footer-restaurant-minimal` | Flujos transaccionales y vistas internas |

Los parts usan Site Logo, Site Title, Navigation, Buttons, Columns, Heading y
Paragraph; el footer completo añade Cover y Social Links. Los enlaces distribuidos son
anclas editables: el theme no fija rutas finales ni simula favoritos, cuenta, carrito
o WhatsApp. Los accesos de carrito y cuenta se añaden insertando el bloque
`vicunav/restaurante-header-actions` del plugin desde el Editor del sitio. Además existen los parts genéricos `header-default` y `footer-minimal`.

**Responsive y accesibilidad** (`restaurant-chrome.css`, limitado a las clases
`vicunav-restaurant-header` y `vicunav-restaurant-footer`, cargado con
`wp_enqueue_block_style()` sobre `core/navigation` en frontend y Editor del sitio): el
overlay de Navigation se mantiene hasta 1024 px y la navegación horizontal aparece
desde 1024 px; el CTA se oculta por debajo de 768 px y el logo puede ocultarse por
debajo de 480 px; altura mínima del header de 82 px en portada y 64 px en interior; el
footer completo pasa de dos columnas a una antes de 768 px y el mínimo envuelve sus
enlaces sin overflow. Enlaces y botones táctiles de al menos 44 px, foco de doble
anillo tinta-crema y `prefers-reduced-motion`. El foco y la gestión del overlay son los
del bloque Navigation.

## Patterns

Todos son agnósticos del vertical, usan presets `vicunav-*` y no incluyen HTML opaco,
lógica de negocio, datos personales ni recursos remotos.

| Superficie | Pattern | Nota |
| --- | --- | --- |
| Hero | `hero-centered` o `hero-split-image` | Cover sin URL inicial; un único H1 editable |
| Banner interior | `page-hero-banner` | Cover compacto de 210 px |
| Historia | `editorial-story` | Group, Columns, Heading H2, Paragraph, Buttons y Cover |
| Categorías | `linked-cards-grid` | Cuatro destinos editoriales; 1 columna en móvil, 2 desde 480 px, 4 desde 1024 px |
| Ubicación | `editorial-location` | Media, dirección, horario y notas como texto independiente |
| FAQ | `faq-accordion` | Contenido `vicu_faq` |
| Testimonios | `testimonials-grid` | Contenido `vicu_testimonial` |
| Contacto | `contact-info` | Ajustes compartidos con fallback |
| CTA | `cta-simple` | Copy y destino editables |

`restaurant-patterns.css` corrige overflow, wrapping, foco, targets táctiles y
movimiento reducido en frontend y editor. El mapa de zonas de entrega no es editorial:
consulta tarifas del dominio y lo cubre el bloque `restaurante-delivery-coverage` del
plugin. Video, fotografías y copy Bonasera solo entran con procedencia y licencia
verificables (ver [`contenido-y-media.md`](contenido-y-media.md)).

## Cómo se compone una página

1. El template (`front-page` o `page`) fija `header-restaurant-home` o
   `header-restaurant-inner`, un `main` con `post-content` y un footer.
2. Las páginas editoriales combinan patterns con un único H1 (hero o banner) y H2
   debajo; el contenido viene de `content/bonasera.json`.
3. Las páginas transaccionales (`/menu/`, `/pizzas/`, `/carrito/`, `/checkout/`,
   `/pedido/`, `/reservas/`, `/mis-pizzas/`) insertan el bloque dinámico del plugin
   correspondiente (ver [`plugin.md`](plugin.md)) bajo el heading editorial de la
   página; el bloque nunca aporta un H1.
4. Los destinos de navegación cruzada (`menuUrl`, `checkoutUrl`, `cartUrl`,
   `accountUrl`) se definen en la composición, no en el plugin.

## Validación

`bash theme/tests/run.sh` valida `theme.json` (`jq`), el contrato del theme, los
patterns y la sintaxis PHP; `tests/validate-wordpress-*.php` comprueban el theme y los
patterns dentro de WordPress.
