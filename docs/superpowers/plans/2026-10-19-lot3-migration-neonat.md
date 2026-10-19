# Lot 3: Legacy migration and Neonat rollout. Implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** let a site running the legacy `facture-pdf-lumia` (1.x) switch to `lumia-factures` with no lost invoice, no renumbering and no broken customer link. Then roll out on neo-nat.fr.

**Architecture:**
- The migration lives in `includes/Modules/Invoices/Migration/`. It imports settings, the invoice register, order meta, the remaining legacy PDFs and the counters. It runs automatically on activation, and can be run again from WP-CLI (`wp lumia-factures migrate [--dry-run]`).
- While legacy invoices exist and are not imported, the issuer refuses to number anything, so numbering cannot restart behind the legacy numbers.
- Customers keep their links:
  - a front-end download endpoint (also serving the legacy `?fpl_download=` URLs);
  - the shortcode `[lumia_factures_invoice]` with the legacy `[facture_pdf_lumia]` alias;
  - Bricks tags with the legacy `{fpl_invoice_*}` aliases.

**Tech Stack:** as lot 2 (PHP 8.1+, WP 6.9+, WooCommerce 9.0+, HPOS + legacy, Dompdf, PHPUnit through wp-env), WP-CLI.

**Spec:** `docs/superpowers/specs/2026-10-19-lumia-factures-v2-design.md` (lot 3: migration, WP-CLI dry-run, aliases, staging then prod, removal of the old copies).

## Global Constraints

- Everything in `CLAUDE.md` applies, including the five invoicing invariants and the "tests are mandatory for `includes/Modules/Invoices`" rule. Both test runs must stay green: `npm test` (HPOS) and `npm run test:legacy`.
- **Never delete or modify legacy data.** The migration only reads:
  - the options `fpl_*`;
  - the table `{prefix}fpl_sequences`;
  - the order meta `_fpl_invoice_*`;
  - the files under `uploads/facture-pdf-lumia/`.

  It copies what it needs. The legacy data stays as a fallback.
- **Legacy data shapes (read on neo-nat.fr, 2026-10-19):**
  - **Order meta**, per invoiced order:
    - `_fpl_invoice_number` (string, e.g. `202610029241`);
    - `_fpl_invoice_sequence` (int);
    - `_fpl_invoice_sequence_key` (e.g. `invoice_2026`);
    - `_fpl_invoice_date` (UTC timestamp);
    - `_fpl_invoice_file` (path relative to `uploads/facture-pdf-lumia/`, e.g. `2026/10/invoice-19396-<32 hex>.pdf`, often missing on disk: 7-day retention);
    - `_fpl_invoice_generated_at`.
  - **Sequences table:** `{prefix}fpl_sequences (sequence_key, current_value, updated_at)`. Its `current_value` is wrong because of bug C1 (160, while the issued sequences reach 29241), so the true counter is `max(current_value, max imported sequence)` per key.
  - **Settings:** option `fpl_settings`, nested `general|company|numbering|content|design`. Same keys as our Schema (lot 2 kept them), plus the keys our Schema removed. `trigger_statuses` and `email_attachments` contain `""` entries, the font names are capitalised (`Inter`), the labels and titles store the French legacy defaults, and `company.address` has CRLF line breaks.
- **Legacy PDFs (user decision, 2026-10-19: "keep + complete"):**
  - a legacy PDF still on disk is copied as is into our archive and recorded with its checksum;
  - an invoice without a file is imported with `file = NULL`, so lot 2's "build when never built" rule (R41) generates it on first need, with its original number and date.
- **Rehearsal (user decision, 2026-10-19):** on a local copy of the production database in wp-env. The copy stays on the user's machine and is deleted afterwards. No production write happens without the user's explicit go-ahead at that moment.
- **Public repository:** no infrastructure detail (host names, container names, credentials) goes into committed files. The Neonat runbook values come from the session's ops memory, not from the repo.
- **Git:**
  - one epic issue "Lot 3: legacy migration and Neonat rollout";
  - branch `feat/<n>-legacy-migration` from `dev`;
  - Conventional Commits with the `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` trailer;
  - one PR to `dev`.
