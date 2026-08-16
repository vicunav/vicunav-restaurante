# Contrato público de `vicunav-restaurante`

Estado: contrato 1.0.0 aprobado; REST-02G implementa carga, compatibilidad,
instalación, menú, catálogo, pricing de pizzas, zonas, descuentos y totales. Las demás
superficies se habilitan por los issues indicados en la matriz, sin considerarse
operativas antes de ellos.

## Responsabilidad y límites

El plugin es propietario del menú estructurado, ingredientes, disponibilidad,
configuración de pizzas, carrito, pedidos, pricing, delivery, reservas y reacción a
eventos públicos de pago. También será propietario de sus bloques dinámicos y sus
superficies administrativas.

No contiene composición Bonasera, identidad visual global ni el ciclo de vida interno
de pagos. No usa WooCommerce. Los consumidores nunca leen tablas, post meta o clases
internas de otro paquete.

## Estado de implementación

| Superficie | Issue propietario | Estado después de REST-02G |
| --- | --- | --- |
| Versiones, autoload, dependencias y hook de carga | REST-02B | Implementado |
| Capabilities, migraciones e instalación | REST-02C | Implementado |
| Menú estructurado | REST-02D | Implementado |
| Ingredientes y opciones de pizza | REST-02E | Implementado |
| Pricing de pizzas | REST-02F | Implementado |
| Totales, descuentos y delivery | REST-02G | Implementado |
| Carrito, pedidos e integración con pagos | REST-02H a REST-02J | Planificado |
| Reservas y pizzas guardadas | REST-02K y REST-02L | Planificado |
| Bloques públicos | REST-02M a REST-02Q | Planificado |
| E2E, privacidad, rendimiento y release candidata | REST-02R | Planificado |

Una superficie planificada no es una API disponible. Cada issue actualiza esta matriz,
implementa el contrato correspondiente y añade pruebas antes de cambiar su estado.

## Compatibilidad y dependencias

- WordPress 6.6 o superior.
- PHP 8.1 o superior.
- Namespace PHP raíz: `Vicu\Restaurante`.
- Versión del plugin: constante `VICU_RESTAURANTE_VERSION`.
- Versión del contrato: constante `VICU_RESTAURANTE_CONTRACT_VERSION`.
- `vicunav-plugin-core` con contrato `>= 1.0.0` y `< 2.0.0`.
- `vicunav-pagos` con contrato `>= 0.3.0` y `< 1.0.0`.

El header `Requires Plugins` declara `vicunav-plugin-core` y `vicunav-pagos` mediante
sus slugs. Como WordPress no admite restricciones de versión en ese header, el
bootstrap valida además las constantes contractuales y las clases públicas requeridas.

De core requiere `Vicu\Core\PostType`, `Vicu\Core\Rest`, `Vicu\Core\Security` y
`Vicu\Core\Settings`. De pagos requiere `Vicu\Pagos\PaymentRequests`,
`Vicu\Pagos\PaymentRequestState` y `Vicu\Pagos\ManualPaymentProvider`.

## Orden de carga

El entry point registra inmediatamente el autoloader de `Vicu\Restaurante`. El
bootstrap se ejecuta en `plugins_loaded` con prioridad 20, después de core en prioridad
5 y pagos en prioridad 10.

Si una dependencia está ausente, expone un contrato incompatible o no ofrece sus
clases públicas, el plugin no registra comportamiento de dominio ni publica su hook de
carga. Solo añade un aviso administrativo para usuarios con `activate_plugins`.

Una combinación compatible publica exactamente una vez:

```php
do_action(
	'vicu_restaurante_loaded',
	VICU_RESTAURANTE_VERSION,
	VICU_RESTAURANTE_CONTRACT_VERSION
);
```

Los consumidores inicializan integraciones en `vicu_restaurante_loaded` o en una
prioridad posterior. No deben inferir disponibilidad solo por la existencia del
archivo del plugin.

## Instalación y migraciones

