# Lot 2: Invoice engine. Implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** make the `Invoices` module issue real invoices: gapless numbering, one tested amounts path that adds up to the WooCommerce total, a Dompdf PDF archived once and never changed, email attachments, order screen tools, a settings screen and a live preview.

At the end of this lot, a fresh shop that installs `lumia-factures` gets a numbered, archived PDF invoice the moment an order reaches a trigger status, attached to the configured emails and downloadable from the order screen. Migrating Neonat's legacy data is lot 3; customer-facing access (My Account, shortcode, Bricks tags) is lot 3/4.

**Architecture:** the module is split by responsibility under `includes/Modules/Invoices/`:
- `Settings/` (schema, defaults, sanitizer);
- `Database/` (two InnoDB tables);
- `Numbering/` (format + atomic sequences);
- `Documents/` (register + issuer);
- `Amounts/` (pure statement of lines and totals, built from a `WC_Order`);
- `Rendering/` (view context, PHP templates, CSS, images, Dompdf);
- `Storage/` (write-once archive);
- `Emails/`;
- `Admin/` (order box, downloads, numbering counters, preview).

The legacy code (`git show v1.1.1:<path>`) is the reference for behavior and labels, never copied as is: it is rewritten to these files, English source strings, the `lumia_factures_` prefix and the fixes below.

**Tech Stack:** PHP 8.1+ (CI 8.3 and 8.5), WP 6.9+, WooCommerce 9.0+ (tested 11.2), HPOS and legacy storage, Dompdf ^3.1, PHPUnit 9.6 through wp-env.

**Spec:** `docs/superpowers/specs/2026-10-19-lumia-factures-v2-design.md`

## Global Constraints

- Everything in [CLAUDE.md](../../../CLAUDE.md) applies:
  - ABSPATH guard;
  - English only outside `languages/` (no accented character except "Lümia");
  - `manage_woocommerce`, nonce + capability on every form and AJAX call;
  - `wp_unslash` then sanitize;
  - Lucide icons only;
  - design-system components only;
  - no PHPCS/PHPStan baseline;
  - never bump the version.
- **Tests are mandatory** for every change under `includes/Modules/Invoices/` (`tests/Integration/Invoices/`). Run `npm test` (wp-env) and `docker run --rm -v "${PWD}:/app" -w /app composer:2 check` before each commit.
- **Identity:**
  - table names (`$wpdb->prefix` +): `lumia_factures_sequences`, `lumia_factures_documents`;
  - order meta: `_lumia_factures_invoice_number`, `_lumia_factures_invoice_date`;
  - module option: `lumia_factures_module_invoices`;
  - storage: `wp-content/uploads/lumia-factures/`;
  - hooks: `lumia_factures_*`;
  - AJAX actions: `wp_ajax_lumia_factures_invoices_{action}`;
  - admin-post actions: `lumia_factures_invoices_{action}`.
- **Money:** every amount is handled in integer minor units (cents), with `wc_get_price_decimals()` decimals. Floats exist only when reading from WooCommerce and are converted at once with `Money::to_minor()`.
- **Tax rate:** always the order's tax items (`WC_Order_Item_Tax::get_rate_percent()`), never `WC_Tax`.
- **Formatting:**
  - prices go through `wc_price()` with the **order** currency, then `html_entity_decode( wp_strip_all_tags() )` to get plain text that templates escape;
  - rates are formatted as `20 %` or `5,5 %`, decimal separator from `wc_get_price_decimal_separator()`.
