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
- Storage under `wp-content/uploads/lumia-factures/` must stay unreachable from the web (planned for lot 2; see the Nginx rule in the [README](../../README.md#invoice-storage-protection)).
