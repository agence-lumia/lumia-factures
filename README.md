# Lümia Factures

PDF invoices for WooCommerce orders, by [Agence Lümia](https://agence-lumia.com). Rebuilt (2.x) on the conventions of the agency plugin lumia-tools; it replaces the former `facture-pdf-lumia` plugin (1.x).

The interface is in English and ships with a French translation (`fr_FR`).

## What it does

- Issues PDF invoices for WooCommerce orders, rendered with Dompdf, with continuous numbering per document type. An issued invoice is never modified. (Invoicing arrives in lot 2: the current build ships the foundations only.)
- One admin page, **WooCommerce > Invoices**, with an internal sidebar (`&tab=`) for the overview, the modules and the settings.
- Updates through GitHub, on a Stable or a Dev channel.
- Coexists with lumia-tools on the same site.

Modules: **Invoices** (core, always active) and **Bricks** (optional dynamic tags, lot 2).

## Requirements

- PHP 8.1 or later (tested on 8.3 and 8.5)
- WordPress 6.9 or later
- WooCommerce 9.0 or later (HPOS and legacy order storage)
- The legacy `facture-pdf-lumia` plugin must be deactivated (both cannot run together)

If a requirement is not met, the plugin starts no module and shows a blocking notice listing the reasons. The updater still works, so a fixed version can be installed.

## Installation

1. Download `lumia-factures-<version>.zip` from the latest [GitHub release](https://github.com/agence-lumia/lumia-factures/releases) (the asset, not the "Source code" archives: only the asset contains the `vendor/` folder with Dompdf).
2. Upload it in Plugins > Add New > Upload Plugin and activate it.
3. Open WooCommerce > Invoices.

## Updates

The repository is public (`agence-lumia/lumia-factures`). In Settings:

- choose the **Stable** (releases from `main`) or the **Dev** (pre-releases from `dev`) channel;
- click Check for updates to bypass the cache.

Optionally, define `LUMIA_FACTURES_GITHUB_TOKEN` in `wp-config.php` to raise the GitHub API quota. The token is only sent to `api.github.com` URLs of this repository and is stripped on redirects.

## Invoice storage protection

Invoice PDFs live in `wp-content/uploads/lumia-factures/`. Apache/LiteSpeed sites are protected by an `.htaccess` file; **Nginx ignores it**, so add this rule to the server block:

```nginx
location ^~ /wp-content/uploads/lumia-factures/ { deny all; }
```

## Uninstalling

Invoices must be kept 10 years, so deleting the plugin removes **nothing** by default. To also delete the plugin data, define this in `wp-config.php` before deleting it:

```php
define( 'LUMIA_FACTURES_REMOVE_ALL_DATA', true );
```

## Development

Working rules and conventions are in [CLAUDE.md](CLAUDE.md); technical documentation is in [docs/](docs/README.md). No PHP is needed on the machine: Docker and Node run everything.

```bash
npm install
npm run env:start     # wp-env (WordPress + WooCommerce)
npm test              # PHPUnit inside wp-env

# Lint (PHPCS) + static analysis (PHPStan level 8), through Docker
docker run --rm -v "${PWD}:/app" -w /app composer:2 check          # PowerShell
docker run --rm -v "$PWD:/app" -w /app composer:2 check            # macOS / Linux

# Translations
docker run --rm -v "${PWD}:/app" -w /app composer:2 i18n:pot       # also i18n:mo, i18n:check
```

The dev `vendor/` (from `composer install`) is mapped into the wp-env containers with the rest of the working tree, so it must exist before `npm test`.

## Releases and versioning

- Dev: every push to `dev` publishes a pre-release automatically (`release-dev.yml`).
- Stable: `gh workflow run release-please.yml -f bump=patch` (or `minor`, `major`) on `main`.
- The version is bumped by CI only, never by hand. The ZIP is attached to each release as `lumia-factures-<version>.zip`.
- Commits are English Conventional Commits; branches start from `dev`.

## License

GPL-2.0+
