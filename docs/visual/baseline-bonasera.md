# Baseline visual Bonasera

## Propósito y estado

Este documento congela la referencia aprobada y el contrato de evidencia para comparar
el sitio WordPress con el prototipo. **No declara paridad 1:1**: solo un checkpoint con
evidencia completa, inspección manual y cero diferencias no autorizadas puede cerrarla
(ver [`../estado.md`](../estado.md)).

Un gate estructural aprobado (rutas, estructura, accesibilidad básica, funciones y
ausencia de overflow) no compara píxeles, tipografía, jerarquía, ritmo ni composición
con la fuente, y nunca debe leerse como fidelidad 1:1.

## Contrato inmutable de fuente

| Campo | Valor |
| --- | --- |
| Prototipo | SPA de diseño de referencia, rama `main` |
| Commit | `1e1f62787e088c0ca9701500e764802499d1b253` |
| Instalación | `npm ci` |
| Validaciones | `npm run lint`, `npm test`, `npm run build` |
| Ejecución | `npm run dev -- --host 127.0.0.1 --port 4173` |

En la revisión de la línea base, lint, las 66 pruebas y el build del prototipo pasaron;
`npm run format` reportaba 56 archivos (hallazgo de la fuente, sin cambios sobre el
commit auditado).

La fuente es una SPA sin rutas reales. Sus siete pantallas son `inicio`, `menu`,
`pizzas`, `carrito`, `checkout`, `reserva` y `mispizzas`. Las acciones declaradas en el
manifiesto reproducen cada pantalla desde un contexto nuevo.

## Entorno comparable

- Chromium 151, escala 1 y esquema claro.
- Viewports (`config/qa.json`): 1440 x 1000, 1024 x 900, 768 x 1024, 390 x 844 y
  375 x 812.
- Locale de captura `es-VE`, zona horaria `America/Caracas` y movimiento reducido.
- Captura de viewport fijo, no página completa, para conservar dimensiones idénticas
  entre una SPA de alturas distintas y las páginas reales de WordPress. El checkpoint
  final añade recorridos por sección y estados interactivos.
- Fuentes: Big Shoulders Display (display) y Jost (cuerpo).
- La captura fuerza las condiciones comparables (locale, zona horaria) sin ocultar que
  la configuración persistida del sitio debe corregirse.
- La inspección de la línea base fue de solo lectura: no se crean pedidos, carritos,
  reservas, usuarios ni cambios de Global Styles.

## Superficies y equivalencia

| WordPress | Estado fuente | Comparación | Observación |
| --- | --- | --- | --- |
| `/` | `inicio` | Sí | Portada, header, hero, secciones y footer |
| `/menu/` | `menu` | Sí | Menú completo en estado inicial |
| `/pizzas/` | `pizzas` | Sí | Catálogo y constructor en estado inicial |
| `/carrito/` | `carrito` | Sí | Carrito vacío en ambos lados |
| `/checkout/` | `checkout` | Sí, con limitación | La fuente necesita un artículo local para alcanzar la pantalla; WordPress se conserva vacío para no escribir en la base de datos |
| `/reservas/` | `reserva` | Sí | Formulario inicial sin enviar datos |
| `/mis-pizzas/` | `mispizzas` | Sí | Estado público sin pizzas guardadas |
| `/pedido/` | No existe | No | Superficie real de seguimiento propia de WordPress |
| `/privacidad/` | No existe | No | Superficie editorial y legal propia de WordPress |

No se inventa una captura fuente para `/pedido/` o `/privacidad/`. Ambas rutas forman
parte del QA WordPress, pero no del contrato de paridad con la SPA.

## Inventario de estados

La matriz congelada cubre el estado inicial de cada superficie en los cinco
viewports. Los siguientes estados quedan indexados para la implementación y el gate
final:

| Superficie | Estados adicionales aplicables |
| --- | --- |
| Portada | FAQ expandida, dirección verificada, zona seleccionada, drawer de carrito |
| Menú | búsqueda, categoría, vegetariano, picante, favoritos, modal de personalización, vacío |
| Pizzas | mitad izquierda/derecha, topping seleccionado, agotado, máximo de toppings, cotización, guardado |
| Carrito | poblado, delivery, zona, propina, descuento válido, error, conflicto |
| Checkout | poblado, delivery, validación, ocupado, error, pedido creado y vínculo de pago manual |
| Reservas | fecha, horarios, sin cupo, alternativa concurrente, confirmada, cancelada, error |
| Mis pizzas | autenticación requerida, vacío autenticado, poblado, renombrado, compartido, conflicto |
| Global | hover, focus-visible, disabled, loading, error, success y reduced motion |

