# Bricks module

Optional module (inactive by default): dynamic tags for the invoice in the Bricks editor. The code is `includes/Modules/Bricks/` (`Module` registers `Tags`).

## Tags

| Tag | Value |
|---|---|
| `{lumia_factures_invoice_url}` | customer download URL of the issued invoice (`CustomerDownload::url()`), `''` when there is none or the visitor has no access |
| `{lumia_factures_invoice_number}` | the invoice number, `''` when there is none or no access |
| `{lumia_factures_invoice_status}` | `available` (an issued invoice), `pending` (`Issuer::can_issue()` is true but nothing is issued yet), `none` otherwise |

They are listed in the Bricks picker under the group "Lümia Factures". The Facture PDF Lumia names `{fpl_invoice_url}`, `{fpl_invoice_number}` and `{fpl_invoice_status}` render the same values but are not listed: existing templates keep working untouched. Hooks, as in the old plugin: `bricks/dynamic_tags_list`, `bricks/dynamic_data/render_tag` (20, 3), `bricks/dynamic_data/render_content` (20, 3) and `bricks/frontend/render_data` (20, 2). They are added unconditionally: without Bricks the filters never fire.

## Turning it on

The legacy import turns the module on when a Bricks template (`_bricks_page_content_2`) contains `{fpl_invoice_`, and adds a note to the report. Otherwise it stays off until activated in the Modules tab.

## Pitfalls

- **A condition on the status must hide the element.** The production templates show the link only when `{fpl_invoice_status}` is not `none`. Without an order in the context, or without access, the status tag therefore returns `none` (not `''`, which would pass the condition and leave a dead link).
- **Structured values.** Bricks 2.x can pass arrays through `render_tag`; a non-string value is returned untouched, converting it would raise "Array to string conversion".
- **Access is checked for all three tags.** A builder can hand over any post: the number and the status are not shown to a visitor who could not download the invoice.
- **The status tag calls `Issuer::can_issue()`**, which writes the "import gate" order note once while the legacy import is running. It is only evaluated when no invoice is issued.
