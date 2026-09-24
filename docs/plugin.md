# Plugin `vicunav-restaurante`

Superficie pública y reglas de negocio del plugin. Requiere WordPress 6.6+ y PHP 8.1+;
no usa WooCommerce. La estructura interna está en [`arquitectura.md`](arquitectura.md).

## Principios

- Todo importe es un entero en unidad menor con moneda ISO 4217; todo porcentaje
  persistido usa puntos base.
- El servidor es la única autoridad de precio, disponibilidad, capacidad y estado. El
  cliente nunca envía importes como autoridad: se ignoran.
- Los IDs públicos son UUID v4 opacos y no enumerables; un ID interno nunca autoriza.
- Los recursos mutables publican una `revision` monotónica y se escriben con
  compare-and-swap (`expected_revision`).
- Pedidos y reservas conservan snapshots históricos.
- Tokens de pedido, reserva y sesión, claves idempotentes y datos privados no aparecen
  en URLs, logs, HTML cacheable ni respuestas públicas. Un token compartible de pizza
  puede formar parte de su ruta pública, pero no autoriza operaciones privadas.
- La persistencia física no es contrato.

## Capabilities

Se conceden solo al rol administrador durante la activación (después de las
migraciones): `manage_vicu_restaurant_catalog`, `manage_vicu_restaurant_availability`,
`manage_vicu_restaurant_discounts`, `manage_vicu_restaurant_delivery`,
`view_vicu_restaurant_orders`, `manage_vicu_restaurant_orders`,
`fulfill_vicu_restaurant_orders`, `view_vicu_restaurant_payment_evidence`,
`manage_vicu_restaurant_reservations`, `manage_vicu_restaurant_settings` y
`reconcile_vicu_restaurant_payments`. El módulo de pagos concede además las
capabilities primitivas de su CPT privado. Una capability autoriza una categoría de
operación; cada escritura verifica además nonce, ownership y revisión cuando
corresponden.

## Menú estructurado

`vicu_menu_item` es un CPT público administrado bajo **Vicunav**: título, extracto,
contenido, imagen destacada y `menu_order` aportan copy, historia, media y orden. La
taxonomía jerárquica `vicu_menu_category` aporta slug, nombre, descripción, orden y
visibilidad; cada item tiene una sola categoría. El controlador genérico
`wp/v2/restaurant-menu-items` exige `manage_vicu_restaurant_catalog` incluso para
lecturas; los clientes públicos usan solo `/vicu/v1/restaurante/menu`.

| Campo | Regla |
| --- | --- |
| `public_id` | UUID v4 inmutable |
| `price_minor` / `currency` | Entero no negativo / tres letras mayúsculas |
| `available` | `false` impide selecciones nuevas |
| `calories_kcal` | Entero no negativo, informativo |
| `allergens` | `celery`, `crustaceans`, `eggs`, `fish`, `gluten`, `lupin`, `milk`, `molluscs`, `mustard`, `nuts`, `peanuts`, `sesame`, `soy`, `sulphites` |
| `dietary_tags` | `spicy`, `vegan`, `vegetarian` |

Un item solo se publica si está publicado, tiene título, ID, precio, moneda y
disponibilidad, y pertenece a exactamente una categoría visible (falla cerrada). Un
item completo con `available = false` sí aparece, con estado agotado, pero no puede
añadirse al carrito. La información de alérgenos nunca elimina el riesgo de
contaminación cruzada. La revisión global `vicu_restaurante_menu_revision` invalida
la object cache y forma parte del `ETag`.

## Ingredientes, opciones de pizza y disponibilidad

Un ingrediente tiene nombre, categoría (`base`, `cheese`, `extra`, `topping`),
modificador de precio con signo, disponibilidad, alérgenos, etiquetas dietarias y
revisión de fila. Una opción de pizza tiene nombre, tipo (`size`, `crust`, `sauce`),
modificador con signo, disponibilidad, orden y revisión. Las relaciones entre un
`vicu_menu_item` y sus ingredientes usan el rol `required`, `removable` u `optional`,
orden y sustitución explícita opcional (nunca inferida); el reemplazo del conjunto es
transaccional.

