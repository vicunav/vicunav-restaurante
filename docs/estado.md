# Estado del proyecto

Resumen del estado actual y del trabajo pendiente. Se actualiza en el mismo cambio que
modifica lo que describe.

## Implementado

- Plugin `vicunav-restaurante` 1.0.0: menú estructurado, catálogo de ingredientes y
  opciones de pizza, quote autoritativo, carrito, checkout idempotente, pedidos con
  máquina de estados, pago manual con reconciliación, reservas con capacidad por
  intervalo, pizzas guardadas y compartibles, privacidad y nueve bloques dinámicos
  (ver [`plugin.md`](plugin.md)).
- Theme `vicunav-bonasera` 1.0.0: tokens, cuatro parts de restaurante, diez patterns y
  fuentes autoalojadas (ver [`theme.md`](theme.md)).
- Contenido demostrativo auditado y media inventariada con licencia y hash
  ([`contenido-y-media.md`](contenido-y-media.md)).
- Instalador local idempotente y validaciones (`tests/run.sh`, `composer check`,
  `npm run check`).
- Las rutas de la composición son `/`, `/menu/`, `/pizzas/`, `/carrito/`,
  `/checkout/`, `/pedido/`, `/reservas/`, `/mis-pizzas/` y `/privacidad/`
  (`config/qa.json`).

## Pendiente: checkpoint de fidelidad visual 1:1

La paridad visual con el prototipo de referencia no está declarada. Falta ejecutar un
checkpoint completo sobre las nueve rutas y los cinco viewports de `config/qa.json`
(1440, 1024, 768, 390 y 375 px) que reúna:

1. Capturas regeneradas de la fuente y de WordPress con el entorno comparable de
   [`visual/baseline-bonasera.md`](visual/baseline-bonasera.md) (viewport fijo, locale
   `es-VE`, zona horaria `America/Caracas`, movimiento reducido).
2. Recorridos por sección y estados interactivos del inventario de estados
   (filtros, constructor, carrito con delivery y propina, checkout, reservas, Mis
   pizzas, hover/focus/disabled/error).
3. Inspección manual de tipografía, jerarquía, ritmo y composición, además de la
   diferencia perceptual automática.
4. Presupuestos de `config/qa.json`: overflow horizontal 0 px, targets de al menos
   44 px, imágenes locales de hasta 200.000 bytes, cero errores de consola, cero
   bloques ausentes y un H1 por ruta.
5. Diferencias intencionales documentadas: las correcciones de responsive y
   accesibilidad frente a defectos de la fuente, y las diferencias conocidas del
   baseline, no se aprueban de forma implícita.

Solo un checkpoint con evidencia completa y cero diferencias no autorizadas puede
declarar paridad; un gate estructural aprobado no lo sustituye. Las cifras de la
medición inicial de la línea base no son el estado actual.

## Pendiente: otros puntos abiertos

- **Editar pizza** desde el carrito y desde Mis pizzas: requiere que el constructor
  admita un modo de edición precargado.
- **Panel de Operaciones consolidado**: hoy las pantallas de Menú, Comercio, Pedidos,
  Reservas, Ingredientes, Opciones de pizza y Disponibilidad son submenús
  independientes bajo **Vicunav**; una pantalla con pestañas no está implementada.
- **Video del hero**: el archivo está inventariado como aportado por el propietario;
  su composición en la portada debe verificarse en el checkpoint visual.
- **Diferencias de media aprobadas** (historia, avatares de testimonios, sustituto de
  dolci): se mantienen salvo que se aporte media nueva con licencia comprobable.
- **Dependencias de desarrollo**: `npm audit` completo conserva avisos transitivos del
  toolchain oficial de WordPress; el gate de producción (`npm run audit:prod`) debe
  seguir en cero vulnerabilidades.
