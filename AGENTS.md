# vicunav-restaurante

Propósito: Dominio WordPress del vertical restaurante para menú, carrito, pedidos,
delivery, pizzas y reservas.

## Estado y límites

REST-02E añade ingredientes, opciones de pizza, relaciones con el menú,
disponibilidad transaccional y lecturas REST cacheables sobre el contrato 1.0.0. No
añadas pricing autoritativo, carrito, pedidos, integración operativa con pagos,
reservas, bloques ni contenido Bonasera antes del issue propietario definido en el
plan del hub.

El plugin será propietario del namespace `Vicu\Restaurante`. Depende de
`vicunav-plugin-core` para capacidades compartidas y de `vicunav-pagos` para
solicitudes de pago. Nunca lee persistencia interna de otro paquete.

El contrato público vive en [`docs/contrato-publico.md`](docs/contrato-publico.md).
Una superficie marcada como planificada no está disponible y solo puede implementarse
en su issue propietario.

## Reglas aplicables

Las reglas transversales del repositorio están en
[`docs/standards/`](docs/standards/). Consúltalas antes de realizar cambios.

No repitas esas reglas aquí; este archivo solo contiene el contexto específico del
repositorio.

## Validación

```sh
composer check &&
git diff --check &&
git submodule status &&
! rg -n '\{\{|\}\}' --glob '!docs/standards/**' .
```

Revisa además la estructura, los enlaces y el formato Markdown de los documentos
modificados.

## Publicación

- No modificar manualmente `composer.lock`, `CHANGELOG.md` ni archivos generados.
- No crear tags, releases o despliegues sin instrucción explícita.
- Todo cambio técnico usa un issue, una rama, un PR y squash-merge.
- El README público se escribe en inglés. La documentación interna y los comentarios
  de código se escriben en español.
