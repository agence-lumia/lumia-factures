# Invoices module

Core module (`includes/Modules/Invoices/`, locked: always active, cannot be deactivated). In lot 1 it is an empty skeleton; numbering, documents, rendering, storage and emails arrive in lot 2, and this page then documents the pitfalls and decisions of each part.

## Invariants (the whole 2.x)

- An issued invoice never changes: number, date, archived PDF.
- Numbering is continuous and unique per document type. A used number is never reissued.
- The amounts on the PDF add up exactly to the WooCommerce total.
- No upgrade may lose an issued invoice or change its number.
- PHP >= 8.1 (tested on 8.3 and 8.5), WP >= 6.9, WooCommerce >= 9.0; HPOS and legacy storage.

## Rules for this module

- Every change under `includes/Modules/Invoices/` ships with PHPUnit integration tests (`npm test`, wp-env). See [CLAUDE.md](../../CLAUDE.md#deviations-from-lumia-tools).
- The old plugin's code is reachable with `git show v1.1.1:<path>`; lot 2 ports it from there.
- Storage under `wp-content/uploads/lumia-factures/` must stay unreachable from the web (see "Issuing and archive" below and the Nginx rule in the [README](../../README.md#invoice-storage-protection)).

## Issuing and archive

- **Order of operations** (`Documents\Issuer::issue()`): `Register::reserve()` first (unique key per order), then `Sequences::next()` immediately before `Register::assign()`. A second call for the same order (status hook plus email filter, or two requests) fails at `reserve()` and never touches the counter, so the sequence advances by exactly one.
- **A consumed but unrecorded number is never silent.** When `assign()` fails after `next()` (duplicate number), the value is burned: logged and written as an order note naming the number and the sequence value, so the gap is auditable.
- **Render from a fresh order.** The PDF is rendered from `wc_get_order()` after the number meta is saved, never from the `WC_Order` in hand: 1.x rendered a stale object and printed invoices without their number (bug C2).
- **A render failure keeps the number.** The row stays `issued` with no file; `Issuer::restore()` builds the PDF later and records it with `attach_file()`. A missing or damaged file is rebuilt into a **new** file recorded with `replace_file()`; the damaged file is left in place.
- **Write-once files.** `Storage\Archive::store()` writes `Y/m/{number}-{16 hex}.pdf` with `fopen( 'xb' )`: an existing path is refused by the filesystem. Every folder (root, year, month) gets a `.htaccess` (Apache 2.4 and 2.2 rules) and an `index.php`; Nginx needs the rule from the README. `Archive::path()` only accepts `Y/m/*.pdf` resolving inside the root, so the Dompdf `fonts/` cache is never served as an invoice.
- **The status hook never throws.** `woocommerce_order_status_changed` is wrapped in `try/catch (Throwable)`: an invoice failure is logged and must not break the order status change.
