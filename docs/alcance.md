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

REST-02B no registra entidades, persistencia, permisos, REST, administración ni
bloques. REST-02C es el siguiente issue y será propietario de capabilities,
migraciones e instalación idempotente.

## Fuera de alcance actual

El plugin todavía no registra CPT, tablas, capabilities, endpoints, ajustes, pantallas
administrativas, bloques o assets. Tampoco contiene menú, pricing, carrito, pedidos,
integración operativa con pagos, reservas, contenido Bonasera ni integración con
LocalWP.

Esas capacidades se implementan únicamente mediante los issues atómicos posteriores
del plan de restaurante.