La activación ejecuta migraciones pendientes y solo después concede capabilities al
rol administrador. REST-02G eleva el schema a versión `4`: conserva
`${prefix}vicu_rest_migrations`, un ledger InnoDB; mantiene la revisión del menú e
incorpora tablas InnoDB vacías para ingredientes, relaciones, opciones de pizza, zonas
de entrega y descuentos. Inicializa las revisiones de disponibilidad y pricing en
`1`, sin crear contenido, zonas, códigos ni datos de demostración.

Cada migración tiene versión monotónica, comprobación de aplicación, operación `up()`
y compensación `down()`. El instalador:

1. ordena las migraciones por versión;
2. omite las ya confirmadas por option y ledger;
3. registra cada versión únicamente después de verificar el cambio físico;
4. compensa en orden inverso los recursos nuevos si una migración falla;
5. conserva exactamente la versión previa ante fallo.

Los nombres se construyen con `$wpdb->prefix`. Cada issue de dominio añade su propia
migración y sus pruebas, sin crear datos de demo.

### Capabilities fundacionales

El administrador recibe durante activación:

- `manage_vicu_restaurant_catalog`;
- `manage_vicu_restaurant_availability`;
- `manage_vicu_restaurant_discounts`;
- `manage_vicu_restaurant_delivery`;
- `view_vicu_restaurant_orders`;
- `manage_vicu_restaurant_orders`;
- `fulfill_vicu_restaurant_orders`;
- `view_vicu_restaurant_payment_evidence`;
- `manage_vicu_restaurant_reservations`;
- `manage_vicu_restaurant_settings`;
- `reconcile_vicu_restaurant_payments`.

No se conceden por defecto a otros roles. Una capability autoriza una categoría de
operación, pero cada escritura debe verificar además nonce, ownership y revisión
cuando correspondan.

## Menú estructurado v1

`vicu_menu_item` es un CPT público administrado bajo el menú Vicunav. Usa título,
extracto, contenido, imagen destacada y `menu_order` para copy, historia, media y orden
editoriales. La taxonomía jerárquica `vicu_menu_category` aporta slug estable, nombre,
descripción, orden no negativo y visibilidad. La interfaz administrativa asigna una
sola categoría por item.

El controlador genérico `wp/v2/restaurant-menu-items` existe para el editor de bloques,
pero exige `manage_vicu_restaurant_catalog` incluso en lecturas. Los clientes públicos
usan únicamente la proyección validada bajo `/vicu/v1/restaurante/menu`.

Los campos operativos registrados son privados a la persistencia:

| Campo público | Tipo y regla | Persistencia v1 |
| --- | --- | --- |
| `public_id` | UUID v4 opaco e inmutable para consumidores | `_vicu_rest_public_id` |
| `price_minor` | Entero no negativo en unidad menor | `_vicu_rest_price_minor` |
| `currency` | Tres letras mayúsculas ISO 4217 | `_vicu_rest_currency` |
| `available` | Booleano; `false` impide selecciones nuevas | `_vicu_rest_available` |
| `calories_kcal` | Entero no negativo e informativo | `_vicu_rest_calories_kcal` |
| `allergens` | Lista única de IDs controlados | `_vicu_rest_allergens` |
| `dietary_tags` | Lista única de IDs controlados | `_vicu_rest_dietary_tags` |

Los alérgenos iniciales son `celery`, `crustaceans`, `eggs`, `fish`, `gluten`,
`lupin`, `milk`, `molluscs`, `mustard`, `nuts`, `peanuts`, `sesame`, `soy` y
`sulphites`. Las etiquetas dietarias iniciales son `spicy`, `vegan` y `vegetarian`.
No son texto libre. La información de alérgenos nunca elimina el riesgo de
contaminación cruzada.

