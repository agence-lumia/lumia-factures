# Lümia Factures

PDF invoices for WooCommerce orders, by [Agence Lümia](https://agence-lumia.com). Rebuilt (2.x) on the conventions of the agency plugin lumia-tools; it replaces the former `facture-pdf-lumia` plugin (1.x).

The interface is in English and ships with a French translation (`fr_FR`).

## What it does

- Issues PDF invoices for WooCommerce orders, rendered with Dompdf. An issued invoice is never modified: its number, date and archived PDF are fixed.
- Continuous, unique numbering per document type, with a configurable format (`{year}`, `{month}`, `{number}`...) and yearly, monthly or no reset; counters can be raised, never lowered.
- Amounts that always add up to the WooCommerce total (a rounding row absorbs any drift), in the order's currency, with the VAT rates read from the order.
- Automatic issue on the order statuses you choose, manual issue and restore from the order screen, an invoice column in the order list, and the PDF attached to the WooCommerce e-mails you choose.
- Write-once PDF archive in `wp-content/uploads/lumia-factures/`, protected from web access.
- Settings screen (company, numbering, content, design) with a live PDF preview.
- HPOS and legacy order storage.
- One admin screen, **WooCommerce > Invoices**: the invoice settings in tabs, with ready-made color themes, the live preview next to the fields, and a Settings tab for the updates.
- Updates through GitHub, on a Stable or a Dev channel.
- Coexists with lumia-tools on the same site.

- Imports the invoices, numbers, counters, settings and PDFs of the former Facture PDF Lumia plugin (see [Migrating from Facture PDF Lumia](#migrating-from-facture-pdf-lumia)); the old customer download links and shortcode keep working.
- Customer download of the invoice by link (order key for guests), by the `[lumia_factures_invoice]` shortcode, and by the Bricks dynamic tags.

Planned, not delivered yet: customer access in My Account and credit notes, no-VAT mode, customer VAT number (lot 4).

Modules: **Invoices** (core) and **Bricks** (dynamic tags), both always active: the Bricks hooks only fire on a Bricks site.

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

Invoice PDFs are stored in `wp-content/uploads/lumia-factures/`, protected on Apache/LiteSpeed by an `.htaccess` file in every folder. **Nginx ignores `.htaccess`**: add this rule to the server block, otherwise the archive is reachable from the web:

```nginx
location ^~ /wp-content/uploads/lumia-factures/ { deny all; }
location ^~ /wp-content/uploads/facture-pdf-lumia/ { deny all; }
```

The second rule protects the archive of the former Facture PDF Lumia plugin, which stays in place after a migration.

## Migrating from Facture PDF Lumia

The former plugin (`facture-pdf-lumia`, 1.x) and this one cannot run together. Its data is imported by this plugin and never modified: it stays as a fallback.

Before you start: if WordPress cron runs in a separate container or server (`wp cron event run`), it must mount the same `wp-content/uploads/` volume as the web server, since the import may run there and copies the old PDFs into the new archive.

1. **Back up** the database and `wp-content/uploads/`.
2. **Install** `lumia-factures` while the old plugin is still active. It shows the blocking notice "legacy plugin active" and starts nothing; nothing is imported yet.
3. **Dry run, old plugin still active**: `wp lumia-factures migrate --dry-run` reports what the import will do (invoices, PDFs, counters, conflicts) without writing anything. `wp lumia-factures migration-status` works too; a real `migrate` is refused while the old plugin is active.
4. **Deactivate every copy** of the old plugin at a quiet moment (few orders coming in), also on a multisite or a staging copy sharing the database. The next admin page opened by a shop manager or the next cron run runs the import; to start it immediately instead of waiting, run `wp lumia-factures migrate` right after deactivating. For the few minutes until it finishes, the old customer links answer "Invoice not found" and no new invoice is numbered: an order that changes status meanwhile gets an order note, and its e-mail goes out without an attachment.
5. **Check the import.** The notice at the top of the admin pages shows the status and the counts; `wp lumia-factures migration-status` shows them too, with each current counter next to the highest legacy sequence of the same key: the counter must be at least that value. Conflicts (a number already used) are listed in a warning that links each order; those orders get no invoice until you handle them by hand, then run `wp lumia-factures migrate --force`.
6. **Check one old and one new invoice**: open an imported invoice from its order (number, date and amounts as before), then let a new order reach an invoicing status and check that its number continues the old series. An old invoice whose PDF was no longer on disk is rebuilt the first time it is needed, with its original number and date.
7. **Deny web access to the archives** with the rule below, for `wp-content/uploads/lumia-factures/` and for the old `wp-content/uploads/facture-pdf-lumia/` folder, if your server ignores `.htaccess` (Nginx).
8. **Delete the old plugin files.** Its options, table, order meta and PDFs stay in place; do not remove them while you need the fallback.

The links already sent to customers (`?fpl_download=1&order=...&key=...`), the `[facture_pdf_lumia]` shortcode and the `{fpl_invoice_url}`, `{fpl_invoice_number}` and `{fpl_invoice_status}` Bricks tags keep working. The Bricks module that renders them is always on.

## Uninstalling

Invoices must be kept 10 years, so deleting the plugin removes **nothing** by default. To also delete the plugin data, define this in `wp-config.php` before deleting it:

```php
define( 'LUMIA_FACTURES_REMOVE_ALL_DATA', true );
```

The archive folder `wp-content/uploads/lumia-factures/` is never deleted, even then: remove the PDFs by hand once the legal retention period is over. The purge does drop the numbering counters while the archived PDFs stay, so a reinstall restarts the numbering: before issuing again, set the start number (Numbering tab) above the last issued number, or raise the counters.

## Development

Working rules and conventions are in [CLAUDE.md](CLAUDE.md); technical documentation is in [docs/](docs/README.md). No PHP is needed on the machine: Docker and Node run everything.

```bash
npm install
npm run env:start     # wp-env (WordPress + WooCommerce)
npm test              # PHPUnit inside wp-env (HPOS order storage)
npm run test:legacy   # same suite on the legacy order storage

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