Ingredientes y opciones comparten `vicu_restaurante_availability_revision` (inicia en
1; +1 por creación o actualización confirmada). Una revisión esperada obsoleta devuelve
`vicu_restaurante_stale_revision` (HTTP 409, `current_revision`, `retryable = true`) sin
cambios parciales. No existe borrado operativo: un recurso se desactiva para conservar
las referencias históricas.

### Pantallas de administración

Todas bajo el menú **Vicunav**. Nada del constructor de pizzas está hardcodeado: el
bloque solo proyecta lo que el catálogo publica como disponible.

| Pantalla | Slug | Capability | Administra |
| --- | --- | --- | --- |
| Ingredientes | `vicu-restaurante-ingredients` | `manage_vicu_restaurant_catalog` | Alta y edición de ingredientes de todas las categorías |
| Opciones de pizza | `vicu-restaurante-pizza-options` | `manage_vicu_restaurant_catalog` | Tamaños, masas y salsas |
| Disponibilidad | `vicu-restaurante-availability` | `manage_vicu_restaurant_availability` | Alternar disponibilidad sin tocar estructura |

Para cambiar el constructor: tamaño, masa o salsa se editan en Opciones de pizza;
queso (`cheese`) y toppings (`topping`) en Ingredientes; agotar algo existente en
Disponibilidad. Las categorías `base` y `extra` no alimentan el constructor; se usan
en las relaciones ingrediente-por-plato (Menú, relaciones de ingredientes). El precio
final lo calcula siempre `PizzaPricingService`; zonas de entrega y descuentos tienen su
propia pantalla (Comercio). Pedidos y Reservas también son pantallas propias.

## Pricing y totales

```text
subtotal        = suma(precio_unitario_autoritativo * cantidad + ajustes_de_linea)
discount_total  = suma(descuentos_validos), limitada a subtotal
net_merchandise = subtotal - discount_total
tax_total       = round_half_up(net_merchandise * tax_rate_bps / 10000)
tip_total       = round_half_up(net_merchandise * tip_rate_bps / 10000)
delivery_total  = tarifa vigente de la zona, o 0 para pickup
total           = net_merchandise + tax_total + tip_total + delivery_total
```

Delivery y propina no forman parte de la base fiscal. El pedido congela el desglose y
la solicitud de pago usa exactamente su `total` y `currency` (debe ser positivo).
`TotalsService` resuelve código y zona por ID con la moneda y tasas vigentes.

Ajustes propios (`vicu_restaurante_settings`, pestaña Restaurante, Settings API):
`currency` (inicial `USD`), `tax_rate_bps` (800), `tip_rates_bps` (0, 1000, 1500,
2000; siempre incluye cero, sin propina no nula preseleccionada), `cart_lifetime_hours`
(72, rango 1 a 720), `payment_lifetime_minutes` y `manual_payment_instructions`.

Descuentos: `fixed` (unidad menor) o `percent` (puntos base), con subtotal mínimo,
vigencia UTC, estado activo y máximo de usos. Resolver un código no lo consume; el
checkout lo consume bajo `SELECT ... FOR UPDATE` en la misma transacción. Nunca
reducen `net_merchandise` por debajo de cero. No tienen endpoint público separado
(evita un oráculo de códigos).

Zonas de entrega: se eligen por UUID y congelan nombre, tarifa y ETA; no hay geocoding
ni texto de dirección como autoridad. Pickup exige tarifa cero y ninguna zona; una
zona inactiva devuelve `vicu_restaurante_unavailable`. Cambiar zonas, descuentos,
tasas, propinas o moneda incrementa `vicu_restaurante_pricing_revision`.

## Pizzas: configuración y quote

`POST /pizza/quote` recibe `configuration`:

```json
{
  "version": 1,
  "catalog_revision": 7,
  "size_id": "<uuid>", "crust_id": "<uuid>", "sauce_id": "<uuid>",
  "cheese_ingredient_id": "<uuid>",
  "toppings": { "<uuid>": "left" },
  "quantity": 1
}
```