Un item solo aparece en lecturas públicas cuando está publicado, tiene título, ID,
precio, moneda y disponibilidad persistidos, y pertenece exactamente a una categoría
visible. La ausencia o inconsistencia de un campo falla cerrada. Un item completo con
`available = false` sí aparece para que el cliente muestre el estado agotado, pero no
podrá añadirse a un carrito cuando REST-02H implemente esa escritura.

La revisión global vive en `vicu_restaurante_menu_revision`, comienza en `1` y aumenta
una vez por solicitud de escritura relevante. Sus valores invalidan las claves de
object cache y forman parte del `ETag`.

## Ingredientes, opciones y disponibilidad v1

El catálogo operativo usa IDs públicos UUID v4 y no expone sus IDs internos. Un
ingrediente contiene nombre, categoría, modificador de precio con signo en unidad
menor, disponibilidad, alérgenos, etiquetas dietarias y revisión de fila. Las
categorías cerradas son `base`, `cheese`, `extra` y `topping`; alérgenos y etiquetas
reutilizan los vocabularios controlados del menú.

Una opción de pizza contiene nombre, tipo, modificador de precio con signo,
disponibilidad, orden y revisión de fila. Los únicos tipos son `size`, `crust` y
`sauce`. REST-02E almacena esos modificadores, pero no calcula configuraciones ni
totales: esa autoridad comienza en REST-02F.

Las relaciones entre un `vicu_menu_item` y sus ingredientes usan uno de los roles
`required`, `removable` u `optional`, un orden no negativo y una sustitución explícita
opcional. El reemplazo del conjunto es transaccional. La sustitución debe referenciar
otro ingrediente existente y nunca se infiere desde nombres o categorías.

Ingredientes y opciones comparten la revisión global
`vicu_restaurante_availability_revision`. Comienza en `1` y aumenta exactamente una
vez por creación o actualización confirmada. Cada recurso empieza con revisión `1` y
la incrementa una vez por actualización. Las escrituras usan compare-and-swap; una
revisión esperada obsoleta devuelve `vicu_restaurante_stale_revision` con HTTP 409,
`current_revision` y `retryable = true`, sin aplicar cambios parciales.

La administración nativa vive bajo Vicunav y separa catálogo de disponibilidad. Los
formularios estructurales requieren `manage_vicu_restaurant_catalog`; los toggles de
disponibilidad requieren `manage_vicu_restaurant_availability`. Toda escritura exige
nonce. No existe borrado operativo: un recurso se desactiva para conservar
referencias históricas.

## Principios de datos públicos v1

- Todo importe usa enteros en unidad menor y una moneda ISO 4217.
- Todo porcentaje persistido usa puntos base.
- IDs públicos son opacos y no enumerables; un ID interno nunca autoriza acceso.
- Recursos mutables publican una revisión monotónica para compare-and-swap.
- Pedidos y reservas conservan snapshots históricos.
- El servidor es la única autoridad de precio, disponibilidad, capacidad y estado.
- Tokens, claves idempotentes y datos privados no aparecen en URLs, logs ni respuestas
  públicas.

La persistencia física no forma parte del contrato. Sus tablas, índices y proyecciones
pueden cambiar mediante migraciones compatibles sin autorizar lecturas externas.

## Pricing y totales v1

El cálculo autoritativo sigue este orden:

```text
subtotal = suma(precio_unitario_autoritativo * cantidad + ajustes_de_linea)
discount_total = suma(descuentos_validos), limitada a subtotal
net_merchandise = subtotal - discount_total
tax_total = round_half_up(net_merchandise * tax_rate_bps / 10000)
tip_total = round_half_up(net_merchandise * tip_rate_bps / 10000)
delivery_total = tarifa vigente de la zona, o 0 para pickup
total = net_merchandise + tax_total + tip_total + delivery_total
```

Delivery y propina no forman parte de la base fiscal en v1. El pedido congela el
desglose y la solicitud de pago usa exactamente su `total` y `currency`. Un total debe
ser positivo para crear una solicitud en pagos.

