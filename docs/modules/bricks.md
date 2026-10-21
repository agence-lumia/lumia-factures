# Bricks module

Locked module (always on, nothing to turn on): dynamic tags for the order documents (invoice, credit notes, documents list) in the Bricks editor. The code is `includes/Modules/Bricks/` (`Module` registers `Tags`).

## Tags

| Tag | Value |
|---|---|
| `{lumia_factures_invoice_url}` | customer download URL (`CustomerDownload::url()`, passed through `esc_url()` inside a content), only when the status is `available`; `''` otherwise |
| `{lumia_factures_invoice_number}` | the number of the issued invoice, `''` when there is none or no access |
| `{lumia_factures_invoice_status}` | `available` when the download link works: an issued invoice whose PDF was never built (the endpoint builds it) or whose archive passes `Archive::verify()`; `none` otherwise (no access, nothing issued, recorded file missing or damaged) |
| `{lumia_factures_credit_note_url}` | download URL of the order's **latest available** credit note (`CustomerDownload::credit_note_url()`, `esc_url()` inside a content); `''` when there is none or no access |
| `{lumia_factures_credit_note_number}` | number of that credit note, `''` otherwise |
| `{lumia_factures_credit_note_status}` | `available` when the order has an available credit note the visitor may open, `none` otherwise |
| `{lumia_factures_documents}` | HTML of the documents block (the `order-documents.php` template, the same as the order pages and `[lumia_factures_documents]`): place it in a Rich text or Code element. `''` when nothing is available or no access |

"Latest" is the last credit note of `DocumentList::for_order()` (issue date, then register row id), among the available ones only: a credit note whose archive is damaged is skipped, the previous one is offered. The credit note tags check `can_access( $order, $key, 'credit_note' )` (filter `lumia_factures_can_access_document`); the documents tag keeps each document only when the visitor may open its type.

They are listed in the Bricks picker under the group "Lümia Factures". The Facture PDF Lumia names `{fpl_invoice_url}`, `{fpl_invoice_number}` and `{fpl_invoice_status}` render the same values but are not listed: existing templates keep working untouched. There is no `fpl_` name for the credit note or documents tags. Hooks, as in the old plugin: `bricks/dynamic_tags_list`, `bricks/dynamic_data/render_tag` (20, 3), `bricks/dynamic_data/render_content` (20, 3) and `bricks/frontend/render_data` (20, 2). They are added unconditionally: without Bricks the filters never fire.

## Always on

The module was optional in 2.0.0: the legacy import turned it on when a Bricks template used `{fpl_invoice_`, and a failed activation had to be reported. Since its hooks are inert without Bricks, it is now locked (`'locked' => true`): a migrated site keeps its legacy tags rendering with no step, and the admin has no module to manage.

## Pitfalls

- **A condition on the status must hide the element.** The production templates show the link only when `{fpl_invoice_status}` is not `none`. Without an order in the context, or without access, the status tag therefore returns `none` (not `''`, which would pass the condition and leave a dead link).
- **Structured values.** Bricks 2.x can pass arrays through `render_tag`; a non-string value is returned untouched, converting it would raise "Array to string conversion".
- **The content short circuit tests the real tag names.** `render_content()` used to return early unless the content held `lumia_factures_invoice_` or `fpl_invoice_`: `{lumia_factures_documents}` and the credit note tags share neither prefix and were never resolved. The tags are now one map from full name to resolver (`Tags::resolvers()`), and `render_content()` replaces exactly the `{name}` entries of that map; a name that merely starts like ours (`{lumia_factures_credit_note_foo}`) stays untouched.
- **Nothing written from a render.** The credit note and documents tags read `DocumentList` only: no `Issuer::issue()`, no `can_issue()`, no `save()`, even on an eligible order not yet invoiced.
- **Access is checked for every tag.** A builder can hand over any post: the number and the status are not shown to a visitor who could not download the invoice.
- **No `pending` status, no `Issuer::can_issue()` from a render.** The first version answered `pending` when the order could be issued but was not yet. `can_issue()` writes the "import gate" order note and saves the order (hooks, webhooks) when the legacy import is running, which a front render must never do; and a `pending` value passed the production condition (`!= none`), so the button led to "Invoice not found". The status is now only `available` or `none`, from the register row and the archive check, without any write.
- **`available` means the link works.** A register row whose file is NULL (imported without a PDF, or never built) is available: the download endpoint builds it on first request. A recorded file that is missing or fails its checksum is not rebuilt for a visitor (the endpoint answers 404), so the status is `none` and the URL empty until a manager rebuilds it from the order.