Tamaño, masa, salsa y queso son obligatorios; los toppings son un mapa por UUID con
zona exclusiva `whole`, `left` o `right`, máximo seis en total. Versión desconocida,
zona inválida o cantidad no entera: `vicu_restaurante_invalid_request`; revisión
antigua: `vicu_restaurante_stale_revision`; referencia ausente, agotada o de tipo
incorrecto: `vicu_restaurante_unavailable`. No se completan defaults.

El tamaño fija el precio base; masa, queso y cada topping suman su modificador; la
salsa aporta cero. Cada topping se cobra una vez a precio completo, también en `left` o
`right`. La respuesta incluye configuración normalizada, revisión, moneda,
componentes, `unit_total_minor`, cantidad y `total_minor`. La ruta es pública, responde
`no-store` y expone el filtro `vicu_restaurante_allow_public_quote` (429
`vicu_restaurante_rate_limited` al denegar). Un quote no reserva inventario ni crea
carrito; se revalida en cada mutación posterior.

## Carrito

`POST /carts` crea o recupera el carrito activo. Anónimo: credencial opaca de 256 bits
en cookie `HttpOnly`, `SameSite=Lax`, host-only (y `Secure` en HTTPS); la base guarda
solo hashes. La respuesta entrega `csrf_token` ligado a la sesión, nunca el secreto.
Autenticado: cookie de WordPress y `X-WP-Nonce`; un carrito anónimo se asocia sin
copiar líneas (y rota la sesión) solo si el usuario no tiene otro activo; nunca se
mezclan dos carritos. Filtro `vicu_restaurante_allow_cart_creation`.

| Ruta | Operación |
| --- | --- |
| `GET /cart` | Leer carrito y revisiones |
| `POST /cart/items` | Añadir línea validada |
| `PATCH /cart/items/{line_id}` | Sustituir una línea completa de forma atómica |
| `DELETE /cart/items/{line_id}` | Retirar línea |
| `PUT` / `DELETE /cart/discount` | Aplicar (sin consumir usos) o retirar código |
| `PUT /cart/fulfillment` | Pickup o delivery y zona activa |
| `PUT /cart/tip` | Tasa configurada |

Toda mutación exige `expected_revision`; una obsoleta devuelve 409 sin alterar líneas
ni totales. Las sesiones anónimas exigen además `X-Vicu-CSRF` y `Origin`/`Referer`
idéntico a `home_url` (esquema, host y puerto). Líneas de menú se fusionan solo si
item, opciones, ingredientes retirados, nota y precio coinciden; las pizzas nunca se
fusionan. Tras cada mutación se recalcula todo contra las revisiones vivas. Respuestas
`Cache-Control: no-store, max-age=0`, `Vary: Cookie, X-WP-Nonce`, `ETag` por UUID y
revisión; sin IDs internos ni hashes. La tarea horaria `vicu_restaurante_expire_carts`
marca vencidos como `expired` y libera el ownership sin borrar líneas.

## Pedidos

`POST /orders` exige la identidad del carrito, `Idempotency-Key` y `expected_revision`.
Acepta nombre y teléfono, correo opcional, dirección (obligatoria solo en delivery),
instrucciones y nota. En una sola transacción bloquea y revalida el carrito, exige
total positivo, consume el descuento, congela snapshots, añade el evento inicial y
convierte el carrito exactamente una vez. La clave se guarda solo como hash con un
fingerprint del request: repetir la operación devuelve el mismo pedido; otra carga con
la misma clave devuelve `vicu_restaurante_idempotency_collision`. Un pedido invitado
recibe una credencial opaca derivada de forma estable (sin persistirla en claro).

`GET /orders/{public_id}` exige ownership de cuenta o `X-Vicu-Order-Token`. Devuelve
UUID, número, estado, revisión, fulfillment, moneda, líneas y totales congelados,
vencimiento de pago, estado de sincronización y fechas; nunca contacto, dirección,
token ni historial administrativo. `no-store`, `ETag` por pedido y revisión.

### Estados

Valores: `pendiente_pago`, `pago_en_revision`, `confirmado`, `en_preparacion`, `listo`,
`en_reparto`, `completado`, `cancelado`, `expirado`.

