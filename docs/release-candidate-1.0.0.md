# Evidencia de la candidata 1.0.0

Estado: gate REST-02R aprobado localmente; la publicación queda condicionada a que la
misma matriz pase en CI sobre el commit fusionado.

## Alcance verificado

La candidata cubre únicamente el runtime propietario de `vicunav-restaurante`: menú,
constructor de pizzas, carrito, checkout manual, pedidos y estados, reservas y pizzas
guardadas. No contiene identidad Bonasera, lógica de theme, contenido demo ni dependencia
de WooCommerce. El QA visual del theme y del demo pertenece a THEME-REST y DEMO-REST.

Dependencias reales usadas en el gate:

- WordPress 6.9 en español y MySQL 8.4, dentro de una instalación desechable separada de
  LocalWP.
- `vicunav-plugin-core` en `12870b0d5e297d715c985037e76898067a749909`.
- `vicunav-pagos` 0.3.1 en `16280c3bd74977ac025f0085ccdf22ae5b995277`.
- Proveedor manual real de `vicunav-pagos`, sin dobles teatrales en el navegador.

## Matriz funcional

| Superficie | Evidencia | Resultado |
| --- | --- | --- |
| Menú | Carga, búsqueda, categoría, estados con y sin resultados | Aprobado |
| Constructor | Cotización autoritativa, opciones reales y alta al carrito | Aprobado |
| Carrito | Persistencia opaca, cantidades, delivery, propina y totales | Aprobado |
| Checkout | Pedido delivery por 13,42 USD y solicitud idempotente a pagos | Aprobado |
| Estado de pedido | Evidencia manual y transición a `pago_en_revision` | Aprobado |
| Reservas | 45 slots, creación, lectura privada y cancelación | Aprobado |
| Pizzas guardadas | Crear, listar, renombrar, compartir, recotizar y eliminar | Aprobado |

El gate detectó y corrigió tres defectos de frontera antes de aprobarse:

- La expiración del pedido ahora se transforma desde UTC interno a RFC 3339 con offset
  antes de invocar el contrato público de pagos.
- El estado privado de reservas se inicializa también cuando una acción ocurre antes del
  callback diferido de inicialización.
- Un visitante sin identidad de carrito ya no hace una lectura REST destinada a fallar
  con 401, por lo que el estado vacío no genera errores de consola.
- Los retornos que solo producen `true` o `WP_Error` usan `bool|WP_Error`: conservan el
  contrato de valores y evitan la sintaxis de tipo literal disponible desde PHP 8.2,
  incompatible con el mínimo declarado PHP 8.1.

La validación real también detectó que el proveedor manual de pagos perdía su estado al
cruzar un request de WordPress. La corrección quedó publicada en `vicunav-pagos` 0.3.1 y
cuenta con su propia regresión.

## Accesibilidad y responsive

- Un solo `h1` en el documento del fixture y encabezados internos sin duplicarlo.
- Cero IDs duplicados y cero controles sin nombre accesible en 768 px y 390 px.
- Sin overflow horizontal: el ancho de scroll coincide con el ancho útil en ambos
  viewports.
- Formularios operables con teclado, foco visible, mensajes en regiones vivas y estados
  de carga o error perceptibles.
- Las hojas de estilo respetan `prefers-reduced-motion`.
- Lighthouse: Accessibility 100.

Las correcciones responsive y accesibles del prototipo no se copian como defectos. La
comparación visual Bonasera en cinco viewports se realizará en la pista de theme y demo.

## Rendimiento

Medición Lighthouse del fixture técnico como visitante, después de vaciar la identidad
de carrito:

| Métrica | Resultado | Presupuesto |
| --- | ---: | ---: |
| Performance | 97 | >= 90 |
| Accessibility | 100 | 100 |
| Best Practices | 100 | 100 |
| CLS | 0,01783 | <= 0,1 |
| LCP | 2.255 ms | Informativo |
| TBT | 0 ms | Informativo |
| Errores de consola | 0 | 0 |

SEO obtuvo 92 en el fixture técnico. No es un gate del plugin porque títulos, metadata,
navegación y contenido editorial pertenecen al theme y al demo.

## Privacidad, caché y red

- El catálogo responde con ETag y `Cache-Control: public, max-age=60,
  stale-while-revalidate=300`.
- Carrito y checkout usan `Cache-Control: no-store, max-age=0` y `Vary: Cookie,
  X-WP-Nonce`.
- La cookie opaca de sesión usa `HttpOnly` y `SameSite=Lax`; su valor no se serializa en
  HTML ni en esta evidencia.
- Los tokens de recuperación invitados permanecen en memoria o `sessionStorage`, se
  retiran de los objetos observables y nunca usan `localStorage`.
- El frontend no incorpora tracking, recursos remotos ni llamadas `console.*`.
- El runtime no añade `error_log` ni `trigger_error`.
- El exportador nativo reúne pedidos, reservas, carritos y pizzas guardadas sin secretos.
- El borrador elimina recursos efímeros, anonimiza autoridades terminales y explica la
  retención de operaciones activas.

`npm audit --omit=dev` reporta cero vulnerabilidades de producción. La auditoría completa
de npm mantiene avisos transitivos del toolchain de desarrollo de WordPress; no se cargan
en el plugin publicado y se documentan sin falsear el gate de producción.

## Compatibilidad y repetición

CI ejecuta la siguiente matriz:

| PHP | WordPress | Resolución Composer |
| --- | --- | --- |
| 8.1 | 6.6 | Dependencias mínimas permitidas con `--prefer-lowest` |
| 8.4 | 6.9 | Lockfile versionado |

Comandos de validación local:

```bash
composer install --no-interaction --prefer-dist
npm ci
npm run check
npm audit --omit=dev

WP_TESTS_DB_NAME=wordpress_test \
WP_TESTS_DB_USER=root \
WP_TESTS_DB_PASSWORD= \
WP_TESTS_DB_HOST="${TEST_DB_HOST}" \
WP_TESTS_TABLE_PREFIX=wptests_vicu_restaurante_ \
composer check
```

Para repetir el E2E se crea un WordPress desechable, se enlazan copias limpias de core,
pagos y restaurante, se activan esos tres plugins y se ejecuta:

```bash
wp eval-file tests/e2e/seed.php
```

El script solo crea catálogo, configuración, horario y una página técnica idempotentes.
No arranca ni modifica LocalWP. Las credenciales administrativas y las identidades
efímeras del gate no se versionan ni se incluyen en la evidencia.