REST-02G implementa esta fórmula en `TotalsService` sin persistir carrito ni pedido.
El subtotal recibido debe provenir de líneas ya cotizadas por servidor. El servicio
resuelve código y zona por ID, usa la moneda y tasas vigentes, y no acepta importes de
descuento, impuesto, propina, delivery o total del cliente.

Las tasas se guardan en puntos base. El impuesto inicial es 800 puntos base y las
opciones iniciales de propina son 0, 1000, 1500 y 2000. Los operadores pueden
cambiarlas mediante Settings API; la lista siempre debe incluir cero. No existe una
propina no nula preseleccionada en el dominio.

Los descuentos son `fixed` en unidad menor o `percent` en puntos base. Pueden exigir
subtotal mínimo, vigencia UTC, estado activo y máximo de usos. Resolver un código no
lo consume. El checkout futuro consumirá el uso bajo bloqueo `SELECT ... FOR UPDATE`;
la verificación y el incremento ocurren en la misma transacción. Un descuento nunca
reduce `net_merchandise` por debajo de cero.

Las zonas de delivery se eligen explícitamente por UUID. Cada una congela nombre,
tarifa y ETA; no usa texto de dirección, geocoding ni mapas como autoridad. Pickup
exige tarifa cero y no acepta una zona. Una zona inactiva devuelve
`vicu_restaurante_unavailable` para selecciones nuevas.

## Estados del pedido v1

Los valores estables son:

- `pendiente_pago`;
- `pago_en_revision`;
- `confirmado`;
- `en_preparacion`;
- `listo`;
- `en_reparto`;
- `completado`;
- `cancelado`;
- `expirado`.

Las transiciones contractuales son:

| Origen | Destinos |
| --- | --- |
| creación | `pendiente_pago` |
| `pendiente_pago` | `pago_en_revision`, `cancelado`, `expirado` |
| `pago_en_revision` | `pendiente_pago`, `confirmado`, `cancelado`, `expirado` |
| `confirmado` | `en_preparacion`, `cancelado` por operador autorizado |
| `en_preparacion` | `listo`, `cancelado` por operador autorizado |
| `listo` | `completado` para pickup, `en_reparto` para delivery |
| `en_reparto` | `completado` |
| terminales | Ninguno |

Cada transición válida incrementa la revisión una vez y añade un evento auditable. V1
no automatiza devoluciones después de un pago confirmado.

## Integración pública con pagos

El pedido se enlaza mediante `external_type = vicu_order` y su ID público como
`external_id`. La creación usa `Vicu\Pagos\PaymentRequests::create()` con monto,
moneda y vencimiento congelados. Los reintentos reutilizan la misma referencia.

La evidencia textual v1 se entrega mediante
`Vicu\Pagos\ManualPaymentProvider::submit_proof()`. Pagos recibe una referencia opaca,
no el contenido privado ni instrucciones del comercio.

El vertical consume estos eventos con `payload_version = 1.0.0`:

- `vicu_pagos_comprobante_recibido`;
- `vicu_pagos_confirmado`;
- `vicu_pagos_rechazado`;
- `vicu_pagos_expirado`.

Antes de reaccionar comprueba tipo e ID externos, monto, moneda, revisión y transición.
Un evento duplicado u obsoleto no cambia el pedido. La reconciliación mediante
`PaymentRequests::get()` es obligatoria porque los hooks no constituyen entrega
garantizada.

## REST v1

El namespace base es `/wp-json/vicu/v1/restaurante`. Las rutas se registran mediante
`Vicu\Core\Rest::register_route()` con schema y `permission_callback` explícitos.

| Grupo | Rutas contractuales |
| --- | --- |
| Catálogo | `GET /menu`, `GET /menu/{public_id}`, `GET /ingredients/availability` |
| Pizzas | `GET /pizza/options`, `POST /pizza/quote` |
| Delivery | `GET /delivery-zones` |
| Carrito | `POST /carts`, `GET /cart`, mutaciones bajo `/cart` |
| Pedidos | `POST /orders`, `GET /orders/{public_id}`, entrega de evidencia |
| Reservas | disponibilidad, creación, consulta privada y cancelación bajo `/reservations` |
| Cuenta | CRUD propio bajo `/saved-pizzas` |

