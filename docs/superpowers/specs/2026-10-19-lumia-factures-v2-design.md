# Lümia Factures 2.0: design (full rebuild)

**Date:** 2026-10-19 · **Repo:** `agence-lumia/lumia-factures` (copy of `studiokyne/facture-pdf-lumia`) · **Reference:** `agence-lumia/lumia-tools` v2.2.2

## Context

"Facture PDF Lumia" 1.1.1 runs in production on a single site, neo-nat.fr: WordPress 7.1.3, **PHP 8.5**, WooCommerce 11.2.1, HPOS on, Bricks 2.4. The agency did not write it. Audits and production checks run on 2026-10-19 established:

1. **Invoice numbering is broken.**
   - `Sequence::next()` reads `$wpdb->insert_id` after an UPDATE.
   - 152 invoices were issued with numbers 25752 → 29184 while the counter sits at 159.
   - There are no duplicates, but there are gaps everywhere.
2. **Every PDF sent so far lacks the invoice number.** The document is rendered from a stale `WC_Order` object.
3. **Wrong amounts on discounted orders between 23/09 and 19/10.**
   - An old 1.1.0 copy (`facture-pdf-lumia-main`) stayed active until 2026-10-19 10:51.
   - Through the `FPL_VERSION` guard it shadowed 1.1.1.
   - Its line was "price after discount", and its subtotal mixed a pre-discount excl. VAT amount with the post-discount VAT.
4. **Issued invoices can change.** The cache expires after 7 days and the PDF is then regenerated from current data.
5. **Security weaknesses:**
   - updater token sent to a lookalike host;
   - placeholders not escaped;
   - Dompdf chroot on all of `uploads`;
   - storage subfolders not protected;
   - temp files never deleted.
6. **No site-specific code that affects invoices.** Neonat's custom code (60 FluentSnippets, 1 mu-plugin) is front-end/Bricks only.

User decision: **rebuild the repo cleanly following the lumia-tools conventions** (architecture, admin UX/navigation, CSS, auto-update, CI/release). It ships as a single 2.0.0 release. Target: any French WooCommerce shop.

## Product decisions

| Topic | Decision |
|---|---|
| Identity | Slug/folder `lumia-factures`, namespace `Lumia\Factures`, constants `LUMIA_FACTURES_*`, options `lumia_factures_*`, table `{prefix}lumia_factures_sequences`, meta `_lumia_factures_{type}_*`, storage `uploads/lumia-factures/`, text domain `lumia-factures`. |
| Language | Code, comments and source strings in English. French in `languages/lumia-factures-fr_FR.po/.mo` (lumia-tools convention). |
| Product lines | Coupon code shown **only** in the totals recap, never on a line. Variation: parent product name + the chosen attributes below it (`Conditionnement : 500 g`), never the WooCommerce name "parent - attribute". |
| Amounts | One single, tested calculation path. Line = unit price **before discount**, excl. or incl. VAT depending on the display mode. The recap always adds up: subtotal − discount + shipping + fees (+ VAT in excl. VAT mode) = WooCommerce total, to the cent. Tax rate read from the order's tax items (`rate_percent`), never from the current `WC_Tax`. |
| Immutability | An issued invoice (number assigned) is archived for good. It is never purged and is always served as is. Any correction goes through a credit note. Explicit admin regeneration is logged in an order note. |
| Numbering | Number assigned when the order reaches a trigger status. Atomic counter that is never lowered. A number that is already used is never reissued. Period tags are required when there is a reset. |
| Legacy | Automatic migration from `facture-pdf-lumia` (options, sequences, order meta, PDF files). Neonat's numbering continues above the highest number issued. Old shortcode `[facture_pdf_lumia]` and old Bricks tags accepted as deprecated aliases. |

## Architecture (lumia-tools conventions)