- **Labels:** a label setting whose stored value is `''` renders the translated default label. Defaults stored in the database are never translated strings.
- **No database transactions.** The WordPress test suite wraps each test in a transaction, and `START TRANSACTION` would commit it. Atomicity comes from unique keys and single-statement updates (Task 3).
- **Git:**
  - one epic issue "Lot 2: invoice engine" with this plan's task list as a checklist;
  - one branch `feat/<n>-invoice-engine` from `dev`;
  - one commit (or more) per task in Conventional Commits (scope `invoices`, `core`, `admin`, `i18n`, `docs`) ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`;
  - one PR to `dev` at the end.

## Review Focus

1. **Deleted product or variation:** an order item whose product no longer exists (`$item->get_product()` returns `false`) still renders. It keeps the stored item name, the attributes from the item meta and the item's own amounts. No fatal error. The test lives in Task 4.
2. **Double issue in one request:** the status hook and the email filter both call `Issuer::issue()` for the same order in the same request, or two requests race. The order gets exactly one number, and the sequence advances by exactly one. The test lives in Task 3 (register) and Task 6 (issuer).
3. **Zero-total order** (100 % coupon, free product, no shipping): no division by zero, every row is `0,00 €`, and the sum still equals the total. The test lives in Task 4.
4. **Unusable logo:**
   - attachment deleted, file missing on disk, or WebP/AVIF image;
   - the PDF still renders, without the logo or with a converted PNG;
   - the issue is logged, never fatal.

   The test lives in Task 5.
5. **Order in a currency other than the shop's:** amounts are formatted in the order currency (`USD` order on a `EUR` shop prints `$`). The test lives in Task 4.

---

### Task 0: lot 1 leftovers

Parked from the lot 1 review. Small fixes outside the Invoices module, each with its own commit.

**Files:**
- Modify: `docs/core.md`, `includes/Core/AbstractModule.php`, `includes/Core/Activator.php`, `includes/Core/Requirements.php`, `includes/Admin/Admin.php`, `includes/Admin/SaveHandler.php`, `assets/admin/css/layout.css`, `.github/workflows/lint.yml`, `CLAUDE.md`
- Test: `tests/Integration/Core/ModulesTest.php`, `tests/Integration/Core/UpdaterTest.php`, `tests/Integration/Admin/AdminTest.php`, `tests/Integration/Core/BootTest.php`

**Interfaces:**
- Produces:
  - filter `lumia_factures_admin_notices` (`array<string, string> $messages`): lets a module add toast codes;
  - query args `lumia_factures_notice` / `lumia_factures_notice_type`;
  - `Requirements` key `extensions`;
  - `AbstractModule::sanitize_against_defaults()` called through `static::`.

- [ ] **Step 1: write the failing tests:**
  - `test_save_settings_keeps_keyed_map_under_empty_default`: a module whose default is `'map' => []` saves `['map' => ['a' => 'x']]` and reads back `['a' => 'x']`. An empty-array default stays a list only when the input is a list.
  - `test_requirements_report_missing_extension`: filter `lumia_factures_required_extensions` to `['lumia_missing_ext']`, and `Requirements::check()` has key `extensions`.
  - `test_toast_reads_prefixed_query_args`: `$_GET['lumia_factures_notice']='settings_saved'` shows the toast. `lumia_notice` is ignored.
  - `test_module_notice_codes_are_filterable`.
  - `test_check_updates_requires_nonce_and_capability`: an editor gets `WPDieException`.
  - `test_auto_update_toggle_writes_site_option`: as admin, `auto_update_plugins` contains the plugin basename after enabling it, and does not after disabling it.
  - `test_activator_reads_locked_modules`: the `invoices` default state comes from the `locked` flag of the definitions, not a hard-coded id.
- [ ] **Step 2: run them and see them fail** (`npm test -- --filter '...'`).
- [ ] **Step 3: implement:**
  - `AbstractModule`:
    - `self::` → `static::` for `sanitize_against_defaults`;
    - an empty-array default accepts an associative input as a sanitized map (scalars → `sanitize_text_field`).
  - `Activator`: the initial state is `! empty( $definition['locked'] )`, read from `Modules::default_definitions()` (add that static method if missing; it is the same array `register_default_modules()` uses).
  - `Requirements`: checks `extension_loaded()` for each of `['dom', 'mbstring', 'gd']`, filterable through `lumia_factures_required_extensions`. Key `extensions`, message listing the missing ones.
  - Rename `lumia_notice*` → `lumia_factures_notice*` in `Admin` and `SaveHandler`. Messages go through the new filter.
  - `layout.css`: replace the fixed `height: calc(100vh - 32px)` on the layout wrapper with `min-height`, so notices above the wrap do not clip it.
  - `docs/core.md`: fix the `save_settings` paragraph (the base implementation sanitizes against `get_defaults()`).
  - `CLAUDE.md`: add `extensions` to the requirement keys.
  - `lint.yml`: add the step "No accented French outside languages/", which fails on `grep -rnP '[\x{00C0}-\x{00FF}]' includes templates assets/admin docs/*.md docs/modules README.md CLAUDE.md .github | grep -v 'Lümia'` (run with `LC_ALL=C.UTF-8`).
- [ ] **Step 4: run all tests and `composer check`.** Everything passes.
- [ ] **Step 5: commit** (`fix(core): ...`, `fix(admin): ...`, `ci(lint): ...`).

### Task 1: invoice settings schema

**Files:**
- Create: `includes/Modules/Invoices/Settings/Schema.php`, `includes/Modules/Invoices/Numbering/NumberFormat.php` (only `validate()` here; Task 3 adds the rest)
- Modify: `includes/Modules/Invoices/Module.php` (`get_defaults()`, `save_settings()`)
- Test: `tests/Integration/Invoices/SettingsSchemaTest.php`, `tests/Integration/Invoices/NumberFormatTest.php`

**Interfaces:**
- Produces:
  - `NumberFormat::validate( string $format, bool $reset_enabled, string $reset_period ): true|string`. The legacy rules (`git show v1.1.1:src/Numbering/NumberGenerator.php`):
    - `{number}` is required;
    - unknown tags are refused;
    - filename characters are refused.

    Plus two new rules:
    - a yearly reset requires `{year}`;
    - a monthly reset requires `{year}` and `{month}`;

    otherwise two periods would print the same numbers.
  - `Schema::defaults(): array<string, array<string, mixed>>`, keyed by group: `general`, `company`, `numbering`, `content`, `design`;
  - `Schema::sanitize( array $input, array $current ): array{settings: array, errors: string[]}`;
  - `Module::settings(): array` (static helper: the stored values merged over the defaults; every other class reads settings through it);
  - transient `lumia_factures_invoices_errors` (`string[]`, 60 s), set when a save has errors.

The keys and defaults are those of the legacy schema (`git show v1.1.1:src/Settings/Schema.php`), so that lot 3 can map Neonat's settings one to one. The exceptions are listed below.

- **Removed:**
  - `general.retention_days`, `general.debug_mode` (no cache anymore: the archive is permanent);
  - `content.total_refunded_enabled` (refunds belong to credit notes, lot 4);
  - `content.legal_mentions` (replaced by `content.legal_text`);
  - `design.template`, `design.custom_css`;
  - `content.col_*_label` / `content.total_*_label` / `content.*_title` defaults become `''` (translated at render time; see Global Constraints).
- **Added:**
  - `content.legal_text` (`''`): multi-line text with placeholders, printed under the totals;
  - `content.item_parent_name_enabled` (`true`): a variation shows its parent name, with the attributes underneath.
- **Changed defaults:**
  - `general.trigger_statuses` = `['processing', 'completed']`;
  - `general.email_attachments` = `['customer_processing_order', 'customer_completed_order', 'customer_invoice']`;
  - `numbering.format` = `'FA-{year}-{number:5}'`;
  - `design.font_heading` / `design.font_body` = `'inter'`, with options `inter` | `dejavu-sans`.

Sanitizing rules:
- every value is coerced to the type of its default;
- lists keep only known options: statuses from `wc_get_order_statuses()` without the `wc-` prefix, email ids from `WC()->mailer()->get_emails()`;
- colors go through `sanitize_hex_color` (invalid → current value);
- numbers are clamped to the legacy `min`/`max`;
- `numbering.format` goes through `NumberFormat::validate()` (Task 3), and an invalid format keeps `$current` and adds the error;
- multi-line fields (`company.address`, `content.message_body`, `content.footer_text`, `content.legal_text`) go through `sanitize_textarea_field`;
- unknown keys are dropped.

- [ ] **Step 1: write the failing tests:**
  - In `NumberFormatTest`:
    - `test_validate_requires_number_tag`;
    - `test_validate_rejects_unknown_tag`;
    - `test_validate_rejects_filename_characters`;
    - `test_validate_requires_year_for_yearly_reset`;
    - `test_validate_requires_month_for_monthly_reset`;
    - `test_validate_accepts_neonat_format`: `'{year}{month}{number:6}'` with a yearly reset gives `true`.
  - `test_defaults_cover_every_group`: the five groups exist, `numbering.format === 'FA-{year}-{number:5}'`.
  - `test_unknown_keys_are_dropped`.
  - `test_invalid_color_keeps_current`.
  - `test_unknown_status_is_filtered_out`: `['completed','bogus']` gives `['completed']`.
  - `test_invalid_number_format_keeps_current_and_reports_error`: the format `'FA-{year}'` (no `{number}`) keeps the current value, `errors` is not empty, and after `Module::save_settings()` the transient holds the error.
  - `test_unchecked_toggle_saves_false`: the hidden `0` input works.
  - `test_module_settings_merge_stored_over_defaults`: a stored partial `company` keeps the other defaults.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement `Schema` and wire `Module::get_defaults()` / `save_settings()` / `settings()`.**
- [ ] **Step 4: run and see them pass, plus `composer check`.**
- [ ] **Step 5: commit** `feat(invoices): settings schema and sanitizer`.

### Task 2: database tables

**Files:**
- Create: `includes/Modules/Invoices/Database/Installer.php`
- Modify:
  - `includes/Modules/Invoices/Module.php`: `on_activate()`, a version check on `init`, `get_uninstall_keys()`;
  - `tests/bootstrap.php`: install the tables at `setup_theme`, after `WC_Install::install()`.
- Test: `tests/Integration/Invoices/DatabaseTest.php`

**Interfaces:**
- Produces:
  - `Installer::DB_VERSION = 1`;
  - option `lumia_factures_db_version`;
  - `Installer::install(): void` (dbDelta, idempotent);
  - `Installer::maybe_upgrade(): void` (installs when the stored version is lower);
  - `Installer::sequences_table(): string`;
  - `Installer::documents_table(): string`.

Tables (`ENGINE=InnoDB`, `$wpdb->get_charset_collate()`):
- `lumia_factures_sequences`:
  - `sequence_key VARCHAR(64) NOT NULL PRIMARY KEY`;
  - `current_value BIGINT UNSIGNED NOT NULL DEFAULT 0`;
  - `updated_at DATETIME NOT NULL`.
- `lumia_factures_documents`:
  - `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`;
  - `type VARCHAR(20) NOT NULL`;
  - `order_id BIGINT UNSIGNED NOT NULL`;
  - `status VARCHAR(20) NOT NULL` (`reserved` | `issued`);
  - `number VARCHAR(100) NULL`;
  - `sequence_key VARCHAR(64) NULL`;
  - `sequence BIGINT UNSIGNED NULL`;
  - `issued_at DATETIME NULL` (UTC);
  - `file VARCHAR(255) NULL` (relative to the storage root);
  - `checksum CHAR(64) NULL` (sha256);
  - `created_at DATETIME NOT NULL`;
  - `UNIQUE KEY type_order (type, order_id)`;
  - `UNIQUE KEY type_number (type, number)`;
  - `KEY order_id (order_id)`.

`maybe_upgrade()` runs on `init` because updating a plugin does not run its activation hook.

`get_uninstall_keys()` declares:
- `tables`: both tables;
- `options`: `lumia_factures_module_invoices`, `lumia_factures_db_version`;
- `meta`: the two order meta keys.

The storage folder is never deleted, even with `LUMIA_FACTURES_REMOVE_ALL_DATA`. Document this in `docs/modules/invoices.md` in Task 11.

- [ ] **Step 1: write the failing tests:**
  - `test_tables_exist`: `SHOW TABLES LIKE` both.
  - `test_unique_keys_exist`: `SHOW INDEX` gives `type_order` and `type_number` with `Non_unique = 0`.
  - `test_maybe_upgrade_installs_when_version_is_lower`: set the option to `0`, call it, and the option is `1`.
  - `test_install_is_idempotent`.

  Note: inside a test, WordPress rewrites `CREATE TABLE` into `CREATE TEMPORARY TABLE`, so the real tables must come from the bootstrap.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.**
- [ ] **Step 4: run and see them pass.**
- [ ] **Step 5: commit** `feat(invoices): sequences and documents tables`.

### Task 3: numbering and document register

**Files:**
- Modify: `includes/Modules/Invoices/Numbering/NumberFormat.php` (created in Task 1)
- Create:
  - `includes/Modules/Invoices/Numbering/Sequences.php`;
  - `includes/Modules/Invoices/Documents/IssuedDocument.php`;
  - `includes/Modules/Invoices/Documents/Register.php`.
- Test: `tests/Integration/Invoices/NumberFormatTest.php`, `tests/Integration/Invoices/SequencesTest.php`, `tests/Integration/Invoices/RegisterTest.php`

**Interfaces:**
- Consumes: `Installer::sequences_table()`, `Installer::documents_table()`.
- Produces:
  - `NumberFormat::build( string $format, int $sequence, \WC_Order $order, int $timestamp ): string`
    - tags as in legacy (`git show v1.1.1:src/Numbering/NumberGenerator.php`): `{number}`, `{number:N}`, `{year}`, `{year:2}`, `{month}`, `{day}`, `{order_number}`, `{order_id}`, `{customer_id}`;
    - dates come from `wp_date()` on `$timestamp`;
    - unknown tags go through the filter `lumia_factures_number_tag`.
  - `NumberFormat::validate()` already exists (Task 1).
  - `NumberFormat::preview( string $format, int $sequence ): string` (sample values `14243` / `27`).
  - `Sequences::key( string $type, int $timestamp, array $numbering ): string`: `invoice`, `invoice_2026` (yearly) or `invoice_2026_10` (monthly).
  - `Sequences::next( string $key, int $start ): int`.
  - `Sequences::current( string $key ): int`.
  - `Sequences::raise( string $key, int $value ): bool`: never lowers. It returns `false` when `$value <= current`.
  - `Sequences::all(): array<int, array{key: string, value: int, updated_at: string}>`.
  - `IssuedDocument`: a readonly value object with `id`, `type`, `order_id`, `status`, `number`, `sequence_key`, `sequence`, `issued_at` (int, UTC timestamp), `file`, `checksum`.
  - `Register::find( string $type, int $order_id ): ?IssuedDocument`.
  - `Register::reserve( string $type, int $order_id ): ?int`: INSERT of a `reserved` row. It returns the row id, or `null` when the order already has a row (`type_order` unique key; suppress the duplicate-key error with `$wpdb->suppress_errors()`).
  - `Register::assign( int $id, string $number, string $key, int $sequence, int $issued_at ): bool`: sets `status=issued`.
  - `Register::attach_file( int $id, string $file, string $checksum ): bool`.
  - `Register::release( int $id ): void`: deletes a `reserved` row whose number is `NULL` (failure before numbering).

`Sequences::next()` algorithm. It fixes legacy bug C1, where `$wpdb->insert_id` was read after an UPDATE:
1. `UPDATE {table} SET current_value = LAST_INSERT_ID(current_value + 1), updated_at = %s WHERE sequence_key = %s`.
2. When one row is affected, return `(int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' )`.
3. Otherwise, `INSERT IGNORE` the row with `current_value = $start - 1` and loop. At most 3 attempts, then return `0` and log.

The value is never computed in PHP from a SELECT: two requests must never read the same counter.

- [ ] **Step 1: write the failing tests:**
  - `test_build_neonat_format`: `'{year}{month}{number:6}'`, sequence `29185`, timestamp of 2026-10-19 gives `'202610029185'`.
  - `test_build_pads_and_two_digit_year`.
  - `test_next_starts_at_start_number`: start 1 gives 1, then 2, then 3.
  - `test_next_reads_value_not_insert_id`: after `$wpdb->insert()` into another table (`insert_id` ≠ counter), `next()` still returns current + 1. This is the regression test for C1.
  - `test_key_per_period`.
  - `test_raise_never_lowers`: `raise( k, 29184 )` gives `true` and current is 29184; `raise( k, 100 )` gives `false` and current is still 29184; `next()` gives 29185.
  - `test_reserve_is_unique_per_order`: the second `reserve()` for the same order returns `null`.
  - `test_assign_refuses_duplicate_number`: two rows with the same number make the second `assign()` return `false`.
  - `test_release_only_deletes_unnumbered_rows`.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.**
- [ ] **Step 4: run and see them pass.**
- [ ] **Step 5: commit** `feat(invoices): gapless numbering and document register`.

### Task 4: amounts statement

**Files:**
- Create:
  - `includes/Modules/Invoices/Amounts/Money.php`;
  - `includes/Modules/Invoices/Amounts/Line.php`;
  - `includes/Modules/Invoices/Amounts/TotalRow.php`;
  - `includes/Modules/Invoices/Amounts/Statement.php`;
  - `includes/Modules/Invoices/Amounts/StatementBuilder.php`.
- Create (tests): `tests/Support/OrderFactory.php`, `tests/Integration/Invoices/StatementTest.php`

**Interfaces:**
- Produces:
  - `Money::to_minor( float|string $amount ): int`: `(int) round( (float) $amount * 10 ** wc_get_price_decimals() )`.
  - `Money::format( int $minor, string $currency ): string`: plain text through `wc_price` (see Global Constraints).
  - `Line`: readonly; fields:
    - `name` (string);
    - `attributes` (`array<int, array{label: string, value: string}>`);
    - `sku`;
    - `quantity` (int|float);
    - `unit` (int, minor units, before discount);
    - `total` (int, before discount);
    - `tax_rate` (`?float`);
    - `product_id`, `image_id` (int, 0 when none).
  - `TotalRow`: readonly; fields:
    - `key`: `subtotal` | `discount` | `coupons` | `shipping` | `fee` | `rounding` | `total_excl` | `tax` | `total`;
    - `label`;
    - `amount` (`?int`, null for `coupons`);
    - `text` (`?string`, the coupon codes);
    - `grand` (bool);
    - `informational` (bool: shown but not part of the sum).
  - `Statement`: `lines` (`Line[]`), `rows` (`TotalRow[]`), `currency`, `incl_tax` (bool); method `sum(): int`, the sum of the non-informational, non-grand rows with `amount !== null`.
  - `StatementBuilder::build( \WC_Order $order, array $settings ): Statement` (`$settings` = `Module::settings()`).
  - `OrderFactory` (tests), static helpers that create real saved orders with `calculate_totals()`:
    - `tax_rates( array $percents )`: FR rates, `woocommerce_calc_taxes=yes`;
    - `simple_product( float $price, string $tax_class = '' )`;
    - `variable_product( string $attribute_label, string $value, float $price )`;
    - `order( array $lines, array $args = [] )`: `$args` take `coupon` (code, type, amount), `shipping` (amount), `fee` (name, amount), `prices_include_tax` (bool), `currency`.

Calculation rules (spec "Amounts"; `$incl = content.price_display === 'incl'`):
- **Line:**
  - `total = to_minor( subtotal ) + ( $incl ? to_minor( subtotal_tax ) : 0 )`;
  - `unit = (int) round( total / quantity )`, and `unit = total` when the quantity is 0;
  - lines **never** show a discount or a coupon code.
- **Name:** with `content.item_parent_name_enabled` and `$item->get_variation_id() > 0`, use `wc_get_product( $item->get_product_id() )->get_name()`. When the parent is gone, fall back to `$item->get_name()`.
- **Attributes:** come from `$item->get_formatted_meta_data( '_', true )`. The second argument `true` is required: without it, WooCommerce hides the attributes already present in the variation name, and they would disappear once the parent name is shown. Labels and values go through `wp_strip_all_tags`, and the result is empty when `content.item_meta_enabled` is false.
- **Line rate:** the first rate id with a non-zero amount in `$item->get_taxes()['subtotal']`, mapped to the `rate_percent` of the order tax item with that `rate_id`. When `rate_percent` is null (an old order), fall back to the legacy derivation (`derive_tax_rate`, snapping to known rates).
- **Rows, in this order:**
  1. `subtotal` = Σ line totals;
  2. `discount` = `-( discount_total + ( $incl ? discount_tax : 0 ) )`, only when it is not zero;
  3. `coupons` (informational, text = codes joined by `, `), only with `content.total_discount_code_enabled` and at least one coupon;
  4. `shipping` = Σ shipping items (`+ tax` when `$incl`), shown when it is not zero or when `content.total_shipping_always` is set;
  5. one `fee` row per fee item (label = fee name, `+ tax` when `$incl`);
  6. **when excl:** `total_excl` (informational, = Σ above), then one `tax` row per rate (non-informational, label `VAT 20 %`, amount = Σ `tax_total + shipping_tax_total` of the tax items with that percent);
  7. `rounding` = `to_minor( order total ) - sum()` of everything above, **only when it is not zero** (never silently absorbed into another row);
  8. `total` (grand) = `to_minor( $order->get_total() )`;
  9. **when incl:** after the total, `total_excl` and one `tax` row per rate, both informational ("of which").

**Invariant:** `sum() === total` for every statement. The `rounding` row guarantees it.

- [ ] **Step 1: write `OrderFactory` and the failing tests.** Every test also asserts `sum() === total`.
  - `test_lecomte_case_line_shows_price_before_discount`: product 35,00 TTC (20 %), prices entered incl., a 5,00 fixed-cart coupon `LECOMTE5`, incl display. Expected:
    - the line `unit === 3500` and `total === 3500`;
    - `subtotal 3500`;
    - `discount -500`;
    - `coupons` text `LECOMTE5`;
    - `total 3000`;
    - no line contains `LECOMTE5`.
  - `test_excl_display_with_two_rates_and_shipping`: lines at 20 % and 5,5 %, taxed shipping 5,90. Expected:
    - the rows include `total_excl`, two `tax` rows labeled `VAT 20 %` and `VAT 5,5 %`;
    - the sum equals the total.
  - `test_percent_coupon_incl_display`.
  - `test_fees_are_listed_by_name`.
  - `test_rounding_row_only_when_needed`: three lines at 0,99 HT 20 % with `woocommerce_tax_round_at_subtotal=yes` and excl display, either no `rounding` row or one that makes `sum() === total`. Assert both properties.
  - `test_variation_shows_parent_name_and_attribute`: parent "Croquettes", attribute "Conditionnement" = "500 g". Expected `name === 'Croquettes'` and `attributes` contains `['label' => 'Conditionnement', 'value' => '500 g']`.
  - `test_variation_name_falls_back_when_parent_deleted` (Review Focus 1, also covers a deleted variation: amounts come from the item).
  - `test_tax_rate_comes_from_order_tax_item`: after issuance, changing the shop rate to 10 % in `woocommerce_tax_rates` still gives `20.0`.
  - `test_zero_total_order` (Review Focus 3).
  - `test_order_currency_is_used` (Review Focus 5): a `USD` order formats with `$`.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.**
- [ ] **Step 4: run and see them pass, plus `composer check`.**
- [ ] **Step 5: commit** `feat(invoices): single amounts statement with exact totals`.

### Task 5: rendering (HTML and PDF)

**Files:**
- Create:
  - `includes/Modules/Invoices/Rendering/DocumentData.php`;
  - `includes/Modules/Invoices/Rendering/Placeholders.php`;
  - `includes/Modules/Invoices/Rendering/Context.php`;
  - `includes/Modules/Invoices/Rendering/Styles.php`;
  - `includes/Modules/Invoices/Rendering/Images.php`;
  - `includes/Modules/Invoices/Rendering/PdfEngine.php`;
  - `includes/Modules/Invoices/Rendering/Renderer.php`.
- Create (templates): `templates/pdf/invoice.php`, `templates/pdf/partials/{header,parties,message,items,totals,notes,footer}.php`, `templates/pdf/style.css`
- Create (fonts): `assets/fonts/Inter/` (the four TTF files and the OFL licence from `git show v1.1.1:assets/fonts/Inter/...`)
- Test: `tests/Integration/Invoices/RenderingTest.php`

**Interfaces:**
- Consumes: `StatementBuilder::build()`, `Money::format()`, `Module::settings()`.
- Produces:
  - `DocumentData`: readonly; fields `title`, `number`, `date` (int timestamp or 0), `preview` (bool);
  - `Placeholders::resolve( string $text, \WC_Order $order, DocumentData $doc ): string`:
    - legacy tags (`git show v1.1.1:src/Placeholders/PlaceholderResolver.php`);
    - returns **plain text**: templates escape it, which fixes the legacy unescaped placeholders;
  - `Context::build( \WC_Order $order, DocumentData $doc, array $settings ): array<string, mixed>`:
    - the legacy keys `header`, `shop`, `customer`, `shipping`, `meta`, `message`, `columns`, `items`, `totals`, `notes`, `footer`, plus `legal_text`;
    - `items` / `totals` come from the `Statement` (formatted strings), never computed here;
    - filter `lumia_factures_invoice_context`;
  - `Styles::css( array $design ): string`: CSS custom properties from the design settings, plus `templates/pdf/style.css`, plus `@font-face` for Inter when chosen;
  - `Images::data_uri( int $attachment_id, int $max_width ): string`:
    - PNG/JPEG data URI, resized with `wp_get_image_editor`;
    - other formats (WebP, AVIF, GIF) are converted to PNG;
    - when the local file is missing, `wp_safe_remote_get( wp_get_attachment_url() )` with a 5 s timeout and a 5 MB cap;
    - `''` on any failure, with an `error_log` line prefixed `[lumia-factures]`;
  - `PdfEngine::render( string $html, array $design ): string`:
    - `%PDF` bytes, or `''` on failure, logged;
    - options:
      - remote disabled;
      - HTML5 parser;
      - default font `DejaVu Sans`;
      - `chroot` = `[ LUMIA_FACTURES_PATH ]` only (images are data URIs);
      - font cache in `get_temp_dir() . 'lumia-factures-fonts'`;
    - A4 portrait;
    - pagination `Page {PAGE_NUM} / {PAGE_COUNT}` when `content.footer_pagination_enabled`;
    - filter `lumia_factures_dompdf_options`;
  - `Renderer::html( \WC_Order $order, DocumentData $doc, array $settings ): string`;
  - `Renderer::pdf( \WC_Order $order, DocumentData $doc, array $settings ): string`.

The templates port the legacy partials (`git show v1.1.1:templates/default/...`), with these changes:
- CSS classes `lf-*`;
- every value escaped;
- no discount badge on lines;
- the item block prints the name, then one line per attribute (`Label : value`);
- `totals.php` styles `informational` rows in a muted color;
- the `legal_text` block sits under the totals;
- the `rounding` label is "Rounding".

- [ ] **Step 1: write the failing tests:**
  - `test_html_contains_number_date_and_lines`.
  - `test_html_has_no_coupon_code_on_lines`: the coupon code appears exactly once, in the totals.
  - `test_variation_attribute_printed_under_parent_name`.
  - `test_placeholders_are_escaped`: a billing first name `<b>x</b>` in `message_body` `{billing_first_name}` gives `&lt;b&gt;` in the HTML.
  - `test_disabled_fields_are_absent`: `company.siret_enabled=false` keeps the SIRET out of the HTML.
  - `test_pdf_bytes`: `Renderer::pdf()` starts with `%PDF-`.
  - `test_logo_missing_file_still_renders` (Review Focus 4): an attachment whose file was deleted gives a PDF and no `<img` in the header.
  - `test_webp_logo_is_converted_to_png`: skipped when `wp_image_editor_supports( ['mime_type' => 'image/webp'] )` is false; otherwise the data URI starts with `data:image/png`.
  - `test_chroot_is_plugin_dir_only`.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.** Copy the Inter fonts with `git show v1.1.1:<path> > <path>` (binary-safe, through `git cat-file -p`).
- [ ] **Step 4: run and see them pass, plus `composer check`.**
- [ ] **Step 5: commit** `feat(invoices): HTML templates and Dompdf rendering`.

### Task 6: issuing and the immutable archive

**Files:**
- Create: `includes/Modules/Invoices/Storage/Archive.php`, `includes/Modules/Invoices/Documents/Issuer.php`
- Modify: `includes/Modules/Invoices/Module.php` (hook `woocommerce_order_status_changed`)
- Test: `tests/Integration/Invoices/IssuerTest.php`, `tests/Integration/Invoices/ArchiveTest.php`

**Interfaces:**
- Consumes:
  - `Register::*`, `Sequences::*`, `NumberFormat::build()`;
  - `Renderer::pdf()`, `DocumentData`, `Module::settings()`.
- Produces:
  - `Archive::root(): string`: `wp_upload_dir()['basedir'] . '/lumia-factures'`;
  - `Archive::store( string $pdf, string $number, int $issued_at ): array{file: string, checksum: string}|null`:
    - writes to `Y/m/{sanitized number}-{16 hex random}.pdf`;
    - **refuses to overwrite** an existing path;
    - writes `.htaccess` (`Require all denied` + the legacy `Deny from all` fallback) and an empty `index.php` in the root **and** in every created subfolder;
  - `Archive::path( IssuedDocument $doc ): ?string`: the absolute path when the file exists and `realpath` is inside the root;
  - `Archive::verify( IssuedDocument $doc ): bool`: the file exists and its sha256 matches `checksum`;
  - `Issuer::issue( \WC_Order $order ): ?IssuedDocument`. Idempotent: it returns the existing document when there is one;
  - `Issuer::can_issue( \WC_Order $order ): bool`: the status is in `general.trigger_statuses`, filter `lumia_factures_can_issue`;
  - `Issuer::restore( IssuedDocument $doc, \WC_Order $order ): bool`: re-renders **only** when `Archive::verify()` is false, keeps number and date, and adds the order note "Invoice PDF %s was missing or damaged and has been rebuilt from the current order data.";
  - action `lumia_factures_invoice_issued` (`IssuedDocument $doc, \WC_Order $order`).

`Issuer::issue()` flow:
1. `find()`, and return early when the document is issued.
2. Check `can_issue()`.
3. `reserve()`, or `null` → re-`find()` and return it (the order is being issued by another call).
4. `$now = time()`, `key()`, `next()`, `build()`, `assign()`. When `next()` returns `0` or `assign()` fails → `release()`, log, order note "Invoice could not be numbered", return `null`.
5. Write the order meta `_lumia_factures_invoice_number` and `_lumia_factures_invoice_date` (timestamp), then `$order->save()`.
6. Render with a `DocumentData` built from the register row, after `wc_get_order( $id )` reloads the order. This fixes legacy bug C2: the PDF was rendered from a stale `WC_Order` and lacked its number.
7. `Archive::store()` + `attach_file()`.
8. Order note "Invoice %s issued.".
9. Fire the action.

When rendering fails, the number stays assigned (never reissued), the note says "PDF will be built on first download", and `restore()` builds it later.

- [ ] **Step 1: write the failing tests:**
  - `test_status_change_issues_invoice`: an order going `pending → processing` gets a register row with `status=issued`, the meta number, a file present, and a checksum that matches.
  - `test_issue_is_idempotent` (Review Focus 2): two `issue()` calls return the same number, and `Sequences::current()` advanced by 1.
  - `test_issue_when_reserved_by_other_call_does_not_number_twice`: insert a `reserved` row first, `issue()` returns `null` or that row, and the sequence is unchanged.
  - `test_non_trigger_status_does_not_issue`.
  - `test_archived_pdf_never_changes`: issue, then change the billing name and an item quantity and `save()`, then call `issue()` again. The file bytes and checksum are identical.
  - `test_archive_refuses_overwrite`.
  - `test_archive_folders_are_protected`: `.htaccess` and `index.php` in the root and in `Y/m`.
  - `test_restore_only_when_missing`: `restore()` on an intact file returns `false`; after deleting the file, it returns `true`, the number is unchanged, and a note is added.
  - `test_rendered_pdf_contains_number`: the HTML from `Renderer::html()` with the issued `DocumentData` contains the number (regression test for C2).
  - `test_render_failure_keeps_number`: filter `lumia_factures_dompdf_options` to throw, and the number is assigned with `file` empty.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.**
- [ ] **Step 4: run and see them pass.**
- [ ] **Step 5: commit** `feat(invoices): issue on trigger status into a write-once archive`.

### Task 7: email attachments

**Files:**
- Create: `includes/Modules/Invoices/Emails/Attachments.php`
- Modify: `includes/Modules/Invoices/Module.php`
- Test: `tests/Integration/Invoices/EmailAttachmentsTest.php`

**Interfaces:**
- Consumes: `Issuer::issue()`, `Issuer::restore()`, `Archive::path()`, `Register::find()`.
- Produces: the filter callback `Attachments::attach( $attachments, $email_id, $object, $email = null ): array` on `woocommerce_email_attachments` (priority 10, 4 args).

Rules:
- only for a `WC_Order` (not a refund) and an `$email_id` in `general.email_attachments`;
- call `issue()`, which is idempotent: the email can fire before the status hook;
- when the archive file is missing, call `restore()` first;
- attach the archived path itself, with no temporary copy (the legacy temp files were never deleted);
- any `Throwable` is caught and logged, and the email always leaves.

- [ ] **Step 1: write the failing tests:**
  - `test_configured_email_gets_archived_pdf`: the result contains `Archive::path()`.
  - `test_unconfigured_email_gets_nothing`.
  - `test_email_before_status_hook_issues_once`.
  - `test_exception_does_not_block_email`.
  - `test_refund_object_is_ignored`.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.**
- [ ] **Step 4: run and see them pass.**
- [ ] **Step 5: commit** `feat(invoices): attach the archived invoice to WooCommerce emails`.

### Task 8: order screen tools

**Files:**
- Create: `includes/Modules/Invoices/Admin/OrderTools.php`, `includes/Modules/Invoices/Admin/Download.php`
- Modify: `includes/Modules/Invoices/Module.php` (admin hooks, only when `is_admin()`)
- Test: `tests/Integration/Invoices/OrderToolsTest.php`

**Interfaces:**
- Consumes: `Register::find()`, `Issuer::issue()`, `Issuer::restore()`, `Issuer::can_issue()`, `Archive::path()`, `Archive::verify()`.
- Produces:
  - `Download::url( int $order_id ): string`: `admin-post.php?action=lumia_factures_invoices_download&order_id=N` plus the nonce `lumia_factures_invoices_download_{N}`;
  - `Download::file_for( int $order_id ): string`: the absolute path, or `wp_die` with 403/404;
  - `Download::handle(): void`: sends `Content-Type: application/pdf`, `Content-Disposition: inline; filename="{number}.pdf"`, `X-Content-Type-Options: nosniff`, then the file, then exits;
  - admin-post actions `lumia_factures_invoices_issue` and `lumia_factures_invoices_restore` (nonce `lumia_factures_invoices_{action}_{order_id}`, capability `manage_woocommerce`, redirect back with `lumia_factures_notice`);
  - meta box `lumia-factures-invoice` (side) on `shop_order` and `wc_get_page_screen_id( 'shop-order' )`, showing:
    - **issued**: number, date, Download button, and a "Rebuild missing PDF" button only when `verify()` is false;
    - **not issued, can issue**: an "Issue invoice now" button;
    - **otherwise**: a text saying the current status does not allow an invoice;
  - list column `lumia_factures_invoice` after `order_status` on both HPOS (`manage_woocommerce_page_wc-orders_columns` / `_custom_column`) and legacy (`manage_edit-shop_order_columns` / `manage_shop_order_posts_custom_column`): the number linked to `Download::url()`, or `—`.

The meta box uses WordPress core button classes (`button`), because it sits on a WooCommerce screen outside the plugin page and outside the `.lumia-factures-admin` scope.

- [ ] **Step 1: write the failing tests:**
  - `test_file_for_requires_capability`: a customer user gets `WPDieException`.
  - `test_file_for_returns_archived_path`.
  - `test_file_for_unissued_order_is_404`.
  - `test_issue_action_issues_invoice`.
  - `test_restore_action_refuses_intact_file`.
  - `test_column_added_on_hpos_and_legacy_screens`.
  - `test_metabox_shows_number_when_issued`: capture the output of the render callback.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.**
- [ ] **Step 4: run and see them pass.**
- [ ] **Step 5: commit** `feat(invoices): order meta box, list column and admin download`.

### Task 9: settings screen

**Files:**
- Create:
  - `includes/Modules/Invoices/settings-template.php`;
  - `templates/admin/invoices/{general,company,numbering,content,design}.php`;
  - `includes/Modules/Invoices/Admin/Counters.php`;
  - `assets/admin/js/invoices.js`.
- Modify: `includes/Modules/Invoices/Module.php` (`get_admin_js()`, `get_admin_js_data()`, `get_admin_js_deps()` = `['media-editor']`; enqueue `wp_enqueue_media()` on the module tab; register the toast codes through `lumia_factures_admin_notices`)
- Test: `tests/Integration/Invoices/SettingsScreenTest.php`

**Interfaces:**
- Consumes: `Schema::defaults()`, the `Schema` errors transient, `Sequences::all()`, `Sequences::raise()`, `NumberFormat::preview()`, `NumberFormat::validate()`.
- Produces:
  - the module form `lumia-factures-module-form` (already posted by the core `SaveHandler` with tab `module_invoices`);
  - design-system tabs `General | Company | Numbering | Content | Design`. The Design tab hosts the preview pane of Task 10. The active tab is kept in the URL hash, as in the design-system Tabs pattern;
  - admin-post `lumia_factures_invoices_raise_counter` (`sequence_key`, `value`, nonce `lumia_factures_invoices_raise_counter`): toast `counter_raised`, or `counter_not_raised` (error) when the value is not higher.

Field rendering:
- every field uses the design-system form classes (`docs/design-system.md#forms`), and toggles come with the hidden `0` input;
- the logo uses `wp.media` (button plus thumbnail, hidden `company[logo_id]`);
- colors use `<input type="color">`;
- the Numbering tab shows:
  - the format;
  - a live sample (`NumberFormat::preview` through `lumiaFacturesAdmin` data for the tags, computed in JS);
  - the counters table (`Sequences::all()`) with a "Raise" form per row, which states that a counter can never be lowered;
- Schema errors (transient) appear as an inline design-system notice at the top of the form, and the transient is deleted once read.

- [ ] **Step 1: write the failing tests:**
  - `test_settings_form_posts_every_group`: render the template for an admin; the output contains `lumia_factures_module_settings[numbering][format]` and the five tab panels.
  - `test_saving_through_save_handler_persists`: post through `SaveHandler::handle()` with a valid nonce, and `Module::settings()` holds the new values.
  - `test_raise_counter_requires_capability`.
  - `test_raise_counter_refuses_lower_value`.
  - `test_schema_error_is_displayed_once`.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.**
- [ ] **Step 4: run and see them pass.** Then check the screen by hand on wp-env (`http://localhost:8888/wp-admin/admin.php?page=lumia-factures&tab=module_invoices`): every tab, logo picker, save, toast. Take headless screenshots as in lot 1.
- [ ] **Step 5: commit** `feat(invoices): settings screen`.

### Task 10: live preview

**Files:**
- Create: `includes/Modules/Invoices/Admin/Preview.php`
- Modify: `templates/admin/invoices/design.php`, `assets/admin/js/invoices.js`
- Test: `tests/Integration/Invoices/PreviewTest.php`

**Interfaces:**
- Consumes: `Schema::sanitize()`, `Renderer::pdf()`, `NumberFormat::preview()`, `DocumentData`.
- Produces:
  - `Preview::render( array $posted_settings, int $order_id = 0, string $format = 'pdf' ): string|\WP_Error`: `'pdf'` returns the bytes and `'html'` the HTML. Tests use `'html'`, and the AJAX endpoint always uses `'pdf'`.
  - AJAX `wp_ajax_lumia_factures_invoices_preview`.
  - Input:
    - `nonce`;
    - `settings` (the serialized form, same shape as `lumia_factures_module_settings`);
    - optional `order_id`.
  - Output: `wp_send_json_success( ['pdf' => base64, 'order' => '#1234'] )`, or `wp_send_json_error( ['message' => ...] )`.

Rules:
- the posted settings are sanitized with `Schema::sanitize()` and **never saved**;
- the order is the given `order_id` or the latest order (`wc_get_orders( ['limit' => 1, 'orderby' => 'date', 'order' => 'DESC'] )`);
- when there is no order, the error is "Create a first order to preview the invoice.";
- the preview never reserves a number, never writes a file or meta, and never adds a note:
  - `DocumentData` has `preview=true`;
  - the number is the next sample (`NumberFormat::preview( format, Sequences::current(key)+1 or start )`);
  - a "Preview" watermark is printed by `templates/pdf/invoice.php` when `preview` is true;
- JS:
  - debounce 600 ms on `input`/`change` in the Design and Content tabs;
  - base64 → `Blob` → `URL.createObjectURL` → `<iframe>` (revoke the previous URL);
  - a "Refresh" button;
  - an order id field.

- [ ] **Step 1: write the failing tests:**
  - `test_preview_requires_nonce_and_capability`.
  - `test_preview_returns_pdf_without_side_effects`: decoded bytes start with `%PDF-`; there is no register row, `Sequences::current()` is unchanged, there is no order note, and no file exists under `Archive::root()`.
  - `test_preview_uses_unsaved_settings`: `Preview::render( [ 'content' => [ 'invoice_title' => 'Proforma' ] ], $order_id, 'html' )` contains `Proforma`, and `Module::settings()` is unchanged.
  - `test_preview_without_orders_returns_error`.
- [ ] **Step 2: run and see them fail.**
- [ ] **Step 3: implement.**
- [ ] **Step 4: run and see them pass, plus a manual check on wp-env.**
- [ ] **Step 5: commit** `feat(invoices): live PDF preview`.

### Task 11: translations and documentation

**Files:**
- Modify: `languages/lumia-factures.pot`, `languages/lumia-factures-fr_FR.po`, `languages/lumia-factures-fr_FR.mo`, `docs/modules/invoices.md`, `docs/core.md` (hooks list), `README.md`, `CLAUDE.md`

- [ ] **Step 1: `composer i18n:pot`, then translate every new string.** Required wording (legal labels):

  | English | French |
  |---|---|
  | Invoice | Facture |
  | Invoice number: | N° de facture : |
  | Invoice date: | Date de facture : |
  | Order number: | N° de commande : |
  | Order date: | Date de commande : |
  | Payment method: | Moyen de paiement : |
  | Subtotal | Sous-total |
  | Discount | Remise |
  | Coupon code | Code promo |
  | Shipping | Livraison |
  | Rounding | Arrondi |
  | Total excl. VAT | Total HT |
  | VAT %s | TVA %s |
  | Total | Total TTC |
  | Bill to: | Facturer à : |
  | Ship to: | Livrer à : |
  | Preview | Aperçu |

  Then `composer i18n:mo` and `composer i18n:check` (exit 0).
- [ ] **Step 2: `docs/modules/invoices.md`:**
  - architecture;
  - the issue flow;
  - each pitfall with what broke and why:
    - C1 `insert_id`;
    - C2 stale order;
    - `get_formatted_meta_data( '_', true )`;
    - the transaction-free atomicity;
    - temporary tables in tests;
    - the `rounding` row;
    - the never-deleted archive;
    - the Nginx rule.
- [ ] **Step 3: `docs/core.md` hooks list, the README feature list, and `CLAUDE.md`** (the module is no longer a skeleton; the "Invoicing invariants" section points to the tests that pin each one).
- [ ] **Step 4: `composer check`, `npm test`, `composer i18n:check`.** All green.
- [ ] **Step 5: commit** `docs: invoice engine` and `feat(i18n): French translation of the invoice engine`.
