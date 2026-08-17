# Contrato público de `vicunav-restaurante`

Estado: contrato 1.0.0 aprobado; REST-02L implementa carga, compatibilidad,
instalación, menú, catálogo, pricing de pizzas, zonas, descuentos, totales, carrito,
checkout, pedidos, integración pública con pagos, reservas y pizzas guardadas. Las
demás superficies se habilitan por los issues indicados en la matriz, sin
considerarse operativas antes de ellos.

## Responsabilidad y límites

El plugin es propietario del menú estructurado, ingredientes, disponibilidad,
configuración de pizzas, carrito, pedidos, pricing, delivery, reservas y reacción a
eventos públicos de pago. También será propietario de sus bloques dinámicos y sus
superficies administrativas.

No contiene composición Bonasera, identidad visual global ni el ciclo de vida interno
de pagos. No usa WooCommerce. Los consumidores nunca leen tablas, post meta o clases
internas de otro paquete.

## Estado de implementación

| Superficie | Issue propietario | Estado después de REST-02L |
| --- | --- | --- |
| Versiones, autoload, dependencias y hook de carga | REST-02B | Implementado |
| Capabilities, migraciones e instalación | REST-02C | Implementado |
| Menú estructurado | REST-02D | Implementado |
| Ingredientes y opciones de pizza | REST-02E | Implementado |
| Pricing de pizzas | REST-02F | Implementado |
| Totales, descuentos y delivery | REST-02G | Implementado |
| Carrito seguro y mutaciones | REST-02H | Implementado |
| Checkout, pedidos y estado operativo | REST-02I | Implementado |
| Integración con pagos | REST-02J | Implementado |
| Reservas | REST-02K | Implementado |
| Pizzas guardadas | REST-02L | Implementado |
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
rol administrador. REST-02L eleva el schema a versión `9`: conserva
`${prefix}vicu_rest_migrations`, un ledger InnoDB; mantiene la revisión del menú e
incorpora tablas InnoDB vacías para ingredientes, relaciones, opciones de pizza, zonas
de entrega, descuentos, sesiones, carritos, idempotencia, pedidos, líneas de pedido,
eventos y evidencia manual privada, y añade autoridades vacías para reservas,
ocupación agregada por intervalo UTC, eventos de reserva y pizzas guardadas de cuenta.
Añade al pedido únicamente el estado observado, revisión, proveedor, salud y fecha de
reconciliación. Inicializa las revisiones de disponibilidad y pricing en `1`, sin
crear contenido, solicitudes, evidencias, pedidos, pizzas guardadas ni datos de
demostración. No instala horarios Bonasera ni reservas de ejemplo.

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
puede añadirse a un carrito.

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
- Tokens de pedido, reserva y sesión, claves idempotentes y datos privados no aparecen
  en URLs, logs ni respuestas públicas. Un token compartible rotado puede formar parte
  de su ruta pública, pero nunca autoriza operaciones privadas.

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

`TotalsService` implementa esta fórmula, el carrito la persiste en cada revisión y el
checkout congela el resultado en el pedido. El subtotal proviene de líneas
recalculadas por el servidor. El servicio
resuelve código y zona por ID, usa la moneda y tasas vigentes, y no acepta importes de
descuento, impuesto, propina, delivery o total del cliente.

Las tasas se guardan en puntos base. El impuesto inicial es 800 puntos base y las
opciones iniciales de propina son 0, 1000, 1500 y 2000. Los operadores pueden
cambiarlas mediante Settings API; la lista siempre debe incluir cero. No existe una
propina no nula preseleccionada en el dominio.

Los descuentos son `fixed` en unidad menor o `percent` en puntos base. Pueden exigir
subtotal mínimo, vigencia UTC, estado activo y máximo de usos. Resolver un código no
lo consume. El checkout consume el uso bajo bloqueo `SELECT ... FOR UPDATE`; la
verificación y el incremento ocurren en la misma transacción. Un descuento nunca
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

La creación ocurre después del commit del pedido. Un fallo deja
`payment_sync_status = error` y un código seguro, pero no revierte ni duplica el
pedido. Checkout, replay, cron o retry administrativo llaman de nuevo a `create()` con
la misma referencia y recuperan la solicitud existente. El pedido conserva el ID,
estado y revisión observados, no una copia de la máquina interna de pagos.

El vertical consume estos eventos con `payload_version = 1.0.0`:

- `vicu_pagos_comprobante_recibido`;
- `vicu_pagos_confirmado`;
- `vicu_pagos_rechazado`;
- `vicu_pagos_expirado`.

Antes de reaccionar comprueba tipo e ID externos, monto, moneda, revisión y transición.
Un evento duplicado u obsoleto no cambia el pedido. La reconciliación mediante
`PaymentRequests::get()` es obligatoria porque los hooks no constituyen entrega
garantizada.