- **Bootstrap**
  - `lumia-factures.php`: aligned header with `Update URI`, `Requires Plugins: woocommerce`, WC tested 11.2.
  - Custom PSR-4 autoloader `Lumia\Factures\` → `includes/`.
  - `vendor/` contains **only Dompdf**: built by `composer install --no-dev` at build time, shipped in the ZIP. This is the one documented deviation from lumia-tools, since a PDF engine is required at runtime.
- **`includes/Core/`**
  - `Plugin` singleton on `plugins_loaded`; text domain and modules on `init`.
  - `Settings` with dot notation.
  - `Modules`, `ModuleInterface`, `AbstractModule`.
  - `Activator`, `Deactivator`, `Updater`, `Migration/`.
- **`includes/Modules/`**
  - `Invoices`: core, always active. Contains `Numbering/`, `Documents/`, `Rendering/`, `Storage/`, `Emails/`.
  - `CustomerAccess`: My Account links, thank-you page, shortcode.
  - `Bricks`: dynamic tags.
  - `CreditNotes`: credit notes.
  - `Tools`: import/export, diagnostics.
- **Admin**
  - Submenu WooCommerce → "Factures" (filter `lumia_factures_menu_parent`), page with a lumia-tools sidebar and `&tab=` navigation (General, Company, Numbering, Content, Design + live preview, Modules, Settings/Updates).
  - PHP templates, vanilla JS.
  - Design system copied from lumia-tools (`tokens.css`, `reset.css`, `layout.css`, `sidebar.css`, `components.css`, `buttons.css`, `notifications.css`, `admin.js`, `notifications.js`), BEM prefix `lumia-`, Lucide icons only.
- **Updater**
  - Copy of `Core/Updater` from lumia-tools: GitHub Releases, stable/dev channels, 12 h cache and 15 min negative cache, asset `lumia-factures-*.zip`, auto-update through `auto_update_plugins`.
  - Requires a public repo (otherwise an optional `LUMIA_FACTURES_GITHUB_TOKEN` constant, limited to `api.github.com`).
- **Quality**
  - `composer check` (PHPCS WPCS + PHPStan level 8, no baseline), run through Docker.
  - **PHPUnit integration tests with wp-env** (WP + WooCommerce), required in CI. A deviation from lumia-tools, justified by the legal stakes of numbering and amounts.
- **Release**
  - `tools/build/build-zip.sh` (esbuild, `.l10n.php`, checks).
  - Workflows `lint.yml` / `tests.yml` / `release-dev.yml` / `release-please.yml`.
  - Branches `dev` → `main`; issues and Conventional Commits in English; no CHANGELOG file (generated release notes).

## Delivery lots (one plan per lot)

1. **Foundations:**
   - plugin skeleton and core (modules, settings, activator, updater);
   - admin shell with design system;
   - CI, build, i18n, wp-env + PHPUnit.
2. **Invoice engine:**
   - documents and numbering;
   - totals calculation and rendering (Dompdf template ported to the new conventions);
   - immutable archive, emails, admin metabox and order list column, preview.
3. **Migration and Neonat rollout:**
   - legacy migration and WP-CLI `wp lumia-factures migrate --dry-run`;
   - aliases;
   - procedure: staging test on a copy of the database, then prod;
   - removal of `facture-pdf-lumia` and `facture-pdf-lumia-main`.
4. **Neutral French shop:**
   - customer access (My Account, thank-you page, emails);
   - credit notes;
   - no-VAT mode (293 B);
   - customer VAT number;
   - demo order built from the shop's own data.

## Invariants (the whole 2.x)

- An issued invoice never changes: number, date, archived PDF.
- Numbering is continuous and unique per document type. A used number is never reissued.
- The amounts on the PDF add up exactly to the WooCommerce total.
- No upgrade may lose an issued invoice or change its number.
- PHP ≥ 8.1 (tested 8.3 and 8.5), WP ≥ 6.9, WC ≥ 9.0; HPOS and legacy storage.
