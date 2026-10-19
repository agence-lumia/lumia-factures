# Technical documentation

Internal plugin documentation, excluded from the release ZIPs. `CLAUDE.md` at the root holds the absolute rules and points here for the details.

- [core.md](core.md) - boot sequence, requirements gate, autoloader, settings storage, `AbstractModule` contract, admin page, forms and AJAX, JS strings and translations, icons, updater, uninstall, tooling and release ZIP.
- [design-system.md](design-system.md) - CSS classes, tokens, modals, forms, buttons, tooltips, toasts, and what is shared with lumia-tools.
- `superpowers/` - design specs and implementation plans (French, archive: never renamed or translated).

## Modules

| Module | Page | What you will find there |
|---|---|---|
| Invoices | [modules/invoices.md](modules/invoices.md) | invariants of the invoicing core (numbering, immutability, totals); filled in lot 2 |

Each module page documents the **pitfalls and decisions** (what broke, why the solution is the one it is), not the code itself: the code reads from `includes/Modules/<Module>/`. The Bricks module has no page yet (skeleton).
