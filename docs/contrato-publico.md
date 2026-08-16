# Contrato público de `vicunav-restaurante`

Estado: contrato 1.0.0 aprobado; REST-02B implementa únicamente carga, compatibilidad
y publicación de disponibilidad. Las superficies de dominio se habilitan por los
issues indicados en la matriz, sin considerarse operativas antes de ellos.

## Responsabilidad y límites

El plugin es propietario del menú estructurado, ingredientes, disponibilidad,
configuración de pizzas, carrito, pedidos, pricing, delivery, reservas y reacción a
eventos públicos de pago. También será propietario de sus bloques dinámicos y sus
superficies administrativas.

No contiene composición Bonasera, identidad visual global ni el ciclo de vida interno
de pagos. No usa WooCommerce. Los consumidores nunca leen tablas, post meta o clases
internas de otro paquete.

## Estado de implementación

| Superficie | Issue propietario | Estado después de REST-02B |
| --- | --- | --- |
| Versiones, autoload, dependencias y hook de carga | REST-02B | Implementado |
| Capabilities, migraciones e instalación | REST-02C | Planificado |
| Menú e ingredientes | REST-02D y REST-02E | Planificado |
| Pricing de pizzas y totales | REST-02F y REST-02G | Planificado |
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