`PaymentIntegration` escucha esos cuatro hooks y agenda
`vicu_restaurante_reconcile_payments` cada hora. La reconciliación consulta únicamente
`PaymentRequests::get()` o repite `create()`. Una revisión duplicada u obsoleta se
ignora; una confirmación perdida puede aplicar primero `pago_en_revision` y después
`confirmado` dentro de una sola transacción local. Rechazo devuelve un pedido en
revisión a `pendiente_pago`; expiración solo opera desde estados de pago abiertos.
Una confirmación incompatible con un pedido terminal crea
`vicu_restaurante_payment_attention` y nunca inventa un arco.

Monto, moneda o ID de solicitud incompatibles dejan
`vicu_restaurante_payment_mismatch` como alerta persistente, sin registrar un evento
ni cambiar el estado. El CPT de pagos, sus tablas y sus metadatos nunca se consultan.

## Reservas v1

La configuración autoritativa vive en el vertical y usa una zona horaria IANA exacta.
Define periodos semanales por día, excepciones únicas por fecha, cierres recurrentes
`MM-DD`, intervalo de slots, duración, capacidad, tamaño mínimo y máximo del grupo,
aviso mínimo, umbral de capacidad limitada y confirmación automática. Los defaults no
incluyen periodos de apertura ni contenido Bonasera. Un cambio confirmado aumenta una
revisión global de configuración.

La disponibilidad convierte cada periodo local a UTC y solo publica inicios cuyo rango
completo cabe antes del cierre. Cada slot considera todos los intervalos que cruza la
duración, no solo su hora de inicio. Su estado es `available`, `limited` o
`unavailable` según la menor capacidad restante del rango. Cierres, excepciones,
límites de grupo y aviso mínimo se aplican en servidor. La respuesta usa
`Cache-Control: no-store` porque una lectura no reserva capacidad.

Crear una reserva exige `Idempotency-Key` de 16 a 191 bytes. El servidor normaliza
fecha, hora, grupo y contacto, revalida el slot, crea las filas de ocupación ausentes,
las bloquea cronológicamente con `SELECT ... FOR UPDATE`, vuelve a comprobar la
revisión de configuración y aplica incrementos condicionados por capacidad. Reserva,
ocupación, evento inicial y resultado idempotente se confirman en una sola
transacción. Dos solicitudes por los últimos puestos no pueden confirmarse ambas.

La reserva congela fecha y hora locales, zona IANA, inicio y fin UTC, tamaño del
intervalo y grupo. Su UUID público y código breve no autorizan acceso. Una reserva
invitada devuelve un token opaco de 64 caracteres solo al crearla o repetir exactamente
la misma operación; la base conserva únicamente su hash. Una reserva de cuenta solo es
legible por ese usuario. Las respuestas públicas nunca incluyen nombre, teléfono,
correo, notas, preferencia de zona, IDs internos ni eventos administrativos.

Los estados estables son `pendiente`, `confirmada`, `completada`, `cancelada` y
`no_asistio`. Pendiente puede pasar a confirmada o cancelada. Confirmada puede pasar a
completada, cancelada o no asistió. Los tres destinos son terminales y no se reabren.
Pendiente y confirmada consumen capacidad. Toda salida hacia un terminal bloquea los
mismos intervalos congelados, resta el grupo una sola vez, incrementa la revisión y
añade un evento dentro de la misma transacción. Repetir una cancelación ya aplicada
devuelve su estado actual sin otro evento ni otra liberación.

Las rutas implementadas son:

| Método y ruta | Contrato |
| --- | --- |
| `GET /reservations/availability` | Fecha y grupo; slots no cacheables |
| `POST /reservations` | Creación idempotente pública o de cuenta |
| `GET /reservations/{public_id}` | Cuenta propietaria o `X-Vicu-Reservation-Token` |
| `POST /reservations/{public_id}/cancel` | Ownership y `expected_revision` |

Las cuentas autenticadas requieren `X-WP-Nonce`. Los filtros
`vicu_restaurante_allow_reservation_availability` y
`vicu_restaurante_allow_reservation_creation` permiten conectar rate limiting o
antifraude sin incorporar una política global al dominio. Ownership fallido responde
como recurso ausente para no ofrecer un oráculo de UUID.

El tab de ajustes y el CPT privado `vicu_reservation` requieren
`manage_vicu_restaurant_reservations`. La proyección sirve únicamente para listado y
detalle: no admite creación, quick edit ni borrado, y se reconstruye desde las tablas
autoritativas. Datos privados, eventos y transiciones operativas se muestran solo tras
capability y nonce. FSE, posts y metadatos proyectados nunca participan en horarios,
capacidad, ownership o transiciones.

## Pizzas guardadas v1

