# vicunav-restaurante

Propósito: proyecto autocontenido de referencia para un sitio de restaurante en
WordPress sin WooCommerce (la trattoria ficticia Bonasera). Un único repositorio
contiene el plugin `vicunav-restaurante`, el theme de bloques `vicunav-bonasera`, el
contenido demostrativo, la media licenciada y las validaciones.

## Estructura y límites

- `plugin/`: plugin con tres módulos internos (`Vicu\Restaurante\*` para el dominio,
  `Vicu\Restaurante\Payments\*` para pagos y `Vicu\Restaurante\Shared\*` para FAQ,
  testimonios, ajustes y REST base). El servidor es la única autoridad de precios,
  disponibilidad, capacidad y estados.
- `theme/`: theme de bloques sin theme padre. Solo presentación: no consulta carrito,
  pedidos, pagos ni disponibilidad.
- `content/`, `assets/`, `config/`: contenido, media y manifiestos. No son contrato de
  runtime; toda imagen nueva exige procedencia y licencia en `config/media.json`.
- `bin/` y `tests/`: instalador local y validaciones.

Detalle en [`docs/arquitectura.md`](docs/arquitectura.md), [`docs/plugin.md`](docs/plugin.md)
y [`docs/theme.md`](docs/theme.md). El trabajo pendiente está en
[`docs/estado.md`](docs/estado.md).

## Reglas aplicables

Las reglas transversales del repositorio están en
[`docs/standards/`](docs/standards/). Consúltalas antes de realizar cambios y no las
repitas aquí; este archivo solo contiene el contexto específico del proyecto.

Reglas propias:

- No añadas dependencias de plugins o themes externos ni WooCommerce.
- El markup de negocio no se serializa en `post_content`; los bloques del plugin son
  dinámicos.
- Ningún secreto, token, cookie, contacto ni evidencia de pago aparece en URLs, logs,
  HTML cacheable ni respuestas públicas.
- No declares fidelidad visual 1:1 sin evidencia (ver
  [`docs/visual/baseline-bonasera.md`](docs/visual/baseline-bonasera.md)).

## Validación

```sh
bash tests/run.sh &&
(cd plugin && composer check && npm run check) &&
git diff --check &&
git submodule status
```

Revisa además la estructura, los enlaces y el formato Markdown de los documentos
modificados.

## Publicación

- No editar a mano `composer.lock`, `package-lock.json`, `CHANGELOG.md` ni el build
  versionado (`plugin/build`): se regenera con `npm run build`.
- No crear tags, releases o despliegues sin instrucción explícita.
- Todo cambio técnico usa un issue, una rama, un PR y squash-merge.
- El README público se escribe en inglés. La documentación interna y los comentarios
  de código se escriben en español.