| Origen | Destinos |
| --- | --- |
| creación | `pendiente_pago` |
| `pendiente_pago` | `pago_en_revision`, `cancelado`, `expirado` |
| `pago_en_revision` | `pendiente_pago`, `confirmado`, `cancelado`, `expirado` |
| `confirmado` | `en_preparacion`, `cancelado` (operador autorizado) |
| `en_preparacion` | `listo`, `cancelado` (operador autorizado) |
| `listo` | `completado` (pickup), `en_reparto` (delivery) |
| `en_reparto` | `completado` |
| terminales | ninguno |

Cada transición válida usa compare-and-swap, incrementa la revisión una vez y añade un
evento append-only. No hay devoluciones automáticas tras un pago confirmado.
`POST /admin/orders/{public_id}/transition` exige sesión, nonce, capability,
`expected_revision`, destino y motivo cuando corresponda; `PaymentIntegration` es la
única integración autorizada para los arcos de pago.

### Administración de pedidos

Las tablas de dominio son la autoridad. El CPT privado `vicu_order` es una proyección
reconstruible (sin creación, edición editorial, borrado ni quick edit). El panel
muestra datos privados solo con `view_vicu_restaurant_orders`; operar, cancelar,
reconstruir proyecciones, reconciliar pagos y ver evidencia usan capabilities
separadas, con nonce. La salud muestra proveedor habilitado, sincronizaciones con
error, retry individual y reconciliación acotada.

## Pagos manuales

El módulo `Payments` ofrece `PaymentRequests` (`create`, `get`, `transition`, `expire`,
`expire_due`) y `ManualPaymentProvider` (`configure`, `submit_proof`, `get_submission`).
Una solicitud se identifica por `external_type` + `external_id` (hash idempotente) y
recorre `pendiente`, `comprobante_subido`, `confirmado`, `rechazado` y `expirado`; un
CPT privado (`vicu_payment_req`) y tablas propias la persisten, y una tarea
(`vicu_pagos_expire_requests`) expira las vencidas. Emite `vicu_pagos_creado`,
`vicu_pagos_comprobante_recibido`, `vicu_pagos_confirmado`, `vicu_pagos_rechazado` y
`vicu_pagos_expirado`.

Integración del pedido (`PaymentIntegration`):

- `external_type = vicu_order`, `external_id` = UUID público; monto, moneda y
  vencimiento congelados. La creación ocurre tras el commit del pedido; un fallo deja
  `payment_sync_status = error` con un código seguro sin revertir ni duplicar el
  pedido. Checkout, replay, cron o retry administrativo repiten `create()` con la misma
  referencia y recuperan la solicitud.
- El pedido guarda ID, estado y revisión observados de la solicitud, no una copia de su
  máquina de estados. La respuesta privada incluye `payment`: proveedor `manual`,
  disponibilidad, instrucciones editoriales y estado; no expone el ID de solicitud.
- Antes de reaccionar a los hooks se validan tipo e ID externos, monto, moneda,
  revisión y transición; un evento duplicado u obsoleto no cambia nada. Como los hooks
  no garantizan entrega, `vicu_restaurante_reconcile_payments` (cada hora) consulta
  `PaymentRequests::get()` o repite `create()`. Una confirmación perdida puede aplicar
  `pago_en_revision` y luego `confirmado` en una sola transacción local; un rechazo
  devuelve el pedido a `pendiente_pago`; la expiración solo opera desde estados de pago
  abiertos.
- Una confirmación incompatible con un pedido terminal crea
  `vicu_restaurante_payment_attention` y no inventa un arco. Monto, moneda o ID
  incompatibles dejan `vicu_restaurante_payment_mismatch` como alerta persistente sin
  evento ni cambio de estado.
- `POST /orders/{public_id}/payment-evidence` exige ownership o token,
  `Idempotency-Key` (16 a 191 bytes) y `reference` textual (1 a 191 bytes). El texto se
  guarda solo en una tabla privada; el proveedor recibe `vicu-order-evidence:{uuid}`
  con clave estable. Repetir clave y texto devuelve la misma evidencia; otro texto
  colisiona. Respuesta 201 con UUID, `submitted`, fecha y pedido actualizado, `no-store`.
  No hay archivos ni referencias en URLs.

