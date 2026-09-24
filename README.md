# Vicunav Restaurante

Self-contained reference project for a restaurant site built on WordPress without
WooCommerce: the fictional trattoria **Bonasera**. It ships a native restaurant plugin,
a block theme, audited demo content and licensed media in a single repository.

## What is in the repository

| Path | Contents |
| --- | --- |
| `plugin/` | The `vicunav-restaurante` plugin: menu, pizza builder, cart, orders, manual payments, delivery, reservations, saved pizzas, nine dynamic blocks and the `vicu/v1` REST API. Includes tests, block build, Composer and npm. |
| `theme/` | The `vicunav-bonasera` block theme (no parent theme): design tokens, restaurant header/footer parts, editorial patterns and self-hosted fonts. Includes its own tests. |
| `content/bonasera.json` | Audited demo content (copy, menu, FAQ, testimonials, operations data). |
| `assets/` | Licensed local media referenced by `config/media.json`. |
| `config/` | `site.json` (install manifest), `media.json` (media and license inventory), `qa.json` (visual QA contract). |
| `bin/` | `install-local.sh` and `wpcli.sh`. |
| `tests/` | Content and QA validators, runtime QA script and the entry point `run.sh`. |
| `docs/` | Project documentation; `docs/standards` is a Git submodule with the shared engineering standards. |

The plugin has three internal modules: the restaurant domain (`Vicu\Restaurante\*`),
payments (`Vicu\Restaurante\Payments\*`) and shared capabilities such as FAQ,
testimonials, settings and REST base (`Vicu\Restaurante\Shared\*`).

## Requirements

- WordPress 6.6 or later and PHP 8.1 or later.
- A [LocalWP](https://localwp.com/) site (or any local WordPress whose URL host ends in `.local`) for the installer.
- Node.js, Composer and WP-CLI for development.

## Local installation

```bash
git submodule update --init --recursive
bash bin/install-local.sh --wp-path=/path/to/app/public --site-url=https://bonasera.local
```

The installer symlinks `plugin/` and `theme/` into the target WordPress and activates
both. It refuses non-`.local` sites and never copies files. Use `--dry-run` to preview
the actions.

## Validation

```bash
bash tests/run.sh          # theme, content and installer checks

cd plugin
composer install && npm ci
composer check             # lint, coding standards, PHPUnit (unit + integration)
npm run check              # audit, JS/CSS lint, Jest, visual contract, build
```

## Documentation

- [`docs/arquitectura.md`](docs/arquitectura.md): structure, modules, installation, limits.
- [`docs/plugin.md`](docs/plugin.md): blocks, REST API, capabilities, settings, payments, privacy.
- [`docs/theme.md`](docs/theme.md): tokens, header/footer parts, patterns, page composition.
- [`docs/contenido-y-media.md`](docs/contenido-y-media.md): content and media audit.
- [`docs/visual/`](docs/visual/): visual baseline and asset contract.
- [`docs/estado.md`](docs/estado.md): current status and pending work.

Contributions follow [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

Licensed under [GPL-2.0-or-later](LICENSE).