Los schemas exactos se incorporan con el issue que implementa cada grupo y permanecen
compatibles durante el contrato mayor 1.

### Lecturas de menú implementadas

`GET /menu` acepta únicamente el filtro opcional `category` como slug normalizado y
devuelve:

```json
{
  "revision": 2,
  "categories": [
    { "slug": "pizzas", "name": "Pizzas", "description": "", "order": 1 }
  ],
  "items": [
    {
      "public_id": "00000000-0000-4000-8000-000000000000",
      "name": "Nombre",
      "description": "Copy breve",
      "story": "<p>Historia editorial</p>",
      "price_minor": 1250,
      "currency": "USD",
      "available": true,
      "calories_kcal": 720,
      "allergens": ["gluten", "milk"],
      "dietary_tags": ["vegetarian"],
      "category": "pizzas",
      "order": 1,
      "image": null
    }
  ]
}
```

`GET /menu/{public_id}` devuelve `revision` e `item` con el mismo schema. Un UUID
ausente o no publicable usa `vicu_restaurante_not_found`; una categoría desconocida u
oculta usa `vicu_restaurante_invalid_request`. Las respuestas 200 incluyen `ETag` y
`Cache-Control: public, max-age=60, stale-while-revalidate=300`; un
`If-None-Match` exacto devuelve 304. El object cache interno usa TTL de 300 segundos y
claves ligadas a revisión.

### Lecturas de ingredientes y opciones implementadas

`GET /ingredients/availability` devuelve la revisión global y todos los ingredientes
como `{ public_id, available, revision }`, incluidos los no disponibles. Responde con
`Cache-Control: no-cache, max-age=0, must-revalidate` y `ETag`; un
`If-None-Match` vigente devuelve 304.

`GET /pizza/options` devuelve la misma revisión y agrupa el catálogo en `sizes`,
`crusts`, `sauces`, `cheeses` y `toppings`. Las tres primeras colecciones contienen
opciones completas; las dos últimas contienen ingredientes completos. Los elementos
no disponibles permanecen presentes con `available = false`. Responde con
`Cache-Control: public, max-age=60, stale-while-revalidate=300`, object cache ligado a
revisión y `ETag`; un `If-None-Match` vigente devuelve 304.

Ambas rutas son lecturas públicas y sus schemas forman parte del contrato 1.x. No
aceptan parámetros de escritura ni calculan precios.

### Quote de pizza implementado

`POST /pizza/quote` recibe un objeto `configuration` con esta forma:

```json
{
  "version": 1,
  "catalog_revision": 7,
  "size_id": "00000000-0000-4000-8000-000000000001",
  "crust_id": "00000000-0000-4000-8000-000000000002",
  "sauce_id": "00000000-0000-4000-8000-000000000003",
  "cheese_ingredient_id": "00000000-0000-4000-8000-000000000004",
  "toppings": {
    "00000000-0000-4000-8000-000000000005": "left"
  },
  "quantity": 1
}
```

Tamaño, masa, salsa y queso son obligatorios. Los toppings forman un mapa por UUID,
por lo que una referencia solo puede ocupar una zona exclusiva: `whole`, `left` o
`right`. El máximo es seis en todo el mapa. Una configuración incompleta, con versión
desconocida, zona inválida o cantidad no entera usa
`vicu_restaurante_invalid_request`. Una revisión antigua usa
`vicu_restaurante_stale_revision`; una referencia ausente, agotada o de tipo
incorrecto usa `vicu_restaurante_unavailable`. No se completan defaults.

