# Lümia Factures 2.0: design

**Date:** 2026-10-19 · **Repo:** `agence-lumia/lumia-factures` (copy of `studiokyne/facture-pdf-lumia`) · **Branch:** `v2`

## Context

The plugin "Facture PDF Lumia" 1.1.1 runs in production on a single site, neo-nat.fr (WordPress 7.1, WooCommerce 11.2, Bricks). The agency is taking over this code without having written it. Two audits (2026-10-19) found critical bugs in invoice numbering, invoices that can change after they are issued, and a few security weaknesses. The plugin must also become reusable on other French WooCommerce shops.

## Decisions taken

| Topic | Decision |
|---|---|
| Delivery | A single 2.0.0 release. No 1.1.x hotfix. |
| Identifier | Full rename: folder/slug `lumia-factures`, text domain `lumia-factures`, namespace `Lumia\Factures`, prefix `LF_` / `lf_`, with automatic migration of Neonat's data. |
| Genericity | France first: any French WooCommerce shop (VAT or no VAT, B2C/B2B). International translation comes later. |
| Legacy | Old shortcode, Bricks tags and public hooks `fpl_*` stay accepted in 2.x, marked as deprecated. |

## Breakdown into three lots (one plan per lot)

1. **Lot 1: reliability** (on the current identifier, so the diffs stay readable)
   - Test infrastructure: wp-env, PHPUnit, CI.
   - Numbering fixes:
     - the counter reads `insert_id` after an UPDATE (C1);
     - the first PDF has no number (C2);
     - duplicates when the reset changes (H2);
     - the counter can be lowered (H3);
     - the lock is not atomic (M1).
   - Invoices that can no longer change (H1):
     - archive the first PDF permanently;
     - drop the cache expiry for issued invoices.
   - Number assigned when the order reaches a trigger status (H4).
   - Security:
     - token sent by the updater to the wrong host (M3);
     - placeholders not escaped, and the Dompdf chroot too broad (M4);
     - storage subfolders not protected (M5);
     - temp files never deleted (M2).
   - Compatibility: WP 7.1 / WC 11.2 headers, `Requires Plugins`, translations loaded on `init`.
   - Documentation: versioned README.md and CLAUDE.md, `vendor/` built in CI.
2. **Lot 2: rename and migration**
   - Slug, namespace, prefixes and constants.
   - Built-in migration, idempotent and resumable:
     - options;
     - `wp_fpl_sequences` table;
     - order meta `_fpl_invoice_*`;
     - `uploads/facture-pdf-lumia/` folder;
     - cron.
   - Legacy aliases kept (shortcode, tags, hooks).
   - WP-CLI command `wp lumia-factures migrate --dry-run`.
   - Deployment procedure for Neonat.
   - New updater repo: `agence-lumia/lumia-factures`.
3. **Lot 3: neutral French WooCommerce shop**
   - Tax lines:
     - rate and label read from the order;
     - decimal separator;
     - "Total HT" line;
     - L1/L2 of the audit.
   - No-VAT mode: automatically hide the VAT columns and lines, with the 293 B notice.
   - Customer VAT number: configurable meta key.
   - Credit notes numbered on refund, in their own sequence.
   - Native "Download the invoice" links in My Account, on the thank-you page and in the customer emails, behind an option.
   - Demo order built from the shop's own data.
   - Default price display taken from WooCommerce.
   - Audit of Neonat's custom code against a neutral install.

## Rules that apply to the whole 2.x

- An issued invoice (number assigned) never changes. Its number, date and archived PDF are definitive. Any correction goes through a credit note (lot 3).
- Numbering stays continuous and unique per document type. A number that is already used is never handed out again, even after a misconfiguration.
- PHP ≥ 8.1, WordPress ≥ 6.5, WooCommerce ≥ 8.0; tested up to WP 7.1 / WC 11.2.
- HPOS and legacy order storage are both supported.
- No upgrade may lose an issued invoice or change its number.
