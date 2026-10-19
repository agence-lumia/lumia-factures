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