Una pizza guardada pertenece exactamente a una cuenta WordPress. La autoridad es una
tabla InnoDB propia con UUID público, `user_id`, nombre privado de hasta 100 caracteres,
versión y JSON de configuración normalizada, revisión monotónica y fechas UTC. La
configuración usa `pizza_configuration` versión 1 y no persiste importes, moneda,
nombres de catálogo ni resultados del quote. El límite operativo es 100 pizzas por
usuario.

Crear o reemplazar una configuración ejecuta `PizzaPricingService::quote()` antes de
escribir. Por ello rechaza versiones desconocidas, revisiones antiguas de catálogo,
selecciones incompletas o referencias no disponibles. Renombrar, reemplazar, eliminar
o rotar un enlace exige `expected_revision`; una revisión obsoleta no aplica cambios y
devuelve `vicu_restaurante_stale_revision`. El UUID se busca siempre dentro del
`user_id` actual y un fallo de ownership responde como recurso ausente.

Las rutas privadas implementadas son:

| Método y ruta | Contrato |
| --- | --- |
| `GET /saved-pizzas` | Lista únicamente las pizzas de la cuenta actual |
| `POST /saved-pizzas` | Guarda nombre y configuración validada |
| `PATCH /saved-pizzas/{public_id}` | Renombra, reemplaza o combina ambos cambios con CAS |
| `DELETE /saved-pizzas/{public_id}` | Elimina con ownership y CAS |
| `POST /saved-pizzas/{public_id}/share` | Rota y entrega una credencial compartible una sola vez |

Todas requieren una cuenta autenticada y `X-WP-Nonce`. Sus respuestas incluyen
`Cache-Control: no-store, max-age=0` y no exponen `user_id`, IDs internos ni el hash
del token. El nombre sí forma parte de la respuesta privada del propietario.

Rotar un enlace crea 256 bits aleatorios codificados como 43 caracteres URL-safe,
invalida inmediatamente la credencial anterior e incrementa la revisión. La base
conserva solo un HMAC SHA-256 con separación de propósito. El token nuevo se devuelve
una sola vez junto con su ruta y nunca puede autorizar listado, edición, renombrado,
borrado o una nueva rotación.

`GET /saved-pizzas/shared/{token}` es público, no enumerable y no cacheable. Su
respuesta excluye nombre, propietario, UUID de la pizza y fechas. Incluye únicamente
`share_version = 1`, la configuración revalidada y un `authoritative_quote` calculado
contra la revisión, disponibilidad, importes y moneda vigentes. El token no contiene
precios ni convierte el snapshot guardado en autoridad. Si una opción deja de estar
disponible, la lectura falla cerrada. El filtro
`vicu_restaurante_allow_shared_pizza` permite conectar rate limiting y devuelve HTTP
429 al denegar.

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
| Cuenta | CRUD propio y rotación bajo `/saved-pizzas` |
| Pizza compartida | Lectura recotizada bajo `/saved-pizzas/shared/{token}` |

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
no tienen endpoint público separado: se aplican dentro del carrito para no crear un
oráculo enumerable de códigos.

### Carrito implementado

`POST /carts` crea o recupera un carrito activo. Una sesión anónima usa una credencial
opaca de 256 bits en una cookie `HttpOnly`, `SameSite=Lax`, host-only y `Secure` cuando
el sitio usa HTTPS. La base solo conserva hashes con separación de propósito. La
respuesta privada entrega el token `csrf_token` ligado a esa sesión, nunca el secreto
de cookie. El filtro `vicu_restaurante_allow_cart_creation` permite conectar rate
limiting y devuelve HTTP 429 al denegar.

Un usuario autenticado usa cookie WordPress y `X-WP-Nonce`. Si llega con un carrito
anónimo y todavía no posee otro activo, el carrito se asocia sin copiar líneas y la
sesión se rota e invalida. Si ya posee otro carrito, no se mezclan silenciosamente.

Las rutas operativas son:

| Método y ruta | Operación |
| --- | --- |
| `GET /cart` | Leer el carrito y sus revisiones |
| `POST /cart/items` | Añadir una línea validada |
| `PATCH /cart/items/{line_id}` | Sustituir una línea completa de forma atómica |
| `DELETE /cart/items/{line_id}` | Retirar una línea |
| `PUT /cart/discount` | Aplicar un código sin consumir usos |
| `DELETE /cart/discount` | Retirar el código |
| `PUT /cart/fulfillment` | Elegir pickup o delivery y zona activa |
| `PUT /cart/tip` | Elegir una tasa configurada |

Toda mutación requiere `expected_revision`. Un valor obsoleto devuelve
`vicu_restaurante_stale_revision`, HTTP 409, `current_revision` y `retryable = true`;
la transacción no altera líneas ni totales. Para sesiones anónimas exige además
`X-Vicu-CSRF` y que `Origin` o `Referer` coincida exactamente en esquema, host y puerto
con `home_url`. El UUID de línea nunca sustituye la comprobación de ownership.