## Reservas

Configuración autoritativa propia con zona horaria IANA: periodos semanales,
excepciones por fecha, cierres recurrentes `MM-DD`, intervalo de slots, duración,
capacidad, tamaño mínimo y máximo de grupo, aviso mínimo, umbral de capacidad limitada
y confirmación automática. Por defecto no hay periodos de apertura. Un cambio confirmado
aumenta una revisión global de configuración.

La disponibilidad convierte los periodos locales a UTC y solo publica inicios cuyo
rango completo cabe antes del cierre; cada slot considera todos los intervalos que
cruza y es `available`, `limited` o `unavailable` según la menor capacidad restante.
Crear exige `Idempotency-Key` (16 a 191 bytes): normaliza, revalida el slot, crea las
filas de ocupación ausentes, las bloquea cronológicamente con `SELECT ... FOR UPDATE`,
recomprueba la revisión y aplica incrementos condicionados por capacidad, todo en una
transacción con evento inicial y resultado idempotente. La reserva congela fecha y
hora locales, zona, inicio y fin UTC, intervalo y grupo. UUID y código breve no
autorizan acceso; una reserva invitada devuelve un token de 64 caracteres solo al
crearla (o repetir exactamente la operación) y se guarda su hash; una de cuenta solo la
lee su usuario. Las respuestas públicas nunca incluyen nombre, teléfono, correo,
notas, preferencia de zona, IDs internos ni eventos.

Estados: `pendiente`, `confirmada`, `completada`, `cancelada`, `no_asistio`. Pendiente
pasa a confirmada o cancelada; confirmada a completada, cancelada o no asistió; los
tres destinos son terminales. Pendiente y confirmada consumen capacidad; toda salida a
un terminal bloquea los mismos intervalos, resta el grupo una sola vez, incrementa la
revisión y añade un evento en la misma transacción (repetir una cancelación no libera
dos veces).

| Ruta | Contrato |
| --- | --- |
| `GET /reservations/availability` | Fecha y grupo; `no-store` |
| `POST /reservations` | Creación idempotente, pública o de cuenta |
| `GET /reservations/{public_id}` | Cuenta propietaria o `X-Vicu-Reservation-Token` |
| `POST /reservations/{public_id}/cancel` | Ownership y `expected_revision` |

Las cuentas exigen `X-WP-Nonce`. Filtros de rate limiting:
`vicu_restaurante_allow_reservation_availability` y
`vicu_restaurante_allow_reservation_creation`. Un ownership fallido responde como
recurso ausente. La pestaña de ajustes Reservas y el CPT privado `vicu_reservation`
(proyección reconstruible, sin creación ni borrado) requieren
`manage_vicu_restaurant_reservations`.

## Pizzas guardadas

Pertenecen a exactamente una cuenta. Tabla InnoDB propia: UUID, `user_id`, nombre
privado (máx. 100 caracteres), versión, JSON de `pizza_configuration` normalizada,
revisión y fechas UTC; sin importes, moneda, nombres de catálogo ni resultados de
quote. Límite: 100 por usuario. Crear o reemplazar ejecuta `PizzaPricingService::quote()`
antes de escribir. El UUID se busca siempre dentro del `user_id` actual y un fallo de
ownership responde como recurso ausente.

| Ruta | Contrato |
| --- | --- |
| `GET /saved-pizzas` | Lista las pizzas de la cuenta |
| `POST /saved-pizzas` | Guarda nombre y configuración validada |
| `PATCH /saved-pizzas/{public_id}` | Renombra y/o reemplaza con CAS |
| `DELETE /saved-pizzas/{public_id}` | Elimina con ownership y CAS |
| `POST /saved-pizzas/{public_id}/share` | Rota y entrega una credencial compartible una vez |

