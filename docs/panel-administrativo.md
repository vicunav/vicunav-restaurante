# Panel administrativo: catálogo dinámico del constructor de pizzas

Este documento describe, de forma práctica, dónde y cómo se administra desde
wp-admin todo lo que el constructor de pizzas ofrece en el frontend. El
contrato técnico completo (schema, revisiones, compare-and-swap) vive en
[`contrato-publico.md`](contrato-publico.md#ingredientes-opciones-y-disponibilidad-v1);
este documento es el mapa de "qué pantalla edita qué".

**Nada de lo que el constructor de pizzas muestra está hardcodeado en el
bloque, el theme o el demo.** Tamaños, tipos de masa, salsas, quesos y
toppings son filas de base de datos administradas desde wp-admin; el bloque
`vicunav/restaurante-pizza-builder` solo proyecta lo que el catálogo publica
como disponible en el momento de la solicitud.

## Dónde vive cada pantalla

Todas las pantallas están bajo el menú **Vicunav** en wp-admin, registradas por
`CatalogAdmin` (`src/admin/class-catalog-admin.php`):

| Pantalla | Slug | Capability | Qué administra |
| --- | --- | --- | --- |
| Ingredientes | `vicu-restaurante-ingredients` | `manage_vicu_restaurant_catalog` | Alta/edición estructural de ingredientes (nombre, categoría, precio, alérgenos, dieta). |
| Opciones de pizza | `vicu-restaurante-pizza-options` | `manage_vicu_restaurant_catalog` | Alta/edición estructural de tamaño, masa y salsa (nombre, precio, orden). |
| Disponibilidad | `vicu-restaurante-availability` | `manage_vicu_restaurant_availability` | Toggle on/off de cualquier ingrediente u opción ya creada, sin tocar su estructura. |

La separación es intencional: alguien con solo `manage_vicu_restaurant_availability`
puede agotar/reactivar un ingrediente el mismo día (p. ej. se acabó la
mozzarella de búfala) sin poder crear ni renombrar nada. Editar nombre, precio
u orden requiere `manage_vicu_restaurant_catalog`.

## Cómo se reparten los ejes del constructor

El constructor de pizzas combina dos tipos de fila de catálogo, cada uno con
su propia pantalla y su propio conjunto cerrado de valores
(`CatalogValidator`, `src/catalog/class-catalog-validator.php`):

- **Opciones de pizza** (`PizzaOptionService`) — tipo cerrado a `size`,
  `crust` o `sauce`. Cada fila es una opción del selector correspondiente
  (tamaño, masa o salsa) con su propio modificador de precio y orden de
  aparición. Se administran en **Opciones de pizza**.
- **Ingredientes** (`IngredientService`) — categoría cerrada a `base`,
  `cheese`, `extra` o `topping`. El constructor de pizzas solo consume las
  categorías `cheese` (el selector de queso) y `topping` (los toppings por
  zona, mitad/mitad o pizza completa). Las categorías `base` y `extra` no
  alimentan el constructor de pizzas: son las que usa el menú estructurado
  para las relaciones ingrediente-por-plato (`MenuIngredientService`, roles
  `required`/`removable`/`optional` de un `vicu_menu_item`). Todos los
  ingredientes, sin importar su categoría, se crean y editan en la misma
  pantalla **Ingredientes**.

En resumen, para cambiar lo que ve alguien construyendo una pizza:

- ¿Agregar/quitar/renombrar un **tamaño**, tipo de **masa** o **salsa**? →
  Opciones de pizza.
- ¿Agregar/quitar/renombrar un **queso** o un **topping**? → Ingredientes,
  con categoría `cheese` o `topping` respectivamente.
- ¿Solo agotar algo que ya existe (por ejemplo, sin stock hoy) sin borrar su
  configuración? → Disponibilidad.

## Qué NO se edita aquí

- **Precio final y validación de una configuración concreta** los calcula
  siempre `PizzaPricingService` en el servidor a partir de las filas vigentes
  — el admin nunca escribe un total, solo modificadores de precio por fila.
- **Relación ingrediente-por-plato del menú** (qué ingredientes lleva un
  plato normal, cuáles son removibles) se administra en **Menú → relaciones
  de ingredientes** (`class-menu-relations-admin.php`), no aquí.
- **Zonas de entrega y sus tarifas** tienen su propio servicio
  (`DeliveryZoneService`) y no pasan por `CatalogAdmin`.

## Consistencia y revisiones

Cada escritura exige un nonce y una `expected_revision` (compare-and-swap):
si alguien más editó la misma fila entre que abriste el formulario y guardaste,
la escritura se rechaza en vez de pisar el cambio ajeno. Toda creación o
actualización confirmada incrementa la revisión pública compartida
`vicu_restaurante_availability_revision`, que el frontend usa para invalidar
caché — por eso un cambio de disponibilidad se refleja en el constructor sin
necesidad de desplegar nada.

No existe borrado operativo: un ingrediente u opción que ya no se usa se
desactiva (Disponibilidad), nunca se elimina, para conservar la referencia en
pedidos históricos que ya la usaron.

## Panel de Operaciones consolidado (pendiente)

Hoy estas pantallas viven como submenús independientes bajo **Vicunav**, junto
con Menú, Comercio, Pedidos y Reservas (cada una en su propia clase admin). Un
"Panel de Operaciones" que las consolide en una sola pantalla con pestañas
(Dashboard / Reservas / Ingredientes / Configuración) está planificado pero
todavía no implementado — ver el plan de migración de
`vicunav-demo-restaurante`. Mientras tanto, la fuente de verdad de qué
pantalla hace qué es este documento.