Una línea de menú solo se fusiona con otra cuando item, opciones normalizadas,
ingredientes retirados, nota normalizada y precio autoritativo coinciden. Las pizzas
personalizadas nunca se fusionan. Sustituir una línea cotiza primero y conserva la
original si alguna selección falla. Tras cada mutación se recalculan todas las líneas,
descuento, impuesto, propina, delivery y total contra las revisiones vivas. Los campos
de precio o total del request se ignoran.

La respuesta contiene UUID del carrito, estado, revisión, revisiones de catálogo,
disponibilidad y pricing, líneas, fulfillment, propina, totales y vencimiento. No
incluye IDs internos, hash de sesión ni propietario. Todas las lecturas y escrituras
responden con `Cache-Control: no-store, max-age=0`, `Vary: Cookie, X-WP-Nonce` y un
`ETag` ligado al UUID y la revisión.

La vigencia predeterminada es 72 horas y puede configurarse entre 1 y 720. La tarea
horaria `vicu_restaurante_expire_carts` cambia carritos vencidos a `expired` y libera
su clave de ownership; no borra líneas ni afecta pedidos existentes.

### Checkout y pedidos implementados

`POST /orders` exige la identidad y las protecciones del carrito, el header
`Idempotency-Key` y `expected_revision`. Acepta nombre y teléfono, correo opcional,
dirección obligatoria solo para delivery, instrucciones y nota. El servidor bloquea el
carrito, revalida catálogo, disponibilidad, descuento y totales, exige un total
positivo y ejecuta en una transacción el consumo del descuento, los snapshots del
pedido, el evento inicial y la conversión del carrito.

La clave idempotente se conserva únicamente como hash junto con un fingerprint del
request normalizado. Repetir la misma operación devuelve el mismo pedido; reutilizar
la clave con otro payload devuelve `vicu_restaurante_idempotency_collision`. La
respuesta de creación de un pedido invitado incluye una única credencial opaca
derivada de forma estable para permitir replays sin persistirla en texto plano.

`GET /orders/{public_id}` exige ownership por usuario autenticado o el header
`X-Vicu-Order-Token`. Responde con UUID, número, estado, revisión, fulfillment,
moneda, snapshots de líneas, totales congelados, vencimiento de pago, estado de
sincronización y fechas. No publica contacto, dirección, token, IDs internos ni
historial administrativo. Las respuestas usan `Cache-Control: no-store`, `ETag` por
pedido y revisión, y `Vary: Cookie, X-WP-Nonce, X-Vicu-Order-Token`.

`POST /admin/orders/{public_id}/transition` exige sesión WordPress, nonce, capability,
`expected_revision`, destino y motivo cuando corresponda. Cada arco válido usa
compare-and-swap, incrementa la revisión exactamente una vez y añade un evento
append-only. `PaymentIntegration` es la única integración autorizada para los arcos de
pago.

La respuesta privada del pedido incluye `payment`: proveedor `manual`, disponibilidad
real del proveedor, instrucciones editoriales del sitio, estado y revisión observados.
No expone `payment_request_id`. Si sincronización o evidencia falla, el pedido sigue
consultable con `payment_sync_status = error`; wp-admin muestra el código seguro y
permite reconciliar con `reconcile_vicu_restaurant_payments`.

`POST /orders/{public_id}/payment-evidence` exige ownership por cuenta o
`X-Vicu-Order-Token`, `Idempotency-Key` de 16 a 191 bytes y una `reference` textual de
1 a 191 bytes. Persiste el texto solo en la tabla privada y entrega a pagos
`vicu-order-evidence:{uuid}` con una clave estable derivada del UUID. Repetir clave y
texto devuelve la misma evidencia; otro texto colisiona. La respuesta 201 contiene
solo UUID, `submitted`, fecha y pedido actualizado, con `Cache-Control: no-store`.
Archivos y referencias en URLs quedan fuera de v1.

Las tablas de dominio son la autoridad. El CPT privado `vicu_order` es una proyección
reconstruible para listado y detalle administrativo: no admite creación, edición
editorial, borrado ni quick edit. El panel muestra datos privados solo a
`view_vicu_restaurant_orders`, separa operar, cancelar y reconstruir mediante sus
capabilities y nonces, y registra motivos sin exponer secretos.

La salud administrativa muestra proveedor habilitado, sincronizaciones con error,
retry individual y reconciliación acotada. Solo
`view_vicu_restaurant_payment_evidence` revela el texto privado; operar pedidos,
reconstruir proyecciones y reconciliar pagos conservan capabilities separadas.

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
- `vicu_restaurante_payment_attention`;
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