Requieren cuenta y `X-WP-Nonce`; `no-store`; sin `user_id`, IDs internos ni hash. Rotar
crea 256 bits (43 caracteres URL-safe), invalida la credencial anterior e incrementa la
revisión; se guarda solo un HMAC SHA-256 con separación de propósito. El token nunca
autoriza listado, edición, borrado ni nueva rotación. `GET /saved-pizzas/shared/{token}`
es público, no enumerable y no cacheable: excluye nombre, propietario, UUID y fechas, e
incluye `share_version = 1`, la configuración revalidada y un `authoritative_quote`
contra el catálogo vigente (falla cerrada si algo dejó de estar disponible). Filtro
`vicu_restaurante_allow_shared_pizza`.

## REST `vicu/v1`

Base: `/wp-json/vicu/v1/restaurante`. Las rutas se registran con
`Shared\Rest::register_route()`, con schema y `permission_callback` explícitos.

| Grupo | Rutas |
| --- | --- |
| Catálogo | `GET /menu`, `GET /menu/{public_id}`, `GET /ingredients/availability` |
| Pizzas | `GET /pizza/options`, `POST /pizza/quote` |
| Delivery | `GET /delivery-zones` |
| Carrito | `POST /carts`, `GET /cart`, mutaciones bajo `/cart` |
| Pedidos | `POST /orders`, `GET /orders/{public_id}`, `POST /orders/{public_id}/payment-evidence`, `POST /admin/orders/{public_id}/transition` |
| Reservas | bajo `/reservations` |
| Cuenta | bajo `/saved-pizzas` y `/saved-pizzas/shared/{token}` |

Lecturas públicas:

- `GET /menu` acepta `category` (slug) y devuelve `revision`, `categories[]` y
  `items[]` con `public_id`, `name`, `description`, `story`, `price_minor`, `currency`,
  `available`, `calories_kcal`, `allergens`, `dietary_tags`, `category`, `order`,
  `image`. `GET /menu/{public_id}` devuelve `revision` e `item`. Ausente o no
  publicable: `vicu_restaurante_not_found`; categoría desconocida u oculta:
  `vicu_restaurante_invalid_request`. 200 con `ETag` y
  `Cache-Control: public, max-age=60, stale-while-revalidate=300`; `If-None-Match`
  exacto devuelve 304; object cache de 300 s ligada a revisión.
- `GET /ingredients/availability`: revisión y `{ public_id, available, revision }` de
  todos los ingredientes (incluidos no disponibles); `no-cache, must-revalidate`, `ETag`.
- `GET /pizza/options`: revisión y `sizes`, `crusts`, `sauces`, `cheeses`, `toppings`
  (los no disponibles permanecen con `available = false`); cache pública de 60 s, `ETag`.
- `GET /delivery-zones`: `revision`, `currency` y zonas activas ordenadas (UUID, nombre,
  tarifa, ETA mínimo y máximo, orden, revisión); `ETag` ligado a la revisión de pricing.

Errores estables (`WP_Error`, forma REST estándar, sin éxito parcial ni datos privados):
`vicu_restaurante_invalid_request`, `_authentication_required`, `_forbidden`,
`_not_found`, `_unavailable`, `_stale_revision`, `_idempotency_collision`,
`_invalid_transition`, `_payment_mismatch`, `_payment_attention`, `_rate_limited`,
`_storage_error` y `_dependency_unavailable`.

## Bloques

Nueve bloques dinámicos (API 3, registro desde `block.json`, SSR seguro, assets
condicionales, preview de editor por render de servidor). Su markup de negocio nunca
se guarda en `post_content`. FSE puede cambiar alineación, anchor, color y espaciado
(y las URL de destino donde se indica), nunca datos, rutas, ownership, revisiones,
precios ni estados.

