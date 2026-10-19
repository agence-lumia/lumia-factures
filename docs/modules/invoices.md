# Invoices module

Core module (`includes/Modules/Invoices/`, locked: always active, cannot be deactivated). It numbers, renders, archives and e-mails PDF invoices for WooCommerce orders (lot 2). The Bricks dynamic tags and the credit notes are planned for later lots.

## Architecture

One responsibility per folder under `includes/Modules/Invoices/`:

| Folder | Role |
|---|---|
| `Database/Installer` | Creates and upgrades the two tables (`lumia_factures_sequences`, `lumia_factures_documents`) with `dbDelta`, versioned in an option. |
| `Numbering/` | `Sequences` (atomic counters, one row per sequence key), `NumberFormat` (format tags, reset policy validation, preview). |
| `Documents/` | `Register` (the documents table: one row per order and type), `Issuer` (the issue flow), `IssuedDocument` (value object). |
| `Amounts/` | `StatementBuilder` turns an order into lines and total rows in integer cents (`Money`, `Line`, `TotalRow`, `Statement`). |
| `Rendering/` | `Context` (data for the templates), `Placeholders`, `Renderer` (HTML), `PdfEngine` (Dompdf), `Images` (logo), `Styles`. Templates live in `templates/pdf/`. |
| `Storage/Archive` | Write-once PDF files under `uploads/lumia-factures/Y/m/`. |
| `Emails/Attachments` | Attaches the archived PDF to the WooCommerce e-mails chosen in the settings. |
| `Settings/Schema` | Single definition of the settings (defaults, sanitizing, bounds). |
| `Admin/` | Settings screen helpers (`Fields`, `Counters`), order screen (`OrderTools`), `Download`, live `Preview`. |

Data flows one way: `Issuer` -> `Register` / `Sequences` (numbers) -> `Renderer` (HTML from `Context` + `StatementBuilder`) -> `PdfEngine` (bytes) -> `Archive` (file) -> `Register::attach_file()`.

## Invariants (the whole 2.x) and the tests that pin them

- An issued invoice never changes: number, date, archived PDF. `IssuerTest::test_issue_is_idempotent`, `IssuerTest::test_archived_pdf_never_changes`, `RegisterTest::test_attach_file_cannot_repoint_an_issued_invoice`, `ArchiveTest::test_archive_refuses_overwrite`.
- Numbering is continuous and unique per document type. A used number is never reissued. `SequencesTest::test_next_reads_value_not_insert_id`, `SequencesTest::test_raise_never_lowers`, `RegisterTest::test_assign_refuses_duplicate_number`, `IssuerTest::test_issue_when_reserved_by_other_call_does_not_number_twice`.
- The amounts on the PDF add up exactly to the WooCommerce total. `StatementTest::test_rounding_row_absorbs_real_drift`, `StatementTest::test_incl_discount_is_the_balancing_figure_without_rounding_row`, `StatementTest::test_zero_total_order`, `RenderingTest::test_summed_rows_always_print_and_toggles_hide_only_outside_the_sum`.
- No upgrade may lose an issued invoice or change its number. `DatabaseTest::test_install_is_idempotent`, `DatabaseTest::test_maybe_upgrade_does_nothing_when_up_to_date`; `uninstall.php` deletes nothing without the constant (its meta purge is covered by `UninstallerTest`).
- PHP >= 8.1 (tested on 8.3 and 8.5), WP >= 6.9, WooCommerce >= 9.0; HPOS and legacy storage: the whole suite runs on both (`npm test`, `npm run test:legacy`; `StorageModeTest`).

## Rules for this module

