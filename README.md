# Vicunav Restaurante

Native WordPress domain plugin for restaurant menus, carts, orders, delivery, custom
pizzas, and reservations.

## Status

REST-02O publishes plugin version 0.15.0 over public contract 1.0.0. The current
runtime adds coordinated cart, manual-checkout, and private order-status blocks to the
dynamic menu and pizza builder. Server contracts remain authoritative for prices,
totals, checkout, order state, and payment evidence. It preserves account-owned saved
pizzas and reservations without reading another package's storage. It does not yet
register the reservation or account blocks, demo content, or depend on WooCommerce.

Further domain persistence, REST endpoints, admin screens, and blocks are introduced
only through their separate atomic issues. A planned surface is not available until
its implementation matrix marks it as complete.

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
- `vicunav-plugin-core` contract 1.x.
- `vicunav-pagos` contract 0.3.0 or later, before contract 1.0.0.

Install and activate the two dependency plugins before activating **Vicunav
Restaurante**.

## Development

Initialize the shared standards and install development dependencies:

```bash
git submodule update --init --recursive
composer install
npm ci
```

Run the complete validation:

```bash
npm run check
composer check
```

Contributions follow one issue, branch, pull request, and squash-merge per change. See
[`CONTRIBUTING.md`](CONTRIBUTING.md).

The versioned integration surface and implementation matrix are documented in
[`docs/contrato-publico.md`](docs/contrato-publico.md).

## License

This project is licensed under the [GPL-2.0-or-later](LICENSE) license.