- **Release:** after lot 3 merges, the stable `2.0.0` is published (`gh workflow run release-please.yml -f bump=...`, with the user's approval). Neonat then updates on the stable channel. Lot 4 ships as 2.1.

## Review Focus

1. **Migration run twice, or interrupted halfway:** a second run imports nothing new, duplicates nothing and never lowers a counter. An interrupted run resumes. Tested in Task 2.
2. **Legacy number already in the register:** an invoice issued by lumia-factures after a partial run, or a duplicate number in the legacy meta. That order is reported as a conflict and skipped. The run continues and is marked `done_with_conflicts`. Tested in Task 2.
3. **Status change or email between activation and the end of the migration:** no number is issued. The email goes out without an attachment and the order note says why. Once the migration is done, the next trigger issues normally, and a legacy-invoiced order resolves to its imported invoice and is never numbered again. Tested in Task 3.
4. **Guest customer opening an old link from an email or the thank-you page** (`?fpl_download=1&order=N&key=wc_order_...`): they get the PDF. A wrong key or another customer's order gets a 404 that reveals nothing. Tested in Task 4.
5. **Legacy PDF damaged or not a PDF on disk:** it is not imported. The order gets `file = NULL` (built on first need), and the report lists it. Tested in Task 2.

---

### Task 1: legacy settings mapping

**Files:**
- Create: `includes/Modules/Invoices/Migration/LegacySettings.php`
- Test: `tests/Integration/Invoices/Migration/LegacySettingsTest.php`

**Interfaces:**
- Produces: `LegacySettings::map( array $legacy ): array{settings: array, notes: string[]}`. A pure function. Its output goes through `Schema::sanitize( $output, Schema::defaults() )` before storage, in Task 2.

Rules:
- **Keys:** copy every key that exists in `Schema::fields()`. Drop the others. Each dropped key with a non-default value adds a note, e.g. `content.legal_mentions had 2 entries: re-enter them in "Legal text"`.
- **Lists:** remove empty strings.
- **Fonts:** lowercase the font names and map `Inter` → `inter`. Any other font → `inter`, with a note.
- **Colors:** lowercase.
- **Multi-line fields:** CRLF → LF.
- **Labels and titles:**
  - the keys `content.*_label`, `content.*_title` and `content.message_title` that are empty in our defaults;
  - a stored value equal to its legacy French default is set to `''`, so the translation applies;
  - the legacy defaults are the French strings of `git show v1.1.1:src/Settings/Schema.php` and `v1.1.1:src/Rendering/ContextBuilder.php`;
  - any other value is kept, because it is a deliberate customization;
  - put the defaults list in a private constant with a comment naming the source.
- **Numbering:** keep the format, `reset_enabled` and `reset_period` as is. `{year}{month}{number:6}` with a yearly reset is valid under `NumberFormat::validate()`.

- [ ] **Step 1: write the failing tests.**
  - `test_neonat_settings_map_cleanly`: a fixture copied from the production read in the session (the `fpl_settings` JSON, with identity values replaced by placeholders). Expected:
    - `trigger_statuses === ['processing','on-hold','completed','refunded']`;
    - `email_attachments === ['new_order','customer_completed_order','customer_invoice']`;
    - `numbering.format === '{year}{month}{number:6}'`;
    - `design.font_body === 'inter'`;
    - `content.col_name_label === ''` (it was "Produit");
    - `content.message_title === 'Merci pour votre commande'` (kept: custom);
    - `company.address` has no `\r`;
    - `Schema::sanitize( map()['settings'], defaults )['errors'] === []`.
  - `test_removed_keys_with_values_are_reported`.
  - `test_unknown_font_falls_back_with_note`.
  - `test_custom_label_is_kept`.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.**
- [ ] **Step 4: run and see them pass, plus `composer check`.**
- [ ] **Step 5: commit** `feat(invoices): map legacy settings`.

### Task 2: legacy importer

**Files:**
- Create: `includes/Modules/Invoices/Migration/LegacySource.php`, `includes/Modules/Invoices/Migration/Importer.php`, `includes/Modules/Invoices/Migration/Report.php`
- Modify:
  - `includes/Modules/Invoices/Documents/Register.php` (`import()`);
  - `includes/Modules/Invoices/Storage/Archive.php` (`import_file()`).
- Create (tests): `tests/Support/LegacyFixture.php`, `tests/Integration/Invoices/Migration/ImporterTest.php`

**Interfaces:**
- Consumes: `LegacySettings::map()`, `Schema::sanitize()`, `Sequences::raise()` / `current()`, `Register::find()`, `Archive::root()`, `Issuer::TYPE`.
- Produces:
  - `LegacySource`:
    - `has_data(): bool` (the option `fpl_settings` or any `_fpl_invoice_number` meta exists);
    - `settings(): ?array`;
    - `sequences(): array<string,int>` (empty when the table is missing);
    - `invoices( int $after_order_id, int $limit ): array<int, array{order_id, number, sequence, sequence_key, issued_at, file}>`, reading the order meta through `wc_get_orders` with a `meta_query`, so it works under HPOS and legacy storage. Orders are sorted by id ascending.
  - `Register::import( string $type, int $order_id, string $number, string $sequence_key, int $sequence, int $issued_at ): ?int`:
    - a direct `issued` insert;
    - `null` on a duplicate of `type_order` or `type_number`, without inserting.
  - `Archive::import_file( string $source, string $number, int $issued_at ): array{file, checksum}|null`:
    - checks that the source is readable, starts with `%PDF-` and is inside `uploads/facture-pdf-lumia/` (`realpath`);
    - copies it through the existing write-once `write_new()` under `Y/m/{number}-{16 hex}.pdf`.
  - `Report`: counters `settings` (`imported` | `skipped_existing` | `none`), `invoices_imported`, `invoices_already`, `files_copied`, `files_missing`, `files_invalid`, `conflicts` (list of `order_id`/`number`/reason), `sequences` (key → value set), and `notes` (`string[]`). Plus `to_array()`.
  - `Importer::run( bool $dry_run ): Report`.

`Importer::run()` steps:
1. **Settings:**
   - only when `lumia_factures_module_invoices` does not exist yet (never overwrite settings the user saved);
   - map, sanitize, `update_option`;
   - with `$dry_run`, compute only.
2. **Invoices:** in batches of 200 ordered by order id:
   - **When `Register::find()` already returns a row with the same number:** count it as `invoices_already`.
   - **When it returns a row with a different number:** record a conflict and skip.
   - **Otherwise:**
     1. `Register::import()`; when it returns `null`, record a conflict and skip;
     2. write the order meta `_lumia_factures_invoice_number` / `_lumia_factures_invoice_date` through the WC_Order API;
     3. when the legacy file exists, `import_file()` then `Register::attach_file()`. Otherwise count it as `files_missing` or `files_invalid`, and leave `file` NULL.
   - Add one order note per imported order: "Invoice %s imported from Facture PDF Lumia."
3. **Sequences:** for each key present either in the legacy table or among the imported sequence keys:
   - `target = max( legacy current_value, max imported sequence for that key )`;
   - `Sequences::raise( key, target )`, which never lowers.
4. **Dry run:** writes nothing (no option, no row, no meta, no file, no note, no raise). Same report.

- [ ] **Step 1: write `LegacyFixture` and the failing tests.**
  - `LegacyFixture` creates the `fpl_sequences` table in the test (temporary is fine), the legacy meta on real orders and legacy PDF files under `uploads/facture-pdf-lumia/Y/m/`. Clean up the files in `tear_down`.
  - Tests:
    - `test_imports_neonat_shaped_data`: 3 orders, 2 with a file and 1 missing, sequences 25752, 29184 and 29241 under `invoice_2026`, and a legacy table at 160. Expected:
      - 3 register rows `issued` with the legacy numbers and dates;
      - meta written;
      - 2 files copied with checksums that verify;
      - 1 row with `file` NULL;
      - `Sequences::current('invoice_2026') === 29241`;
      - one note per order.
    - `test_next_invoice_continues_after_legacy_numbers`: after the import, with the Neonat settings, a new order moved to processing gets `2026{month}029242`.
    - `test_dry_run_writes_nothing`.
    - `test_second_run_is_a_no_op` (Review Focus 1).
    - `test_conflict_is_reported_and_skipped` (Review Focus 2).
    - `test_invalid_legacy_pdf_is_not_imported` (Review Focus 5).
    - `test_existing_settings_are_never_overwritten`.
    - `test_legacy_data_is_untouched`: the legacy meta, table, options and files are byte-identical after the run.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.**
- [ ] **Step 4: run and see them pass on HPOS and legacy storage, plus `composer check`.**
- [ ] **Step 5: commit** `feat(invoices): import legacy invoices, files and counters`.

### Task 3: migration lifecycle, issuing gate and WP-CLI

**Files:**
- Create:
  - `includes/Modules/Invoices/Migration/Migration.php`;
  - `includes/Modules/Invoices/Cli/Command.php`.
- Modify:
  - `includes/Modules/Invoices/Module.php` (activation, init, CLI registration);
  - `includes/Modules/Invoices/Documents/Issuer.php` (gate);
  - `includes/Modules/Invoices/Admin/` (blocking notice + retry action).
- Test: `tests/Integration/Invoices/Migration/MigrationTest.php`, `tests/Integration/Invoices/Migration/CliTest.php`

**Interfaces:**
- Consumes: `LegacySource::has_data()`, `Importer::run()`.
- Produces:
  - option `lumia_factures_migration`: `{status: 'none'|'pending'|'running'|'done'|'done_with_conflicts'|'failed', started_at, finished_at, report}`.
  - `Migration::state(): array`.
  - `Migration::maybe_run(): void`: when the status is `pending` or `failed`, set `running` (guarded with `add_option`/compare so two requests cannot both run) and run. Store the report and the final status. A `running` status older than 15 minutes counts as `failed`.
  - `Migration::blocks_issuing(): bool`: true when legacy data exists and the status is not `done` or `done_with_conflicts`.
  - `Issuer::can_issue()` returns false while `Migration::blocks_issuing()`. It also refuses an order that carries `_fpl_invoice_number` with no register row, whatever the state, because the importer resolves those orders. Each refusal adds the order note "Invoice not issued: the Facture PDF Lumia data is being imported." at most once per order.
  - WP-CLI, only when `WP_CLI` is defined:
    - `wp lumia-factures migrate [--dry-run] [--force]` prints the report as a table plus the conflicts. `--force` reruns even when the status is `done`;
    - `wp lumia-factures migration-status`.
  - Blocking notice on `all_admin_notices` for `manage_woocommerce` while `blocks_issuing()`. It shows the status, the counts and a "Run the import now" admin-post button (`lumia_factures_invoices_migrate`, nonce + capability).

Lifecycle:
- On activation (`on_activate()`) and in `Module::init()`, after `maybe_upgrade()`:
  - when `LegacySource::has_data()` and the status is `none`, set `pending`;
  - then call `maybe_run()` on activation, on the next admin request, or on the next cron/CLI request. Never during a front-end request.
- A site without legacy data stays `none`, and nothing changes.

- [ ] **Step 1: write the failing tests.**
  - `test_activation_with_legacy_data_runs_migration`.
  - `test_site_without_legacy_data_is_unaffected`.
  - `test_issuing_is_blocked_until_migration_done` (Review Focus 3): an order moved to processing while the status is `pending` gets no number and one note. After the migration, the next trigger issues it.
  - `test_legacy_invoiced_order_is_never_renumbered`.
  - `test_concurrent_run_is_refused`.
  - `test_stale_running_state_is_retried`.
  - `test_retry_action_requires_nonce_and_capability`.
  - `test_cli_dry_run_reports_without_writing`: call the command class directly with a fake `WP_CLI` output when `WP_CLI` is unavailable in the suite. Keep the logic in a method that returns the rows, and make the command a thin wrapper.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.**
- [ ] **Step 4: run and see them pass, plus `composer check`.**
- [ ] **Step 5: commit** `feat(invoices): run the legacy migration safely and gate issuing`.

### Task 4: customer download, shortcode and legacy URLs

**Files:**
- Create: `includes/Modules/Invoices/Front/OrderContext.php`, `includes/Modules/Invoices/Front/CustomerDownload.php`, `includes/Modules/Invoices/Front/Shortcode.php`
- Modify: `includes/Modules/Invoices/Module.php`
- Test: `tests/Integration/Invoices/Front/CustomerDownloadTest.php`, `tests/Integration/Invoices/Front/ShortcodeTest.php`

**Interfaces:**
- Consumes:
  - `Register::find()`;
  - `Issuer::can_issue()`, `Issuer::restore()` (only when `file` is NULL, as in R41);
  - `Archive::path()`, `Archive::verify()`.
- Produces:
  - `OrderContext::resolve( mixed $subject = null ): ?WC_Order` and `OrderContext::current_order_key(): ?string`. A port of `git show v1.1.1:src/Frontend/OrderContext.php`: the query vars `view-order` and `order-received`, then the request URI endpoints, then the subject id.
  - `CustomerDownload::url( WC_Order $order ): string`:
    - `home_url( '/' )` with `lumia_factures_invoice=<order id>`;
    - plus `key=<order key>` when the visitor is not logged in;
    - filter `lumia_factures_customer_download_url`.
  - `CustomerDownload::can_access( WC_Order $order, ?string $key ): bool`, true when one of:
    - the visitor has `manage_woocommerce`;
    - the logged-in user is the order's customer;
    - the key matches `hash_equals( order key, key )`.

    Filter `lumia_factures_can_access_invoice`.
  - `CustomerDownload::handle()` on `init` priority 20. It answers both `?lumia_factures_invoice=N` and the legacy `?fpl_download=1&order=N[&document=invoice][&key=...][&inline=1]`.
    - Refuse with `wp_die` 404 "Invoice not found." when there is no access, no issued document, or a file that fails verification after the never-built restore. The same message for every case: never reveal whether an order exists.
    - Rate limit: 10 requests per minute per user or IP (transient), only on requests that must build a PDF.
    - Headers as `Admin\Download` (inline unless the legacy URL had no `inline`, which means `attachment`), with output buffers cleaned.
  - `Shortcode`:
    - `[lumia_factures_invoice order_id="" label="" class="" inline="0"]`, and the alias `[facture_pdf_lumia]` with the same attributes;
    - renders `<a href class>label</a>`, escaped, only when an issued document exists and `can_access()` is true;
    - the default label is "Download my invoice".

- [ ] **Step 1: write the failing tests.**
  - `test_owner_can_download`.
  - `test_guest_with_order_key_can_download`.
  - `test_wrong_key_or_other_customer_gets_404` (Review Focus 4).
  - `test_legacy_url_is_served`: `?fpl_download=1&order=N&key=K` gives the same file.
  - `test_never_built_invoice_is_built_on_first_download`: imported with `file` NULL, then downloaded. The PDF is created, with the imported number and date.
  - `test_rate_limit_applies_only_to_builds`.
  - `test_shortcode_and_legacy_alias_render_link_for_owner_only`.
  - `test_order_context_from_view_order_endpoint`.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.**
- [ ] **Step 4: run and see them pass, plus `composer check`.**
- [ ] **Step 5: commit** `feat(invoices): customer download, shortcode and legacy links`.

### Task 5: Bricks dynamic tags

**Files:**
- Modify: `includes/Modules/Bricks/Module.php`
- Create: `includes/Modules/Bricks/Tags.php`
- Modify: `includes/Modules/Invoices/Migration/Importer.php` (activate the Bricks module)
- Test: `tests/Integration/Bricks/TagsTest.php`

**Interfaces:**
- Consumes: `OrderContext::resolve()`, `OrderContext::current_order_key()`, `CustomerDownload::url()`, `CustomerDownload::can_access()`, `Register::find()`, `Issuer::can_issue()`.
- Produces:
  - Tags `{lumia_factures_invoice_url}`, `{lumia_factures_invoice_number}` and `{lumia_factures_invoice_status}` (`available` | `pending` | `none`, with the legacy semantics: `available` = issued, `pending` = can issue but not issued yet, `none` otherwise). They are listed in the Bricks picker under the group "Lümia Factures".
  - The legacy `{fpl_invoice_url}`, `{fpl_invoice_number}` and `{fpl_invoice_status}` are rendered identically but not listed.
  - Hooks: `bricks/dynamic_tags_list`, `bricks/dynamic_data/render_tag` (20, 3), `bricks/dynamic_data/render_content` (20, 3), `bricks/frontend/render_data` (20, 2), as in `git show v1.1.1:src/Frontend/DynamicTag.php`.
  - The URL tag returns `''` when `can_access()` is false.
  - The Importer turns the Bricks module on (`Plugin::instance()->modules()->activate( 'bricks' )`, which exists since lot 1) when a post meta `_bricks_page_content_2` contains `{fpl_invoice_`, and adds a report note.

- [ ] **Step 1: write the failing tests.**
  - `test_tags_render_for_order_context`: with the filters applied directly, Bricks itself is not needed.
  - `test_legacy_aliases_render_same_values`.
  - `test_url_empty_without_access`.
  - `test_status_values`.
  - `test_importer_enables_bricks_module_when_legacy_tags_used`.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.**
- [ ] **Step 4: run and see them pass, plus `composer check`.**
- [ ] **Step 5: commit** `feat(bricks): invoice dynamic tags with legacy aliases`.

### Task 6: docs, translations and migration guide

**Files:**
- Modify:
  - `docs/modules/invoices.md`: migration, gate, customer access, pitfalls;
  - `docs/modules/bricks.md` (create if missing);
  - `README.md`: a "Migrating from Facture PDF Lumia" section and the Nginx rule;
  - `CLAUDE.md`: modules overview;
  - `languages/*`.

- [ ] **Step 1: write the generic migration guide in the README:**
  1. back up;
  2. install `lumia-factures` while the legacy plugin is still active (it waits on the requirements gate);
  3. deactivate every copy of the legacy plugin;
  4. the import runs, then check the notice and the report (or run `wp lumia-factures migrate --dry-run` first);
  5. check one old and one new invoice;
  6. add the web server rule denying `wp-content/uploads/lumia-factures/` (and `uploads/facture-pdf-lumia/`);
  7. delete the legacy plugin files. Their data stays.
- [ ] **Step 2: `composer i18n:pot`, French for every new string, then `i18n:mo` and `i18n:check`.**
- [ ] **Step 3: `npm test`, `npm run test:legacy` and `composer check`.**
- [ ] **Step 4: commit** `docs: legacy migration guide` and `feat(i18n): French translation of the migration`.

### Task 7 (controller and user, no subagent): local rehearsal on a copy of production

This is not a code task. The controller runs it with the user's go-ahead. The steps use the production access described in the session's ops memory (read-only).

- [ ] **Step 1:** export the production database read-only, by streaming `wp db export -` over ssh to a local file in the scratchpad, never to a file on the server. Copy `uploads/facture-pdf-lumia/` the same way (`tar` over ssh).
- [ ] **Step 2:** import the copy into the wp-env **development** instance (not tests). Install the legacy 1.1.1 ZIP and the branch build of lumia-factures. Set `home`/`siteurl` to localhost, and disable outgoing email with a mu-plugin that short-circuits `pre_wp_mail`.
- [ ] **Step 3:**
  1. With the legacy plugin active, run `wp lumia-factures migrate --dry-run` (allowed in that state since the final review fixes) and review the report with the user.
  2. Deactivate the legacy plugins.
  3. Load one manager admin page, which runs the import. Activation alone only marks it as pending. Compare the counts:
  - 153 or more invoices;
  - the files copied match the files present on disk;
  - the counter equals the highest legacy sequence;
  - no conflict, or every conflict explained.
- [ ] **Step 4:**
  - move one test order to processing: its number continues the legacy series;
  - download an old invoice with a file (unchanged bytes), and an old invoice without a file (built with its legacy number and date);
  - open the Bricks "Mon compte" and "Commandes" pages and check the invoice link.
- [ ] **Step 5:** delete the local copy (the DB dump, the file archive, and a `wp-env clean development`) and confirm with the user. Any finding becomes a fix on the lot 3 branch before the PR.

### Task 8 (controller and user, no subagent): release and production rollout

- [ ] **Step 1:** merge lot 3 into `dev`, then publish the stable `2.0.0` with the user's approval (`release-please.yml`).
- [ ] **Step 2:** with the user's explicit go-ahead at that moment:
  - take a production backup (database + uploads);
  - add the Nginx deny rule for both upload folders (the config lives in the site's infrastructure repository; check with `nginx -t` and a request to a guard file that must return 403);
  - check that the cron container mounts the same uploads volume.
- [ ] **Step 3:**
  1. Install `lumia-factures` 2.0.0 while the legacy plugin is still active.
  2. Run `wp lumia-factures migrate --dry-run` and review the report with the user.
  3. At a quiet moment, deactivate `facture-pdf-lumia` and `facture-pdf-lumia-main`.
  4. Load one manager admin page, which runs the import.
  5. Confirm `migration-status` reads `done` and that the `invoice_2026` counter equals the highest legacy `_fpl_invoice_sequence`.
- [ ] **Step 4:** run the checks from Task 7 step 4 against production (with no test order in production: watch the next real order instead). Then delete the two legacy plugin folders. Their data stays.
