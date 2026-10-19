# CLAUDE.md

Working guide for Claude Code on this repository. The rules below are absolute; the detail (architecture, design system, per-module pitfalls) lives in [docs/](docs/README.md) and is read **on demand**, when the task touches the subject.

## The project

**Lümia Factures** (repository `agence-lumia/lumia-factures`, text domain and slug `lumia-factures`) is a WordPress plugin that issues PDF invoices for WooCommerce orders (PHP 8.1+, WP 6.9+, WooCommerce 9.0+, HPOS and legacy storage). It is a rebuild of the former `facture-pdf-lumia` plugin (1.x, tag `v1.1.1`) on the conventions of the agency plugin lumia-tools (v2.2.2): home-grown PSR-4 autoloader (`Lumia\Factures\` -> `includes/`), `AbstractModule` modules, a custom admin page, a GitHub updater. Every identifier is the lumia-tools one, renamed: namespace `Lumia\Factures`, prefix `lumia_factures_`, constants `LUMIA_FACTURES_`.

Two modules under `includes/Modules/`: `Invoices` (core, **locked**: always active, cannot be deactivated) and `Bricks` (optional, inactive by default). `Invoices` delivers the invoice engine (lot 2: numbering, amounts, rendering, immutable archive, e-mails, order tools, settings and live preview); `Bricks` is still a skeleton. Migration of the 1.x data (lot 3) and the credit notes / customer access (lot 4) are planned. Settings: `lumia_factures_settings` (global + module state) and `lumia_factures_module_{id}` (per module), merged **recursively** over the defaults.

Lifecycle: `plugins_loaded` -> `Plugin::instance()` -> `init` -> text domain, `Requirements::check()`, then (only when it returns no error) module definitions (filter `lumia_factures_module_definitions`) and `Module::init()` on every active module. The updater and `Admin` are built whatever the requirements. Full detail: [docs/core.md](docs/core.md).

## Absolute rules

- **Never bump the version by hand.** CI does it (`* Version:` and `LUMIA_FACTURES_VERSION` in `lumia-factures.php`, always in step). A push to `dev` -> automatic pre-release; stable -> `gh workflow run release-please.yml -f bump=patch|minor|major`. The release asset is `lumia-factures-<version>.zip` (folder `lumia-factures/`), built by `tools/build/build-zip.sh` ([docs/core.md](docs/core.md#release-zip)).
- **`ABSPATH` guard** on every PHP file: `defined( 'ABSPATH' ) || exit;` after `namespace`, otherwise after the docblock.
- **Never a drawn or approximated SVG.** Every icon comes from [Lucide](https://lucide.dev) (lucide-static v1.34.0), the official file fetched as is (`Admin\Icons`).
- **Design-system components only** (`components.css` + `admin.js`): modals, tooltips, toasts, buttons, forms, tabs. Do not code an equivalent. See [docs/design-system.md](docs/design-system.md).
- **Capabilities.** The plugin admin requires `manage_woocommerce`. Every form posts to `admin-post.php` with a nonce + capability check. Every AJAX endpoint (`wp_ajax_lumia_factures_{module}_{action}`) calls `check_ajax_referer( 'lumia_factures_admin_nonce', 'nonce' )` then checks the capability.
- **Never trust the client**: every `$_POST` / `$_GET` / `$_FILES` goes through `wp_unslash()` then the matching sanitizer; paths and imported data are validated server-side.
- **Coexistence with lumia-tools.** Both plugins are often active on the same admin. No class, function, constant, option, hook name, AJAX action, nonce, menu slug, DOM id or CSS/JS handle may collide: everything is prefixed `lumia_factures_` / `lumia-factures-`, JS globals are `lumiaFactures*`, CSS is scoped under `.lumia-factures-admin`. The `lumia-*` BEM classes and `--lumia-*` tokens are the exception, kept identical to lumia-tools on purpose ([docs/design-system.md](docs/design-system.md#duplication-with-lumia-tools)).
- **Never delete invoice data by default.** Invoices must be kept 10 years: `uninstall.php` removes nothing unless `LUMIA_FACTURES_REMOVE_ALL_DATA` is defined to `true` in `wp-config.php`.
- **No PHPCS/PHPStan baseline**: never recreate one to make a finding pass. Fix it, or add a justified `phpcs:ignore` / `@phpstan-ignore`.
- **Document pitfalls in `docs/`**, not here: a fix that comes from a counter-intuitive WordPress behavior or a bug that cost something goes in the module page (what broke, why this solution).
- **Everything is English**: identifiers, comments, docblocks, UI strings, `docs/`, this file, the README and the GitHub templates. French lives only in `languages/lumia-factures-fr_FR.po` / `.mo`. No accented French character in `includes/`, `templates/`, `assets/`, `docs/*.md`, `README.md`, `CLAUDE.md` or `.github/`; the only exception is "Lümia". `docs/superpowers/` (specs and plans) stays in French and is never translated.

## Invoicing invariants (the whole 2.x)

These hold for every change, in every lot. Any change that could break one is refused, however small.

1. **An issued invoice never changes**: number, date, archived PDF. Pinned by: `IssuerTest::test_issue_is_idempotent`, `IssuerTest::test_archived_pdf_never_changes`, `RegisterTest::test_attach_file_cannot_repoint_an_issued_invoice`, `ArchiveTest::test_archive_refuses_overwrite`.
2. **Numbering is continuous and unique per document type.** A used number is never reissued. Pinned by: `SequencesTest::test_next_reads_value_not_insert_id`, `SequencesTest::test_raise_never_lowers`, `RegisterTest::test_assign_refuses_duplicate_number`, `IssuerTest::test_issue_when_reserved_by_other_call_does_not_number_twice`.
3. **The amounts on the PDF add up exactly to the WooCommerce total.** Pinned by: `StatementTest::test_rounding_row_absorbs_real_drift`, `StatementTest::test_incl_discount_is_the_balancing_figure_without_rounding_row`, `StatementTest::test_zero_total_order`.
4. **No upgrade may lose an issued invoice or change its number.** Pinned by: `DatabaseTest::test_install_is_idempotent`, `DatabaseTest::test_maybe_upgrade_does_nothing_when_up_to_date`, `UninstallerTest` (all in `tests/Integration/`).
5. **Compatibility**: PHP >= 8.1 (tested on 8.3 and 8.5), WP >= 6.9, WooCommerce >= 9.0, HPOS and legacy order storage. Pinned by: `StorageModeTest` (HPOS run via `npm test`, legacy run via `npm run test:legacy`).

## Deviations from lumia-tools

- **`vendor/` ships.** lumia-tools has no runtime dependency; here Dompdf (`dompdf/dompdf ^3.1`) is loaded from `vendor/`, installed by `build-zip.sh` with `composer install --no-dev --optimize-autoloader --classmap-authoritative`. The dev `vendor/` of the working tree is never copied into the ZIP. A missing or damaged `vendor/` is reported by `Requirements` (key `dompdf`), never a fatal error.
- **Automated tests are mandatory for `includes/Modules/Invoices`.** lumia-tools has none. Any change under `includes/Modules/Invoices/` comes with PHPUnit integration tests (run in WordPress through wp-env: `npm test`). A change to that module without a test is not mergeable. The other areas have integration tests for boot, modules, updater and admin (`tests/Integration/`).
- **Files are forced to LF** by `.gitattributes` (`* text=auto eol=lf`) on every OS: fixtures are compared byte for byte.
- **Requirements gate and blocking notice.** `Requirements::check()` returns the blocking errors keyed `php`, `wp`, `woocommerce`, `extensions`, `dompdf`, `legacy_active` (the old `facture-pdf-lumia` plugin defines `FPL_VERSION`; both must never run together). While one is present no module starts, and a notice is printed on **`all_admin_notices`**, not `admin_notices`: lumia-tools moves `admin_notices` into its notification drawer, which would hide a blocking error.

## Working convention

Tracking lives **in GitHub issues** (`gh issue list`): read them before proposing a plan.

1. **One issue per subject** (templates in `.github/ISSUE_TEMPLATE/`).
2. **One branch per issue**, created from `dev`: `feat/<n>-<subject>`, `fix/<n>-<subject>`, `docs/<n>-<subject>`, `chore/<n>-<subject>`, `ci/<n>-<subject>`.
3. **Commits are Conventional Commits, in English**: `type(scope): description`; types `feat`, `fix`, `docs`, `style`, `refactor`, `chore`, `ci`; scope = module or area (`core`, `admin`, `invoices`, `i18n`, `lint`...).
4. **PR to `dev`**, title in Conventional Commits, body from `.github/PULL_REQUEST_TEMPLATE.md`, with `Closes #n`, reviewed by an independent reviewer agent (read-only, given this CLAUDE.md); blocking findings are fixed before merging. `Closes #n` only closes the issue when it reaches `main`: close it by hand after merging into `dev`.
5. **Batch the merges locally, then a single push** of `dev`, so that only one pre-release is triggered per batch.
6. **`composer check` and `npm test` before the PR.** No PHP on the machine: go through Docker/wp-env.
   - Lint + static analysis: `docker run --rm -v "${PWD}:/app" -w /app composer:2 check` (PowerShell; `"$PWD:/app"` on macOS/Linux; Git Bash: `MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app composer:2 check`).
   - Tests: `npm run env:start` once, then `npm test` (HPOS) and `npm run test:legacy` (legacy order storage).

   `lint.yml` (PHPCS, PHPStan, language gate, ZIP build) and `tests.yml` (PHPUnit on PHP 8.3 and 8.5) replay them on every PR.

## Translations (text domain `lumia-factures`)

All strings in the code, PHP and JS, are **English**. JS strings are passed from PHP (`lumiaFacturesAdmin.i18n`), so one `.mo` serves both: no `wp.i18n`. A string with a placeholder carries a `/* translators: */` comment, and numbered placeholders (`%1$s`) as soon as there are two. Two French texts for one English source cannot coexist in a `.po`: disambiguate with `_x()`.

1. Write the string in English with `__()` / `_x()` / `_n()` and the `lumia-factures` domain.
2. `composer i18n:pot` regenerates `languages/lumia-factures.pot`.
3. Add the French translation to `languages/lumia-factures-fr_FR.po` (the only source of the French text).
4. `composer i18n:mo` compiles the `.mo`.
5. `composer i18n:check` verifies that the `.po` covers the `.pot` and the `.mo` matches the `.po` (the `i18n` job of `lint.yml` runs it too).

Through Docker: `docker run --rm -v "${PWD}:/app" -w /app composer:2 i18n:pot` (same form for `i18n:mo`, `i18n:check`, `i18n:test`). Details and exit codes: [tools/i18n/README.md](tools/i18n/README.md).

## Where to read the detail

- [docs/core.md](docs/core.md) - boot, requirements, autoloader, storage, modules, admin page, updater, uninstall, tooling, release ZIP.
- [docs/design-system.md](docs/design-system.md) - BEM classes `lumia-`, tokens, modals, forms, buttons, tooltips, toasts.
- [docs/modules/](docs/README.md#modules) - one page per module, to open before touching that module.
- [docs/superpowers/specs/2026-10-19-lumia-factures-v2-design.md](docs/superpowers/specs/2026-10-19-lumia-factures-v2-design.md) - the v2 design spec (French).