- Every change under `includes/Modules/Invoices/` ships with PHPUnit integration tests (`npm test`, wp-env). See [CLAUDE.md](../../CLAUDE.md#deviations-from-lumia-tools).
- The old plugin's code is reachable with `git show v1.1.1:<path>`; lot 2 ported it from there, never copying it as is.
- Storage under `wp-content/uploads/lumia-factures/` must stay unreachable from the web (see "Issuing and archive" below and the Nginx rule in the [README](../../README.md#invoice-storage-protection)).

## Pitfalls

What broke (or would have), and why the solution is the one in the code.

- **C1: `$wpdb->insert_id` after an UPDATE.** 1.x read `insert_id` after an `UPDATE` to get the new counter. An `UPDATE` does not set it, so two invoices could share a number. `Sequences::next()` runs one atomic `UPDATE ... SET current_value = LAST_INSERT_ID(current_value + 1)` and reads `SELECT LAST_INSERT_ID()` (per connection). The number is never computed in PHP from a `SELECT`. Pinned by `SequencesTest::test_next_reads_value_not_insert_id`.
- **C2: the stale `WC_Order`.** The status hook hands over an order object loaded before the number meta exists; rendering from it printed invoices without their number. `Issuer` saves the meta, then renders from a fresh `wc_get_order()`. Pinned by `IssuerTest::test_rendered_pdf_contains_number`.
- **`get_formatted_meta_data( '_', $hide )`.** The first argument hides meta keys starting with `_`; the second (`$include_all`) must be the parent-name flag: with the parent name printed, the attributes already in the variation name would disappear; without it WooCommerce hides them so they are not printed twice (`StatementBuilder`). For a deleted product WooCommerce cannot map the attribute slug to its label, so the slug is humanised.
- **Atomicity without transactions.** The WordPress test suite wraps each test in a transaction; `START TRANSACTION` in the code would commit it and leak data between tests. Atomicity comes from the unique key on the documents table (one row per order and type: `Register::reserve()` is the lock), the single-statement counter update and guarded updates (`assign()` only updates a `reserved` row, `attach_file()` only fills an empty file, `release_stale()` only deletes an unnumbered row).
- **Temporary tables in tests.** Inside a test case `CREATE TABLE` becomes `CREATE TEMPORARY TABLE`, which the next connection cannot see. `tests/bootstrap.php` therefore runs `Installer::install()` on `setup_theme`, before the first test, so the real tables exist.
- **The `rounding` row.** WooCommerce rounds per line and per tax rate, so the printed rows can drift from the order total by a few cents. `StatementBuilder` appends a `rounding` row for the difference instead of absorbing it into another row, so the sum is always exact. Exception (incl. VAT display): a coupon split over two rates reads 4.99 once re-added, and the customer was really given 5.00, so the discount becomes the balancing figure (`balance_discount()`, gap of at most 5 minor units) and no rounding row remains.
- **The archive is never deleted.** PDFs are the legal record (10 years). No code path deletes a file: `Archive::store()` opens with `xb` (write-once), a rebuilt PDF is a **new** file, the damaged one stays, and uninstall never removes `uploads/lumia-factures/`, even with `LUMIA_FACTURES_REMOVE_ALL_DATA`.
- **The Nginx rule.** `.htaccess` protects Apache and LiteSpeed only; on Nginx the archive is public unless the server block contains `location ^~ /wp-content/uploads/lumia-factures/ { deny all; }` (README). File names carry 16 random hex characters as a second barrier.
- **Dompdf font cache.** Dompdf writes its font metrics under `uploads/lumia-factures/fonts/`, created with the same guards as the archive root before the first render (`Archive::ensure_root()`). `Archive::path()` refuses anything outside `Y/m/*.pdf`, so the cache is never served as an invoice.
- **HPOS order meta on uninstall.** `delete_post_meta_by_key()` only reaches `wp_postmeta`; `Core\Uninstaller::delete_order_meta()` also purges `{prefix}wc_orders_meta`.

## Issuing and archive

- **Order of operations** (`Documents\Issuer::issue()`): `Register::reserve()` first (unique key per order), then `Sequences::next()` immediately before `Register::assign()`. A second call for the same order (status hook plus email filter, or two requests) fails at `reserve()` and never touches the counter, so the sequence advances by exactly one.
- **A consumed but unrecorded number is never silent.** When `NumberFormat::build()` throws or `assign()` fails after `next()` (duplicate number), the value is burned: logged and written as an order note naming the number and the sequence value, so the gap is auditable.
- **Render from a fresh order.** The PDF is rendered from `wc_get_order()` after the number meta is saved, never from the `WC_Order` in hand: 1.x rendered a stale object and printed invoices without their number (bug C2).
- **A render failure keeps the number.** The row stays `issued` with no file; `Issuer::restore()` builds the PDF later and records it with `attach_file()`. A missing or damaged file is rebuilt into a **new** file recorded with `replace_file()`; the damaged file is left in place.
- **Write-once files.** `Storage\Archive::store()` writes `Y/m/{number}-{16 hex}.pdf` with `fopen( 'xb' )`: an existing path is refused by the filesystem. Every folder (root, year, month) gets a `.htaccess` (Apache 2.4 and 2.2 rules) and an `index.php`; Nginx needs the rule from the README. `Archive::path()` only accepts `Y/m/*.pdf` resolving inside the root, so the Dompdf `fonts/` cache is never served as an invoice.
- **The status hook never throws.** `woocommerce_order_status_changed` is wrapped in `try/catch (Throwable)`: an invoice failure is logged and must not break the order status change.
- **After `assign()`, every step runs.** Order meta, render + store, order note and the `lumia_factures_invoice_issued` action are each wrapped and logged on failure: a third-party hook throwing from `$order->save()` must not skip the PDF or the note. Missing or different order meta is back-filled by the next `issue()` call on the issued document.
- **Stale reservations.** A `reserved` row with no number older than `Issuer::STALE_AFTER` (10 minutes) comes from a request that died between `reserve()` and `assign()`; `issue()` deletes it with the guarded `Register::release_stale()` and reserves again once. A younger row is a call in progress and is left alone.
- **Guards before any render.** `Archive::ensure_root()` creates the root and `fonts/` with their `.htaccess` / `index.php`; `PdfEngine::options()` and `Archive::store()` both call it, so the Dompdf font cache never lands in an unprotected folder.

## Settings screen

Tab `module_invoices` of the plugin page. `includes/Modules/Invoices/settings-template.php` prints the module form (`lumia-factures-module-form`, the one the header "Save" button submits) with five design-system tabs (`data-lumia-tabs="invoices"`: General, Company, Numbering, Content, Design); each panel is `templates/admin/invoices/{tab}.php`. Fields are rendered by `Admin\Fields` from the `Schema` definitions (name, min, max), so only labels and help texts are written by hand.

- **Tabs and the URL.** The design-system tabs remember the open tab in `sessionStorage`, not in the URL hash. `invoices.js` also opens the tab named by the hash (the raise redirect ends with `#numbering`).
- **Lists need a hidden empty entry.** An unchecked checkbox posts nothing, so a form with every box unchecked would post no `trigger_statuses` at all and keep the old value. `Fields::checklist()` prints `<input type="hidden" name="...[]" value="">` first; the Schema drops empty strings and saves `[]`.
- **Forms cannot nest.** The "Raise counter" forms are standalone `<form>` elements printed after the module form; the inputs and buttons inside the counters table point to them with the HTML5 `form="lumia-factures-raise-{n}"` attribute. Those inputs still sit inside the module form in the DOM, so their `input`/`change` events would mark the settings as unsaved: `invoices.js` stops them at the table.
- **Raising a counter** (`Admin\Counters`, admin-post `lumia_factures_invoices_raise_counter`): `raise_for()` only accepts a key that already exists (the client cannot create counters) and delegates to `Sequences::raise()`, which never lowers a value. Notice codes `counter_raised` / `counter_not_raised` go through the `lumia_factures_admin_notices` filter and the `lumia_factures_notice` query args.
- **Schema errors** (`lumia_factures_invoices_errors` transient, 60 s) are printed as a `lumia-notice--error` above the panels and deleted when read. `SaveHandler` still redirects with the generic "Settings saved" toast: the other settings were saved, only the numbering fields were refused.
- **Live number sample.** Computed in `invoices.js` from `lumiaFacturesAdmin.invoices` (today's date in the site timezone, order `14243`, customer `27`, the counters by key, the known tags). It mirrors `NumberFormat::preview()` and `Sequences::key()`; there is no AJAX.
- **Logo.** `wp_enqueue_media()` only on this tab (`Module::enqueue_media()`), script dependency `media-editor`. The value is the attachment id (`company[logo_id]`).
- **Design tab.** `#lumia-factures-invoices-preview` is the empty container reserved for the live PDF preview.

## Live preview

`Admin\Preview` (AJAX `wp_ajax_lumia_factures_invoices_preview`, nonce `lumia_factures_admin_nonce`, `manage_woocommerce`) renders the **unsaved** form against a real order (the given `order_id`, else the latest order; a refund or unknown id is refused).

- **Input.** `invoices.js` posts a `FormData` of the whole module form (nested `lumia_factures_module_settings[group][key]`, same shape as a save) with `action`, `nonce` and `order_id` overridden. Never post the form's own `action`: it is the save action.
- **No side effect.** The settings go through `Schema::sanitize()` against the stored ones and are never saved. The preview never calls `Issuer`, `Register`, `Sequences::next()` or `Archive::store()`; the number is `NumberFormat::preview()` of the next counter value, read with `Sequences::current()`. `DocumentData::$preview` is true, which prints the translated "Preview" watermark (`.lf-watermark`, `position: fixed`, rotated) in `templates/pdf/invoice.php`. The Dompdf font cache still writes under the archive root `fonts/`, which is not an invoice file.
- **JS.** Debounce 600 ms on `input`/`change` of the Design and Content panels, a request counter so only the latest response is shown, the PDF base64 becomes a Blob URL in an `<iframe>` (the previous URL is revoked). The order field has no `name`, so it is never saved and never marks the form as unsaved. Nothing is requested before the Design tab is first shown.
