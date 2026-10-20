# Bricks module

Optional module (inactive by default): dynamic tags for the invoice in the Bricks editor. The code is `includes/Modules/Bricks/` (`Module` registers `Tags`).

## Tags

| Tag | Value |
|---|---|
| `{lumia_factures_invoice_url}` | customer download URL (`CustomerDownload::url()`, passed through `esc_url()` inside a content), only when the status is `available`; `''` otherwise |
| `{lumia_factures_invoice_number}` | the number of the issued invoice, `''` when there is none or no access |
| `{lumia_factures_invoice_status}` | `available` when the download link works: an issued invoice whose PDF was never built (the endpoint builds it) or whose archive passes `Archive::verify()`; `none` otherwise (no access, nothing issued, recorded file missing or damaged) |

They are listed in the Bricks picker under the group "Lümia Factures". The Facture PDF Lumia names `{fpl_invoice_url}`, `{fpl_invoice_number}` and `{fpl_invoice_status}` render the same values but are not listed: existing templates keep working untouched. Hooks, as in the old plugin: `bricks/dynamic_tags_list`, `bricks/dynamic_data/render_tag` (20, 3), `bricks/dynamic_data/render_content` (20, 3) and `bricks/frontend/render_data` (20, 2). They are added unconditionally: without Bricks the filters never fire.

## Turning it on

The legacy import turns the module on when a Bricks template (`_bricks_page_content_2`) contains `{fpl_invoice_`, and adds a note to the report. Otherwise it stays off until activated in the Modules tab. When `Modules::activate( 'bricks' )` fails (the modules are not registered when the plugin has not booted, which is why the plugin activation no longer runs the import), the report says so and the failure is logged: turn the module on by hand.

## Pitfalls

- **A condition on the status must hide the element.** The production templates show the link only when `{fpl_invoice_status}` is not `none`. Without an order in the context, or without access, the status tag therefore returns `none` (not `''`, which would pass the condition and leave a dead link).
- **Structured values.** Bricks 2.x can pass arrays through `render_tag`; a non-string value is returned untouched, converting it would raise "Array to string conversion".
- **Access is checked for all three tags.** A builder can hand over any post: the number and the status are not shown to a visitor who could not download the invoice.
- **No `pending` status, no `Issuer::can_issue()` from a render.** The first version answered `pending` when the order could be issued but was not yet. `can_issue()` writes the "import gate" order note and saves the order (hooks, webhooks) when the legacy import is running, which a front render must never do; and a `pending` value passed the production condition (`!= none`), so the button led to "Invoice not found". The status is now only `available` or `none`, from the register row and the archive check, without any write.
- **`available` means the link works.** A register row whose file is NULL (imported without a PDF, or never built) is available: the download endpoint builds it on first request. A recorded file that is missing or fails its checksum is not rebuilt for a visitor (the endpoint answers 404), so the status is `none` and the URL empty until a manager rebuilds it from the order.
