# Alcance de `vicunav-restaurante`

## Responsabilidad futura

El plugin será propietario del dominio restaurante: menú estructurado, ingredientes,
disponibilidad, constructor de pizzas, carrito, pedidos, totales, delivery y reservas.
Consumirá contratos públicos de `vicunav-plugin-core` y `vicunav-pagos` sin leer su
persistencia interna.

La decisión coordinadora y el spec durable viven en `vicunav-hub`. El contrato técnico
se publicará en este repositorio durante REST-02B, antes de implementar superficies
consumibles.

## Alcance de REST-02A

Esta fase incluye únicamente:

- repositorio creado desde `vicunav-repo-template`;
- submódulo de estándares compartidos;
- entry point instalable con compatibilidad y dependencias declaradas;
- namespace y constantes fundacionales;
- Composer, lint, WordPress Coding Standards, PHPUnit y CI;
- documentación de límites y contribución.

## Fuera de alcance

REST-02A no registra hooks, CPT, tablas, capabilities, endpoints, ajustes, pantallas
administrativas, bloques o assets. Tampoco contiene menú, pricing, carrito, pedidos,
pagos, reservas, contenido Bonasera ni integración con LocalWP.

Esas capacidades se implementan únicamente mediante los issues atómicos posteriores
del plan de restaurante.
