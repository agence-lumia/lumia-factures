# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**Facture PDF Lumia**: a WordPress/WooCommerce plugin that generates customizable PDF invoices with Dompdf 3. It needs PHP ≥ 8.1, WP ≥ 6.4 and WC ≥ 8.0, and it declares HPOS compatibility. The code, comments, UI strings and CHANGELOG are all in **French**, so keep new code consistent with that. The text domain is `facture-pdf-lumia`.

## Commands

There is no build step and there are no automated tests.

- `composer lint`: phpcs with WordPress Coding Standards (`phpcs.xml.dist`, PHPCompatibility for 8.1+).
- `composer fix`: phpcbf auto-fix.
- `php -l <file>`: syntax check. The CHANGELOG records this as the verification step for releases (run it on every file outside `vendor/`).

**`vendor/` is committed** and contains only the runtime dependencies (Dompdf and its deps). The plugin ships as a ZIP with no build step. Running `composer install` pulls the dev dependencies (phpcs, wpcs) into `vendor/`. Do not commit them. Use `composer install --no-dev` to restore the shipped state.

## Releases

Bump the version in **two places** in `facture-pdf-lumia.php`: the `Version:` header and the `FPL_VERSION` constant. Then add an entry at the top of `CHANGELOG.md` (Keep a Changelog, French, with `Corrigé` / `Ajouté` / `Vérifié` sections). Updates reach sites through `src/Updater/GitUpdater.php`, which reads GitHub releases (a `.zip` asset is preferred over the tag archive). It only runs when `wp-config.php` defines `FPL_UPDATE_REPO` and `FPL_UPDATE_TOKEN`.

## Architecture

Namespace: `Lumia\FacturePDF\` → `src/` (PSR-4). The main file falls back to its own autoloader when `vendor/autoload.php` is missing.

**Bootstrap**: `facture-pdf-lumia.php` runs `Plugin::instance()->boot()` on `plugins_loaded` at priority 5, inside a `Throwable` guard so that a failure shows an admin notice and never takes the site down. `Plugin` only wires up services: each service is an object with a `register()` method that attaches hooks. Admin services are instantiated only when `is_admin()`. To add a service, add it in `Plugin::register_services()` and **add the `use` import** (missing imports caused the fatal error fixed in 1.0.1). Extensions hook into `fpl_loaded` or `fpl_services`.

**Settings are schema-driven.** `Settings/Schema.php` is the single source of truth. Each tab (general, company, numbering, content, design, …) declares its fields there, and the following are all derived from it: the admin UI (`Admin/FieldRenderer`), sanitization, defaults, and JSON export/import (`Settings/PortManager`). To add a setting, add a schema entry. Do not write HTML for it. All settings live in a single autoloaded option, read through `SettingsRepository::instance()->get('tab.field')`. Structural changes need a migration: increment `Migrator::SCHEMA_VERSION` and add a `to_N()` method. Design "variations" (`Settings/Variations`) are preset value sets for the design tab, not templates.

**PDF generation pipeline** (`Storage/PdfGenerator::generate`):
1. `Documents\Document` (subclass `Invoice`, factory `DocumentRegistry::make/invoice`) wraps a `WC_Order`. All document data lives in order meta, keyed through `$document->meta_key($suffix)`.
2. `Numbering\NumberStore::assign()` gives a number **before** rendering. **An assigned number is final (legal requirement): never add code that changes or reuses one.** `Sequence` increments atomically with MySQL `LAST_INSERT_ID()` in the custom table `{prefix}fpl_sequences` (created by `Support\Installer`; bump `Installer::DB_VERSION` when the table changes). `NumberGenerator` expands the format tags (`FA-{year}-{number:4}`).
3. `Rendering\Renderer` does the following:
   - `ContextBuilder` assembles the template variables from the order and settings. Free text goes through `Placeholders\PlaceholderResolver` (`{customer_first_name}`…).
   - `TemplateLoader` renders `templates/<template>/invoice.php` and its partials.
   - `StyleCompiler` turns the design settings into CSS variables and **substitutes them in PHP**, because Dompdf does not support `var()`.
   - `FontLibrary` declares only the fonts actually used (bundled TTFs in `assets/fonts/`).
   - `ImageEncoder` inlines images as data URIs. Dompdf needs PNG/JPEG, so GIF/WebP are converted, and the declared MIME type must match the bytes.
4. `Rendering\PdfEngine` is the only class that touches `Dompdf\`.
5. `Storage\FileStore` caches the PDF in uploads under an unguessable filename, with retention in days; `Cleaner` purges it via cron. `AccessGuard` controls downloads.

**Templates** are resolved in this order: child theme `facture-pdf-lumia/<template>/…` → parent theme → plugin `templates/`. A missing partial in a custom template falls back to `templates/default/`. Templates must use only the CSS variables that `StyleCompiler` produces, with no hard-coded values.

**Entry points into the pipeline:**
- `Admin/OrderMetabox`: generate, download or regenerate an invoice from the order screen.
- `Admin/PreviewController` + `Admin/DemoOrder`: a fake in-memory order that is never saved, used for the live preview.
- `Emails/Attacher`: attaches the PDF to WooCommerce emails.
- `Frontend/DownloadHandler`: the download endpoint.
- `Frontend/Shortcode`: `[facture_pdf_lumia]`.
- `Frontend/DynamicTag`: Bricks Builder dynamic tags.
- `Frontend/OrderContext`: the shared order resolution used by the shortcode and the dynamic tags (it handles My Account `view-order` endpoints and custom endpoint slugs).

**Extensibility**: nearly every step exposes an `fpl_*` filter or action (`fpl_settings_schema`, `fpl_template_roots`, `fpl_should_assign_number`, `fpl_invoice_generated`, `fpl_before_document`, …). Keep adding hooks at new extension points and document them with a docblock, as the existing code does.

**Admin JS** uses Alpine.js (vendored `assets/admin/alpine.min.js`, excluded from phpcs) plus `assets/admin/admin.js`.
