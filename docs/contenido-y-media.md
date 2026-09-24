# Contenido y media Bonasera

## Baseline

L## Fuente y naturaleza

La fuente auditada es el prototipo de diseño de referencia en el commit
`1e1f62787e088c0ca9701500e764802499d1b253`. `content/bonasera.json` conserva el copy
y los datos demostrativos necesarios para componer WordPress. No es un contrato de
runtime: los datos se siembran a través de las superficies públicas del plugin (ver
[`plugin.md`](plugin.md)).

Bonasera, su historia, sus testimonios y sus datos operativos son ficticios. El
teléfono, correo y dirección exacta del prototipo se sustituyeron por datos no
enrutables. Esta clasificación evita presentar el sitio como un comercio real o
publicar datos que pudieran pertenecer a terceros.

 editoriales

| Ruta prevista | H1 único | Contenido principal | Propietario en WordPress |
| --- | --- | --- | --- |
| `/` | Bonasera | Hero, destacados, categorías, historia, ubicación, testimonios, FAQ y contacto | Composición FSE del theme |
| `/menu/` | Nuestro menú | Filtros y catálogo estructurado | Bloque dinámico del plugin |
| `/pizzas/` | Crea tu pizza | Catálogo y constructor | Bloques dinámicos del plugin |
| `/carrito/` | Tu pedido | Líneas y totales autoritativos | Bloque dinámico del plugin |
| `/checkout/` | Confirmar pedido | Datos, entrega o retiro y pago manual | Bloque dinámico del plugin |
| `/pedido/` | Estado del pedido | Estado real de pedido y pago | Bloque dinámico del plugin |
| `/reservas/` | Reservar mesa | Disponibilidad y formulario | Bloque dinámico del plugin |
| `/mis-pizzas/` | Mis pizzas | Configuraciones de la cuenta | Bloque dinámico del plugin |

El JSON contiene 37 platos en ocho categorías, 20 ingredientes, opciones del
constructor, ocho FAQ y tres testimonios ficticios. También conserva horarios,
capacidad, zonas, descuentos y propinas como datos de siembra, nunca como lógica ni
como autoridad de precios.

## Ajustes al prototipo

- El checkout menciona únicamente el proveedor de pago manual real del plugin.
- No se importaron los cuatro métodos de pago teatrales ni sus pantallas simuladas.
- La historia conserva su tono, pero ninguna foto identifica a personas reales como
  miembros de la familia ficticia.
- Los testimonios conservan copy demostrativo sin retratos y declaran que son
  ficticios.
- Los defectos responsive, de accesibilidad y los H1 duplicados no forman parte del
  contenido canónico.

## Media versionada

`config/media.json` (schema 2) es el inventario verificable. Cada imagen WebP es local
(`assets/images/`) y registra uso previsto, texto alternativo, autor, fuente, licencia,
dimensiones, peso y SHA-256. Las fotos de Unsplash se usan bajo la
[Unsplash License](https://unsplash.com/license) y las de Pexels bajo la
[Pexels License](https://www.pexels.com/legal-pages/license/), verificadas el
2026-08-26. La atribución no es obligatoria en esas licencias, pero se conserva para
trazabilidad y crédito. Los dos mapas usan datos de
[OpenStreetMap](https://www.openstreetmap.org/copyright) (ODbL) con la atribución
visible en la propia imagen.

- Diez imágenes recuperan la misma fotografía o mapa de la fuente auditada.
- `dolci.webp` es una sustitución conservadora (foto Pexels de tiramisú) porque el
  original muestra una marca ajena visible.
- Se excluyeron los tres retratos de testimonios, la foto de dos trabajadores
  presentada como familia y el original de postre con marca. Sus URL sobreviven solo
  como evidencia en el inventario; no hay hotlinks en el contenido que consume WordPress.
- `assets/hero-bg.mp4` (video del hero, 15,9 MB, SHA-256 en el inventario) figura en la
  sección `videos` como `provided-by-owner`: archivo propio, autorizado por el
  propietario del sitio. El video de terceros del prototipo no debe recuperarse.

El estado por activo, incluidas las omisiones de seguridad, está en
[`visual/assets-bonasera.md`](visual/assets-bonasera.md).

## Reglas para nueva media

Todo activo nuevo exige procedencia, licencia, dimensiones, peso, SHA-256 y texto
alternativo en `config/media.json` (presupuesto: imágenes locales de hasta 200.000
bytes, ver `config/qa.json`). No se crean sustitutos que aparenten ser mapas u otras
fuentes reales. `tests/validate-content.mjs` valida contenido y media.
