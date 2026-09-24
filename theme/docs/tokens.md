# Tokens del theme Bonasera

## Fuente

Los tokens provienen del export de diseño del prototipo de referencia (commit
`1e1f62787e088c0ca9701500e764802499d1b253`): `legacy/Restaurante Guasábara.dc.html` y
los CSS de `tokens/` de su sistema de diseño. La reimplementación `src/` del prototipo
no es fuente de tokens. Los valores viven en `theme/theme.json` como presets
`vicunav-*` (consumidos también por los bloques del plugin) y `bonasera-*` (propios de
la marca). Composición general en [`../../docs/theme.md`](../../docs/theme.md).

## Paleta

| Slug | Valor | Origen |
| --- | --- | --- |
| `vicunav-primary` | `#0D0D0D` | `--color-ink` |
| `vicunav-secondary` | `#4A3B33` | `--color-malt-brown` |
| `vicunav-accent` | `#9DAAAA` | `--color-sage` |
| `vicunav-neutral-100` | `#FAEBD7` | `--color-cream` |
| `vicunav-neutral-200` | `#F0DFC4` | `--color-cream-dim` |
| `vicunav-neutral-300` | `#FFFDF8` | derivado (blanco papel; no está en `colors.css`) |
| `vicunav-neutral-400/500/600/700` | `rgba(13,13,13,.12/.5/.62/.75)` | overlays de `--color-ink` a distintas opacidades |
| `vicunav-neutral-800/900` | `#4A3B33` / `#0D0D0D` | malta / tinta |
| `bonasera-ink-soft` | `#181410` | `--color-ink-soft` |
| `bonasera-dusty-rose` | `#D99B93` | `--color-dusty-rose` |

Las dos últimas filas son presets propios de la marca, adicionales a los slugs
`vicunav-*`.

`vicunav-positive` (`#4D673B`), `vicunav-warning` (`#9F4527`), `vicunav-danger`
(`#A8432B`) y `vicunav-info` (`#557259`) son variantes oscurecidas de los colores de
estado que usaba la fuente (`#C1592F`, `#5B7A45`, `#7C9A7E`), que no alcanzaban 4.5:1
de contraste sobre crema. Relaciones calculadas con luminancia relativa WCAG 2.1
(`theme/tests/validate-theme.mjs`):

| Combinación | Relación |
| --- | ---: |
| Positivo sobre crema | 5.40:1 |
| Advertencia sobre crema | 5.34:1 |
| Peligro sobre crema | 5.12:1 |
| Información sobre crema | 4.55:1 |
| Tinta sobre crema | 16.59:1 |
| Tinta sobre salvia | 8.11:1 |

## Tipografía

Big Shoulders Display (700–900) y Jost (400–700), autoalojadas bajo SIL Open Font
License 1.1 (`assets/fonts/licenses/`), confirmadas en `_ds/tokens/fonts.css` de la
fuente. Los ocho pasos de tamaño `vicunav-*` se completan con
siete valores reales de `_ds/tokens/typography.css` (`--text-eyebrow`, `--text-small`,
`--text-body`, `--text-body-lg`, `--text-display-md/lg/xl`); la fuente solo define
siete pasos distintos, así que `vicunav-display-sm` y `vicunav-display-lg` comparten
el mismo valor (`clamp(3.5rem, 9vw, 7.5rem)`) en vez de inventar un octavo paso que el
diseño no tiene.

## Espaciado

Escala 1:1 con `_ds/tokens/spacing.css` (`--space-1` a `--space-10`: 4, 8, 12, 16, 24,
32, 48, 64, 96, 140 px), mapeada a los diez slugs `vicunav-space-*`.

## Media

El video del hero y los mapas de zona de entrega se rigen por el contrato de assets
([`../../docs/visual/assets-bonasera.md`](../../docs/visual/assets-bonasera.md)). El
video original del prototipo es un archivo de terceros sin licencia verificable y no
debe recuperarse ni sustituirse por él; mientras no exista media propia se usan
placeholders editables que no alteran la geometría.
