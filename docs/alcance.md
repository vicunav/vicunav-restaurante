# Alcance de `vicunav-restaurante`

## Responsabilidad futura

El plugin será propietario del dominio restaurante: menú estructurado, ingredientes,
disponibilidad, constructor de pizzas, carrito, pedidos, totales, delivery y reservas.
Consumirá contratos públicos de `vicunav-plugin-core` y `vicunav-pagos` sin leer su
persistencia interna.

La decisión coordinadora y el spec durable viven en `vicunav-hub`. El
[contrato técnico 1.0.0](contrato-publico.md) vive en este repositorio y distingue qué
superficies están implementadas de las que permanecen planificadas.

## Alcance de REST-02A

Esta fase incluye únicamente:

- repositorio creado desde `vicunav-repo-template`;
- submódulo de estándares compartidos;
- entry point instalable con compatibilidad y dependencias declaradas;
- namespace y constantes fundacionales;
- Composer, lint, WordPress Coding Standards, PHPUnit y CI;
- documentación de límites y contribución.

## Alcance de REST-02B

Esta fase añade únicamente:

- versión de plugin 0.2.0 y contrato 1.0.0;
- autoload del namespace `Vicu\Restaurante`;
- validación del contrato mayor 1 de core y de pagos desde 0.3.0 hasta antes de 1.0.0;
- comprobación de las clases públicas que consumirá el vertical;
- aviso administrativo protegido ante incompatibilidad;
- action `vicu_restaurante_loaded` para anunciar una carga compatible;
- contrato público y pruebas aisladas de estas garantías.

REST-02B no registró entidades de dominio. REST-02C añadió capabilities, migraciones e
instalación idempotente.

## Alcance de REST-02C

Esta fase añade únicamente:

- versión de plugin 0.3.0 y schema fundacional 1;
- capabilities primitivas concedidas al administrador durante activación;
- ledger InnoDB `${prefix}vicu_rest_migrations`;
- migraciones ordenadas, idempotentes y compensables;
- upgrade durante una carga compatible;
- pruebas aisladas y pruebas de integración con WordPress y MySQL reales.

No crea tablas o registros de negocio. REST-02D es el siguiente issue y será
propietario del menú estructurado.

## Alcance de REST-02D

Esta fase añade únicamente:

- plugin 0.4.0 y schema de instalación 2;
- CPT `vicu_menu_item` y taxonomía `vicu_menu_category`;
- copy editorial en título, extracto, contenido y media nativos;
- precio en unidad menor, moneda, disponibilidad, calorías, alérgenos y etiquetas
  dietarias como metadatos registrados y no expuestos por la API genérica;
- IDs públicos UUID v4, revisión global monotónica y migración sin contenido;
- edición nativa bajo el menú Vicunav con nonce y capability del catálogo;
- `GET /vicu/v1/restaurante/menu` y detalle por ID público, con filtro de categoría,
  object cache, `ETag` e invalidación por revisión.

No incorpora ingredientes ni relaciones con ellos. REST-02E es el siguiente issue y
será propietario del catálogo canónico de ingredientes, opciones de pizza y
disponibilidad revisada.

## Alcance de REST-02E

Esta fase añade únicamente:

- plugin 0.5.0 y schema de instalación 3;
- tablas InnoDB vacías para ingredientes, relaciones de items y opciones de pizza;
- IDs públicos UUID v4, importes enteros con signo y vocabularios cerrados;
- servicios transaccionales con revisión de fila y compare-and-swap;
- una revisión global única para ingredientes, opciones y disponibilidad;
- relaciones `required`, `removable` u `optional`, con orden y sustitución explícita;
- administración nativa bajo Vicunav con capabilities y nonces;
- `GET /ingredients/availability` y `GET /pizza/options`, con schema, `ETag`, caché e
  invalidación por revisión.

No calcula precios de pizzas ni incorpora contenido de demostración. REST-02F es el
siguiente issue y será propietario del quote autoritativo del constructor.

## Fuera de alcance actual

El plugin todavía no registra ajustes generales, bloques o assets. Tampoco contiene
pricing autoritativo, carrito, pedidos, integración operativa con pagos, reservas,
contenido Bonasera ni integración con LocalWP.

Esas capacidades se implementan únicamente mediante los issues atómicos posteriores
del plan de restaurante.