Los estados que crean entidades no se ejecutan contra datos persistidos del sitio de
referencia: se capturan con fixtures desechables y limpieza documentada.

## Tokens visuales de la fuente

| Grupo | Valores de referencia |
| --- | --- |
| Color | crema `#faebd7`, crema tenue `#f0dfc4`, papel `#fffdf8`, tinta `#0d0d0d`, marrón `#4a3b33`, salvia `#9daaaa` |
| Tipografía | Big Shoulders Display y Jost |
| Espaciado | escala 4, 8, 12, 16, 24, 32, 48, 64, 96 y 140 px |
| Sección | `clamp(64px, 10vw, 140px)` vertical y `clamp(24px, 5vw, 96px)` horizontal |
| Contenedor | 1240 px |
| Breakpoints | 480, 768, 1024 y 1440 px |
| Forma | radio píldora y geometría rectangular predominante |

Estos valores viven en `theme/theme.json` (ver
[`../../theme/docs/tokens.md`](../../theme/docs/tokens.md)). El plugin no los incrusta
como marca.


## Propiedad por componente

| Elemento | Propietario |
| --- | --- |
| Tokens, tipografía, presets, templates, parts y patterns | `theme/` |
| Markup semántico, estados y comportamiento de menú, pizza, carrito, checkout, pedidos, delivery y reservas | Bloques de `plugin/` |
| Solicitud y ciclo del pago manual | Módulo `Payments` del plugin |
| FAQ, testimonios y ajustes compartidos | Módulo `Shared` del plugin |
| Copy, media licenciada, páginas y composición | `content/`, `assets/` y el Editor del sitio |

El checkout usa el proveedor de pago manual real; la recomendación de WooCommerce del
prototipo está descartada.

## Assets y bloqueos

Las imágenes y los dos mapas están recuperados, y el video del hero lo aportó el
propietario; el resto de diferencias de media (foto de historia, avatares de
testimonios, original de dolci) son omisiones o sustituciones aprobadas. Detalle en
[`assets-bonasera.md`](assets-bonasera.md). No se versionan hotlinks, cookies, nonces,
credenciales, rutas personales ni datos privados como parte del baseline.

## Defectos que no deben preservarse

- header y overflow rotos en 768 px y 390 px;
- input de zona reducido a aproximadamente 30 px en móvil;
- H1 duplicados en carrito, checkout, reservas y pizzas guardadas;
- dependencia de imágenes remotas sin garantía de producción.

El constructor de pizzas sí renderiza cuando se alcanza su posición real; el supuesto
primer clic fallido de navegación no quedó demostrado. Ninguno se registra como bug
confirmado.

Responsive y accesibilidad se corrigen en Gutenberg aunque eso produzca una diferencia
intencional frente al defecto de la fuente. Esa diferencia debe documentarse; no se
aprueba de forma implícita.

## Diferencias conocidas

- Superficies propias de WordPress sin equivalente en la fuente (`/pedido/`,
  `/privacidad/`): solo entran al QA de WordPress.
- Campos y comportamientos de dominio que el prototipo omite o simula (correo, notas,
  dirección e instrucciones en checkout, preferencia de zona en reservas, timeline real
  de pedido, confirmación en dos pasos al eliminar una pizza guardada) se conservan como
  superconjunto funcional, no como desviación visual.
- Sin precio en las tarjetas de pizzas guardadas: el servidor es la única autoridad de
  precio y no se recalcula en el cliente.
- Sin imagen por línea de carrito ni acciones **Editar** de pizza (carrito y Mis pizzas):
  requerirían un modo de edición del constructor.
- Tarifas de propina, ETA y tasas provienen de la configuración real, no de los valores
  fijos del prototipo.

## Medición inicial de la línea base

La primera comparación (viewport fijo, cinco viewports, estado inicial) fue un punto de
partida, no el estado actual: 35 de 35 filas `different`, cero coincidencias y cero
diferencias aprobadas, con diferencia perceptual entre 31,55 % y 90,47 % según
superficie y viewport. Confirmó, por ejemplo, que Jost, el fondo crema y el texto tinta
de la fuente no estaban aplicados en WordPress. Ese resultado no debe reutilizarse como
resultado vigente: cualquier cifra nueva sale de una captura regenerada.

`different` significa deuda abierta; `pending` no significa aprobado.
