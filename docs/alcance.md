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

## Alcance de REST-02F

Esta fase añade únicamente:

- plugin 0.6.0, conservando schema de instalación 3 y contrato 1.0.0;
- `pizza_configuration` versión 1 con tamaño, masa, salsa, queso, toppings por zona,
  cantidad y revisión de catálogo obligatorios;
- validación cerrada de IDs, disponibilidad, tipos, versión, zonas y máximo global de
  seis toppings;
- pricing puro en enteros donde tamaño fija base, masa, queso y toppings suman
  modificadores, y salsa no modifica el precio en v1;
- precio completo por topping tanto en `whole` como en `left` o `right`;
- moneda operativa propia mediante Settings API, con `USD` como valor inicial;
- `POST /pizza/quote` público, sin caché, con schema y hook de rate limiting;
- desglose autoritativo que ignora importes enviados por el cliente.

No crea carritos ni calcula descuentos, impuestos, propina, delivery o total de
pedido. REST-02G es el siguiente issue y será propietario de esos componentes.

## Alcance de REST-02G

Esta fase añade únicamente:

- plugin 0.7.0 y schema de instalación 4;
- tablas InnoDB vacías para zonas de entrega y códigos de descuento;
- UUID, revisión de fila, compare-and-swap y revisión global de pricing;
- zonas explícitas con tarifa en unidad menor, ETA, orden y estado;
- descuentos fijos o porcentuales con vigencia, subtotal mínimo y límite de usos;
- consumo de códigos limitado mediante transacción y `SELECT ... FOR UPDATE`;
- tasa fiscal y opciones de propina en puntos base dentro de los ajustes propios;
- servicio puro de totales con redondeo half-up y orden descuento, impuesto, propina y
  delivery;
- `GET /delivery-zones` público, cacheable, con schema, revisión y `ETag`;
- administración nativa de zonas y descuentos con capabilities y nonces.

No crea sesiones, carritos ni pedidos. REST-02H es el siguiente issue y será
propietario de esa persistencia y sus mutaciones REST.

## Alcance de REST-02H

Esta fase añade únicamente:

- plugin 0.8.0 y schema de instalación 5;
- tablas InnoDB vacías para sesiones, carritos, líneas y registros idempotentes;
- secreto de sesión hasheado, cookie `HttpOnly` limitada al sitio y CSRF ligado a la
  sesión, con validación estricta de origen;
- ownership exclusivo por sesión o usuario, asociación idempotente al iniciar sesión
  sin fusionar dos carritos y rotación del secreto adoptado;
- líneas de menú y pizzas recalculadas desde catálogo y pricing vivos, sin aceptar
  importes ni snapshots del cliente;
- fusión solo de líneas de menú equivalentes y líneas independientes para pizzas;
- mutaciones atómicas con `expected_revision`, compare-and-swap y preservación de la
  línea original ante una edición fallida;
- descuento, pickup/delivery y propina con recálculo completo y revisión de catálogo,
  disponibilidad y pricing;
- expiración repetible mediante WP-Cron sin borrar líneas históricas;
- endpoints privados de carrito con `Cache-Control: no-store`, schemas, `ETag`, rate
  limiting conectable y errores contractuales.

No crea pedidos, checkout ni solicitudes de pago. REST-02I es el siguiente issue y
convertirá un carrito validado en snapshots inmutables y una máquina de estados.

## Alcance de REST-02I

Esta fase añade únicamente:

- plugin 0.9.0 y schema de instalación 6;
- tablas InnoDB para pedidos, líneas inmutables y eventos append-only;
- checkout transaccional que bloquea y revalida el carrito, consume el descuento,
  congela importes y convierte el carrito exactamente una vez;
- `Idempotency-Key` con hash, fingerprint, replay y rechazo de colisiones, sin guardar
  la clave ni el token de acceso en texto plano;
- ownership por usuario o token opaco de pedido para invitados;
- máquina de estados v1 con compare-and-swap, revisión y evento por transición;
- `POST /orders`, `GET /orders/{public_id}` y transición administrativa protegida;
- proyección privada `vicu_order`, listado, detalle, historial, salud y reconstrucción
  idempotente en wp-admin.

No crea solicitudes de pago ni consume eventos de `vicunav-pagos`. REST-02J es el
siguiente issue y será propietario exclusivo de esa integración.

## Fuera de alcance actual

El plugin todavía no registra bloques o assets. Tampoco contiene integración operativa
con pagos, reservas, contenido Bonasera ni integración con LocalWP.

Esas capacidades se implementan únicamente mediante los issues atómicos posteriores
del plan de restaurante.