| Bloque | Función |
| --- | --- |
| `vicunav/restaurante-menu` | Catálogo con búsqueda y filtros (categoría, vegetariano, picante). SSR completo sin JavaScript; con JavaScript refresca `GET /menu`, anuncia resultados y conserva el SSR ante fallos de red. Agotados visibles. Sin H1. |
| `vicunav/restaurante-pizza-builder` | Constructor con Interactivity API: una opción por grupo, zonas `whole`/`left`/`right`, máximo seis toppings, cantidad uno; cotiza tras cada cambio y antes de cada alta al carrito. Sin fórmulas ni importes en JavaScript. |
| `vicunav/restaurante-cart` | Líneas, cantidades, descuento, pickup/delivery, zona y propina; totales del servidor. Atributos `menuUrl` y `checkoutUrl`. Pizzas con resumen, Duplicar y Quitar. |
| `vicunav/restaurante-checkout` | Contacto, dirección solo en delivery, clave idempotente por intento, proveedor manual real e instrucciones. Sin archivos ni métodos de pago simulados. |
| `vicunav/restaurante-order-status` | Consulta por cuenta o token invitado; estado, timeline según pickup o delivery (con `cancelado` y `expirado` como terminales), total, vencimiento y envío de la referencia de pago. |
| `vicunav/restaurante-reservations` | Fecha y grupo, slots del servidor (disponible, limitado, agotado visible pero deshabilitado), creación idempotente, confirmación, recuperación y cancelación. |
| `vicunav/restaurante-saved-pizzas` | Colección de la cuenta: renombrar, eliminar (con confirmación), compartir, añadir al carrito tras un quote real. Anónimo: enlace de login. |
| `vicunav/restaurante-header-actions` | Enlaces al carrito (contador en vivo compartido con el store de comercio) y a la cuenta; atributos `cartUrl` y `accountUrl`. Ambos son enlaces reales. |
| `vicunav/restaurante-delivery-coverage` | Comprueba una zona escrita contra `GET /delivery-zones` (informativo, sin mapas ni geocoding). |

Cart, checkout, order-status y header-actions comparten un store de Interactivity API
(`vicunav/restaurante-commerce`), un módulo y una hoja de estilos. El UUID y el token
de invitado (pedido o reserva) viven solo en memoria o `sessionStorage` y se retiran de
los objetos observables; nunca en URL, markup, logs ni `localStorage`. Un conflicto
409 recarga el estado vigente antes de permitir otra acción.

Contrato visual: los bloques consumen los presets `vicunav-*` del theme con fallbacks
neutrales (funcionan con otro theme), sin tokens `bonasera-*` ni copy de demo. Grids
fluidos, controles con target mínimo de 44 px, foco visible, regiones live/alert,
layout de una columna en móvil desde 320 px y `prefers-reduced-motion`. La suite
`plugin/tests/validate-visual-contract.mjs` lo comprueba.

Pendiente de diseño: **Editar** una pizza desde el carrito o desde Mis pizzas requeriría
que el constructor aceptara un modo de edición; hoy no existe.

## Privacidad

El plugin registra un exportador y un borrador en las herramientas nativas de WordPress.
La identidad se resuelve por correo y, si existe, por la cuenta local. El exportador
incluye pedidos, reservas, carritos y pizzas guardadas, nunca hashes, tokens, claves de
ownership ni evidencia privada de pago. El borrado elimina carritos, sesiones y pizzas
guardadas de la cuenta; pedidos y reservas terminales se conservan anonimizados
(sin contacto, dirección, instrucciones, notas, tokens ni referencias de pago, y sus
snapshots sin notas libres); los activos se retienen hasta completar su ciclo y la
herramienta informa el motivo. El frontend no incorpora tracking, recursos remotos ni
llamadas `console.*`, y el runtime no añade `error_log`.

## Caché

Catálogo, opciones y zonas: `public, max-age=60, stale-while-revalidate=300` con `ETag`
(disponibilidad de ingredientes: `no-cache, must-revalidate`). Carrito, pedidos,
reservas, quotes, pizzas guardadas y lecturas compartidas: `no-store`.

## Capacidades compartidas

- CPT `vicu_faq` y `vicu_testimonial` (`show_in_rest`), consumidos por los patterns del
  theme mediante Query Loop.
- Ajustes generales (option `vicu_core_settings`) bajo el menú **Vicunav** con pestañas
  registrables (`Settings::register_tab`): General, Restaurante y Reservas. Contacto
  compartido con fallback en los patterns.
- `Shared\Rest::register_route()` sobre el namespace `vicu/v1`.