El tamaño aporta el precio base. Masa, queso y cada topping aportan su modificador. La
salsa aporta cero en v1 aunque el registro tenga un modificador almacenado. Cada
topping se cobra una vez a precio completo, también cuando ocupa solo `left` o
`right`. El flag visual premium no participa del cálculo.

La respuesta incluye configuración normalizada, revisión, moneda, componentes,
`unit_total_minor`, cantidad y `total_minor`. Todos los importes provienen del
catálogo vigente y usan enteros; cualquier precio adicional del request se ignora. La
moneda proviene del option propio `vicu_restaurante_settings`, administrado en la
pestaña Restaurante de Vicunav mediante Settings API junto con impuesto y propinas.
El valor inicial es `USD` y solo se aceptan tres letras mayúsculas.

La ruta es pública para el constructor, devuelve `Cache-Control: no-store, max-age=0`
y expone el filtro `vicu_restaurante_allow_public_quote` para conectar la política de
rate limit de la instalación. Si el filtro deniega, devuelve
`vicu_restaurante_rate_limited` con HTTP 429. Un quote no reserva inventario, no crea
carrito y debe revalidarse en cada mutación posterior.

### Zonas de entrega implementadas

`GET /delivery-zones` devuelve `revision`, `currency` y zonas activas ordenadas. Cada
zona incluye UUID, nombre, tarifa en unidad menor, ETA mínimo y máximo, orden y
revisión de fila. No expone zonas inactivas, IDs internos, direcciones ni mapas.

Las respuestas 200 incluyen `Cache-Control: public, max-age=60,
stale-while-revalidate=300` y un `ETag` ligado a
`vicu_restaurante_pricing_revision`; un `If-None-Match` vigente devuelve 304. Cambiar
zonas, descuentos, tasas, propinas o moneda incrementa esa revisión. Los descuentos
no tienen endpoint público separado: REST-02H los aplicará dentro del carrito para no
crear un oráculo enumerable de códigos.

### Errores estables

Los fallos usan `WP_Error` y la forma REST estándar. Los códigos v1 son:

- `vicu_restaurante_invalid_request`;
- `vicu_restaurante_authentication_required`;
- `vicu_restaurante_forbidden`;
- `vicu_restaurante_not_found`;
- `vicu_restaurante_unavailable`;
- `vicu_restaurante_stale_revision`;
- `vicu_restaurante_idempotency_collision`;
- `vicu_restaurante_invalid_transition`;
- `vicu_restaurante_payment_mismatch`;
- `vicu_restaurante_rate_limited`;
- `vicu_restaurante_storage_error`;
- `vicu_restaurante_dependency_unavailable`.

Las escrituras no devuelven éxito parcial. Los errores de campos no repiten datos
privados.

## Bloques v1

Las superficies públicas planificadas usan estos nombres estables:

- `vicunav/restaurante-menu`;
- `vicunav/restaurante-pizza-builder`;
- `vicunav/restaurante-cart`;
- `vicunav/restaurante-checkout`;
- `vicunav/restaurante-order-status`;
- `vicunav/restaurante-delivery-zones`;
- `vicunav/restaurante-reservations`;
- `vicunav/restaurante-saved-pizzas`.

Serán bloques dinámicos con render de servidor y assets condicionales. FSE puede editar
composición y contenido, pero no pricing, estados, permisos, schemas o disponibilidad
transaccional.

## Gestión de cambios

Una versión menor del contrato puede añadir campos o superficies opcionales sin
cambiar significado, firmas, rutas o valores existentes. Romper un nombre de bloque,
ruta, error, estado, fórmula, hook o forma pública requiere una nueva versión mayor,
identificar consumidores y coordinar la migración en `vicunav-hub` antes del merge.

Las fuentes coordinadoras son el
[ADR de comercio sin WooCommerce](https://github.com/vicunav/vicunav-hub/blob/main/docs/adr/0009-restaurante-sin-woocommerce.md)
y la
[especificación durable](https://github.com/vicunav/vicunav-hub/blob/main/docs/especificaciones/restaurante-v1.md).
