# Arquitectura

Bonasera es un único proyecto WordPress de referencia para restaurantes, sin
WooCommerce y sin dependencias de otros plugins o themes. El repositorio contiene todo
lo necesario para instalarlo en un sitio local y validarlo.

## Estructura del repositorio

| Ruta | Contenido |
| --- | --- |
| `plugin/` | Plugin `vicunav-restaurante`: `src/`, `build/` (bloques compilados, versionado), `tests/`, `composer.json` y `package.json`. |
| `theme/` | Theme de bloques `vicunav-bonasera`: `theme.json`, `templates/`, `parts/`, `patterns/`, `assets/` (CSS, JS, fuentes), `tests/` y `docs/tokens.md`. |
| `content/bonasera.json` | Contenido demostrativo auditado. |
| `assets/` | Media local licenciada. |
| `config/site.json` | Manifiesto de instalación: versiones mínimas y rutas/slug del plugin y del theme. |
| `config/media.json` | Inventario de media: procedencia, licencia, dimensiones, peso y SHA-256. |
| `config/qa.json` | Contrato de QA visual: rutas, viewports y presupuestos. |
| `bin/` | `install-local.sh` (instalación en LocalWP) y `wpcli.sh`. |
| `tests/` | `validate-content.mjs`, `validate-qa.mjs`, `qa-runtime.php` y `run.sh`. |
| `docs/` | Documentación; `docs/standards` es un submódulo con los estándares compartidos. |

## Plugin

`plugin/vicunav-restaurante.php` define las constantes de versión (`VICU_RESTAURANTE_VERSION`,
`VICU_RESTAURANTE_DB_VERSION`) y carga `src/bootstrap.php`, que registra el autoloader
PSR-like de `Vicu\Restaurante` (clases `class-*.php` en directorios kebab-case) y
arranca todo en `plugins_loaded` con prioridad 20. Si el schema no puede actualizarse,
no se registra ningún comportamiento y se muestra un aviso a quien tenga
`activate_plugins`.

Tres módulos internos conviven bajo el mismo namespace raíz:

| Módulo | Namespace | Responsabilidad |
| --- | --- | --- |
| Dominio | `Vicu\Restaurante\*` | Menú, catálogo, pizzas, comercio (zonas, descuentos, totales), carrito, pedidos, reservas, pizzas guardadas, privacidad, administración, REST y bloques. |
| Pagos | `Vicu\Restaurante\Payments\*` | Solicitudes de pago (`PaymentRequests`), proveedor manual, máquina de estados de pago, expiración y eventos. |
| Compartido | `Vicu\Restaurante\Shared\*` | FAQ (`vicu_faq`), testimonios (`vicu_testimonial`), ajustes generales con pestañas, `Rest::register_route()` sobre el namespace `vicu/v1` y base de CPT. |

Los módulos no leen la persistencia unos de otros: el dominio usa `PaymentRequests` y
`ManualPaymentProvider`, y reacciona a los hooks `vicu_pagos_*`; nunca consulta el CPT
`vicu_payment_req` ni sus tablas. El theme usa FAQ y testimonios mediante Query Loop
sobre `vicu_faq` y `vicu_testimonial`.

Directorios de `plugin/src/`: `admin`, `blocks`, `cart`, `catalog`, `commerce`, `menu`,
`migrations`, `order`, `payments`, `pizza`, `privacy`, `reservation`, `rest`,
`saved-pizza`, `settings` y `shared`, más `class-capabilities.php`,
`class-installer.php` y `class-schema.php`. La superficie pública se describe en
[`plugin.md`](plugin.md).

### Instalación y migraciones

La activación ejecuta las migraciones pendientes, concede capabilities al administrador,
instala el schema de pagos y programa las tareas de carritos y pagos. El instalador
ordena migraciones por versión, omite las confirmadas (option y ledger
`${prefix}vicu_rest_migrations`, InnoDB), registra cada versión solo tras verificar el
cambio físico y, ante un fallo, compensa en orden inverso y conserva la versión previa.
Las migraciones no crean contenido de demostración: horarios, catálogo y reservas de
Bonasera se siembran aparte a partir de `content/bonasera.json`.

## Theme

`vicunav-bonasera` es un theme de bloques independiente. Aporta presets `vicunav-*` y
`bonasera-*`, cuatro template parts de restaurante, patterns `vicunav-bonasera/*`,
hojas CSS acotadas y fuentes autoalojadas. No contiene lógica de negocio. Ver
[`theme.md`](theme.md) y [`../theme/docs/tokens.md`](../theme/docs/tokens.md).

## Contenido y media

`content/bonasera.json` y `config/media.json` son datos de siembra y de auditoría, no
contrato de runtime. Toda la media es local; las URL externas sobreviven solo como
evidencia de procedencia. Ver [`contenido-y-media.md`](contenido-y-media.md).

## Instalación local

```bash
bash bin/install-local.sh --wp-path=/ruta/a/app/public --site-url=https://x.local [--dry-run]
```

Lee `config/site.json`, comprueba que el host de `--site-url` termine en `.local`,
enlaza `plugin/` como `wp-content/plugins/vicunav-restaurante` y `theme/` como
`wp-content/themes/vicunav-bonasera`, y los activa con WP-CLI solo si no lo están
(idempotente). Rechaza destinos ocupados por otro directorio. Variables:
`VICUNAV_PHP_BIN`, `VICUNAV_WP_CLI_BIN`, `VICUNAV_MYSQL_SOCKET` y
`VICUNAV_MANIFEST_PATH` (pruebas).

## Validación

| Comando | Cubre |
| --- | --- |
| `bash tests/run.sh` | Contenido, QA, theme (`theme/tests/run.sh`) e instalador contra un WordPress simulado. |
| `composer check` (en `plugin/`) | `composer validate`, lint PHP, WPCS, PHPUnit unitario e integración con WordPress y MySQL reales. |
| `npm run check` (en `plugin/`) | Audit de producción, lint JS/CSS, Jest, contrato visual y build. |

CI ejecuta la matriz PHP 8.1 / WordPress 6.6 (dependencias mínimas) y PHP 8.4 /
WordPress 6.9 (lockfile).

## Límites

- Sin WooCommerce ni pasarelas externas: el único proveedor de pago es el manual.
- El servidor decide precios, disponibilidad, capacidad, ownership y estados; el
  cliente y el theme nunca son autoridad.
- La persistencia física no es contrato; las tablas pueden cambiar por migración.
- La administración vive bajo el menú **Vicunav** de wp-admin, con capabilities
  propias y nonce en cada escritura.
- El contenido editorial (copy, media, composición) se edita en el Editor del sitio;
  el plugin no serializa markup de negocio en `post_content`.
