# Vicunav Restaurante

Native WordPress domain plugin for restaurant menus, carts, orders, delivery, custom
pizzas, and reservations.

## Status

REST-02A provides the initial installable scaffold. It intentionally contains no
restaurant runtime behavior yet. The public contract, persistence, REST endpoints,
admin screens, and blocks will be introduced through separate atomic issues.

The v1 architecture is owned by the Vicunav hub and does not use WooCommerce.

## Boundaries

`vicunav-restaurante` will own restaurant business data and behavior. It will consume
public APIs from the following plugins without reading their internal storage:

- [`vicunav-plugin-core`](https://github.com/vicunav/vicunav-plugin-core) for shared
  WordPress infrastructure.
- [`vicunav-pagos`](https://github.com/vicunav/vicunav-pagos) for payment requests and
  payment lifecycle events.

Presentation shared across sites belongs in `vicunav-theme-core`. Bonasera content and
Full Site Editing composition belong in the future `vicunav-demo-restaurante` project.

## Requirements

- WordPress 6.6 or later.
- PHP 8.1 or later.
- `vicunav-plugin-core` 0.1.0 or later within contract major 1.
- `vicunav-pagos` 0.3.0 or later within its compatible contract.

Install and activate the two dependency plugins before activating **Vicunav
Restaurante**.

## Development

Initialize the shared standards and install development dependencies:

```bash
git submodule update --init --recursive
composer install
```

Run the complete scaffold validation:

```bash
composer check
```

Contributions follow one issue, branch, pull request, and squash-merge per change. See
[`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

This project is licensed under the [GPL-2.0-or-later](LICENSE) license.
