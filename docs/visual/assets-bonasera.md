# Contrato de assets Bonasera

## Resultado

La media de la fuente auditada está clasificada en `config/media.json` (schema 2). Diez
imágenes locales recuperan la misma fotografía o mapa de la fuente, una imagen local es
un sustituto aprobado, cinco originales se omiten o sustituyen por seguridad y
representación responsable, y el video del hero es un archivo propio entregado por el
propietario del sitio. El manifiesto declara `final_gate: approved-with-differences` y
ninguna entrada marca `blocks_final_parity`.

Una licencia compatible permite usar un archivo, pero no convierte una omisión o
sustitución en paridad visual ni autoriza a atribuir identidades o respaldo ficticios.

## Fuente y verificación

| Campo | Valor |
| --- | --- |
| Fuente | Prototipo de diseño de referencia, commit `1e1f62787e088c0ca9701500e764802499d1b253` |
| Referencias de origen | `src/data/media.js` del prototipo |
| Inventario local | `config/media.json`, schema 2 |
| Licencias revisadas | 2026-08-26 |

La [licencia de Unsplash](https://unsplash.com/license) permite descargar, usar y
modificar imágenes para fines comerciales y no comerciales, con las restricciones que
publica el proveedor. La [licencia de Pexels](https://www.pexels.com/license/) permite
uso y modificación, pero prohíbe sugerir respaldo de personas o marcas presentes en la
imagen. Se conservan autor, fuente y licencia aunque la atribución no sea obligatoria.
Los dos mapas usan datos de [OpenStreetMap](https://www.openstreetmap.org/copyright)
(Open Database License) renderizados con Leaflet; la atribución "© OpenStreetMap
contributors" queda visible en la propia imagen, como exige la licencia.

## Originales recuperados

Archivos locales en `assets/images/`, no hotlinks. Cada fila conserva dimensiones, peso
y SHA-256 en `config/media.json`. Estado: `exact-source-recovered`.

| Asset local | Referencia en la fuente |
| --- | --- |
| `antipasti.webp` | `CATEGORY_IMG.antipasti` |
| `insalate.webp` | `CATEGORY_IMG.insalate` |
| `pizze.webp` | `CATEGORY_IMG.pizze` |
| `pasta.webp` | `CATEGORY_IMG.pasta` |
| `secondi.webp` | `CATEGORY_IMG.secondi` |
| `contorni.webp` | `CATEGORY_IMG.contorni` |
| `bevande.webp` | `CATEGORY_IMG.bevande` |
| `reservas.webp` | `RESERVA_IMG` |
| `mapa-zulia.webp` | `MAP_ZULIA_IMG` |
| `mapa-maracaibo.webp` | `MAP_MARACAIBO_IMG` |

La identidad de la fotografía se comprueba mediante la referencia y el identificador
estable del proveedor; la integridad del archivo local, mediante su hash.

## Sustitución aprobada

`dolci.webp` usa una fotografía Pexels de tiramisú en lugar de `CATEGORY_IMG.dolci`,
cuyo original de Unsplash muestra una marca visible. Estado: `approved-substitute`.

## Originales omitidos

Los archivos no se descargan al repositorio; sus URL sobreviven solo como evidencia de
procedencia en la sección `excluded` del inventario. Estado: `approved-omission`.

| ID | Referencia | Motivo |
| --- | --- | --- |
| `historia-original` | `HISTORIA_IMG` | Personas identificables presentadas como familia ficticia |
| `testimonial-avatar-t1` | `AVATAR_MAP.t1` | Podría sugerir respaldo a un testimonio ficticio |
| `testimonial-avatar-t2` | `AVATAR_MAP.t2` | Ídem |
| `testimonial-avatar-t3` | `AVATAR_MAP.t3` | Ídem |

`dolci-original` (marca ajena visible) figura también en `excluded` con su sustituto.
Las omisiones y la sustitución están aprobadas por el propietario del sitio; no deben
reintroducirse retratos asociados a testimonios ficticios.

## Video del hero

`assets/hero-bg.mp4` (`provided-by-owner`) es un archivo propio autorizado por el
propietario del sitio para este proyecto; su hash y tamaño están en la sección `videos`
del inventario. El video de terceros que aparece en la referencia original de la fuente
no debe recuperarse ni usarse.

## Condiciones para media nueva

- Debe incluir procedencia, licencia, dimensiones, peso, hash y texto alternativo
  aplicable antes de entrar al repositorio.
- Todo reemplazo debe registrar su aprobación en el inventario.
- Tras cualquier cambio de media se regeneran la captura y el reporte visual.

## Límites de propiedad

Los assets y su composición pertenecen a este proyecto: el theme puede definir ratios,
recortes y estilos reutilizables, pero las fotografías de la marca viven en `assets/` y
se inventarían en `config/media.json`. El plugin no posee media editorial. El tooling de
validación nunca es dependencia de runtime.
