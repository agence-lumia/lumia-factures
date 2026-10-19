# Lot 1: reliability. Implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** fix the invoice numbering, make issued invoices immutable, close the security weaknesses and set up a test base. All of this keeps the current identifier (`facture-pdf-lumia`, namespace `Lumia\FacturePDF`, prefix `fpl_`).

**Architecture:**
- The existing classes are fixed in place.
- Persistent PDF archiving replaces the cache expiry for issued invoices.
- An order status hook assigns the number.
- Every behaviour is covered by WordPress + WooCommerce integration tests, run by PHPUnit inside wp-env (Docker).

**Tech Stack:** PHP 8.1+, WordPress 7.1, WooCommerce 11.2, Dompdf 3.1.6, `@wordpress/env`, PHPUnit 9.6 + `yoast/phpunit-polyfills`, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-19-lumia-factures-v2-design.md`

## Global Constraints

- PHP ≥ 8.1, `declare( strict_types=1 );`, WPCS style (tabs, `array()`, Yoda conditions), French comments and strings, like the existing code.
- No local PHP: every PHP command goes through `npx wp-env run tests-cli --env-cwd=wp-content/plugins/facture-pdf-lumia <cmd>`. Below this is shortened to `WPENV <cmd>`.
- A number that has been assigned is never changed or reassigned. An archived PDF is never overwritten, except by the explicit admin regeneration, which is logged.
- Order access only through the WC CRUD (`get_meta`, `update_meta_data`, `save`). Never `get_post_meta`, since HPOS must keep working.
- Branch `v2`. One commit per task, in French, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Neonat already has numbers made wrong by bug C1** (e.g. `FA-2026-48213`) while the counter is at 2. The next number must never repeat a number already issued. Owner: Task 4, test `test_skips_number_already_issued`.
2. **Invoice issued under 1.x with its cached PDF already purged.** The first access after 2.0 regenerates it and archives it with the number and issue date already stored, without consuming a new number. Owner: Task 5, test `test_legacy_issued_invoice_without_file_is_archived_with_same_number`.
3. **The customer email is sent in the same request as the status change.** The order object passed by WooCommerce to `woocommerce_email_attachments` does not yet carry the number meta. The attached PDF must still have it. Owner: Task 6, test `test_completed_email_attachment_contains_number`.
4. **Order going from completed to refunded or cancelled.** The issued invoice stays downloadable and unchanged. Owner: Task 5, test `test_archived_pdf_survives_status_change`.
5. **Stale lock (a request crashed while it held the lock).** Taking it back must be atomic: two takers do not both win. Owner: Task 3, test `test_stale_lock_is_taken_over_once`.

---

### Task 1: test infrastructure, CI and vendor built at release time

**Files:**
- Create: `.wp-env.json`, `package.json`, `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/Support/TestCase.php`, `tests/Support/OrderFactory.php`, `tests/Integration/PluginBootTest.php`, `.github/workflows/tests.yml`
- Modify: `composer.json` (require-dev + scripts), `.gitignore` (ignore the whole `vendor/`, add `node_modules/`), `.gitattributes` (export-ignore `tests/`, `docs/`, `.wp-env.json`, `package.json`, `package-lock.json`, `phpunit.xml.dist`, `CLAUDE.md`), `.github/workflows/release.yml` (`composer install --no-dev --optimize-autoloader` before the ZIP; the ZIP includes `vendor/`)
- Remove from the index: `vendor/` (`git rm -r --cached vendor`)

**Interfaces:**
- Produces: `Lumia\FacturePDF\Tests\Support\TestCase` (extends `WP_UnitTestCase`):
  - `set_setting(string $dotted_key, mixed $value): void` writes to `fpl_settings` and resets the `SettingsRepository` cache;
  - `tear_down()` empties the `wp_fpl_sequences` table and the storage folder.
- Produces: `OrderFactory::create(array $args = array()): \WC_Order`
  - accepted args: `status` (default `'pending'`), `customer_id` (0 = guest), `items` (list of `['price' => float, 'qty' => int, 'tax_class' => string]`), `billing` (array).
  - It creates the simple products, applies taxes if `wc_tax_enabled()`, calls `calculate_totals()` then `save()`.

- [ ] **Step 1:** `.wp-env.json`
  - `{"core": null, "phpVersion": "8.3", "plugins": ["https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip"], "mappings": {"wp-content/plugins/facture-pdf-lumia": "."}}`.
  - The mapping keeps the folder name independent of the checkout name.
  - `package.json` with devDependency `@wordpress/env` pinned to an exact version, and the scripts `"env:start": "wp-env start"`, `"test": "wp-env run tests-cli --env-cwd=wp-content/plugins/facture-pdf-lumia vendor/bin/phpunit"`.
- [ ] **Step 2:** `composer.json`
  - require-dev `phpunit/phpunit:^9.6`, `yoast/phpunit-polyfills:^3.0`.
  - `autoload-dev` PSR-4 `Lumia\\FacturePDF\\Tests\\` → `tests/`.
  - script `"test": "phpunit"`.
- [ ] **Step 3:** `tests/bootstrap.php`
  - Reads `WP_TESTS_DIR` (provided by wp-env, `/wordpress-phpunit`).
  - Loads `functions.php` from the test suite.
  - On `muplugins_loaded`, requires `woocommerce/woocommerce.php` then `facture-pdf-lumia.php`.
  - On `setup_theme`, runs `WC_Install::install()` and `Lumia\FacturePDF\Support\Installer::activate()`.
  - Then requires `bootstrap.php` from the suite.
  - `phpunit.xml.dist`: testsuite `tests/Integration`.
- [ ] **Step 4:** write `PluginBootTest`.

```php
public function test_plugin_is_booted(): void {
	$this->assertTrue( defined( 'FPL_VERSION' ) );
	$this->assertNotNull( \Lumia\FacturePDF\Plugin::instance()->service( 'cleaner' ) );
}
public function test_sequences_table_exists(): void {
	global $wpdb;
	$this->assertSame( \Lumia\FacturePDF\Numbering\Sequence::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', \Lumia\FacturePDF\Numbering\Sequence::table() ) ) );
}
public function test_order_factory_creates_completed_order(): void {
	$order = \Lumia\FacturePDF\Tests\Support\OrderFactory::create( array( 'status' => 'completed' ) );
	$this->assertSame( 'completed', $order->get_status() );
	$this->assertGreaterThan( 0, (float) $order->get_total() );
}
```

  (Check the real service id in `Plugin::register_services()` and use it in the first test.)
- [ ] **Step 5:** `npm i && npx wp-env start && WPENV composer install && npm test`. Expected: 3 tests, OK.
- [ ] **Step 6:** `.github/workflows/tests.yml`
  - on push/PR: Node LTS, `npm ci`, `npx wp-env start`, `WPENV composer install`, `npm test`.
  - plus a `phpcs` job (`WPENV composer lint`), non-blocking (`continue-on-error: true`) until lot 2.
- [ ] **Step 7:** commit `test: infrastructure wp-env + PHPUnit, vendor construit en CI`.

### Task 2: the counter reads the right value after the UPDATE (C1)

**Files:** Modify `src/Numbering/Sequence.php:75-90` · Test `tests/Integration/Numbering/SequenceTest.php`

**Interfaces:** Produces `Sequence::next(string $key, int $start = 1): int` (unchanged signature, fixed behaviour).

- [ ] **Step 1:** test

```php
public function test_next_increments_even_after_unrelated_insert(): void {
	$this->assertSame( 1, Sequence::next( 'invoice' ) );
	add_option( 'fpl_test_noise', 'x' ); // fait bouger insert_id de la connexion
	$this->assertSame( 2, Sequence::next( 'invoice' ) );
	$this->assertSame( 3, Sequence::next( 'invoice' ) );
	$this->assertSame( 3, Sequence::current( 'invoice' ) );
}
public function test_new_key_starts_at_start_value(): void {
	$this->assertSame( 100, Sequence::next( 'invoice_2026', 100 ) );
	$this->assertSame( 101, Sequence::next( 'invoice_2026', 100 ) );
}
```

- [ ] **Step 2:** `npm test -- --filter SequenceTest` → FAIL (second assertion returns the `option_id`).
- [ ] **Step 3:**
  - After a successful UPDATE, read `(int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' )` instead of `$wpdb->insert_id`.
  - Fix the class docblock, which states that `insert_id` works.
- [ ] **Step 4:** test → PASS.
- [ ] **Step 5:** commit `fix(numérotation): lire LAST_INSERT_ID() après l'UPDATE du compteur`.

### Task 3: the document sees its number right away; atomic lock (C2, M1)

**Files:** Modify `src/Documents/Document.php`, `src/Numbering/NumberStore.php:82-89,221-240` · Test `tests/Integration/Numbering/NumberStoreTest.php`

**Interfaces:**
- Produces `Document::refresh_order(): void`: replaces `$this->order` with `wc_get_order( $this->order->get_id() )` when the reload succeeds.
- Produces `NumberStore::acquire_lock()` / `release_lock()` (still private), with atomic takeover.

- [ ] **Step 1:** tests

```php
public function test_document_exposes_number_right_after_assignment(): void {
	$order = OrderFactory::create( array( 'status' => 'completed' ) );
	$doc   = DocumentRegistry::invoice( $order );
	$number = $doc->assign_number();
	$this->assertNotSame( '', $number );
	$this->assertSame( $number, $doc->get_number() );
	$this->assertNotNull( $doc->get_date() );
}
public function test_rendered_html_contains_number_on_first_generation(): void {
	$order = OrderFactory::create( array( 'status' => 'completed' ) );
	$doc   = DocumentRegistry::invoice( $order );
	$doc->assign_number();
	$this->assertStringContainsString( $doc->get_number(), Renderer::render( $doc ) );
}
public function test_stale_lock_is_taken_over_once(): void {
	$order = OrderFactory::create( array( 'status' => 'completed' ) );
	add_option( 'fpl_lock_invoice_' . $order->get_id(), time() - 60, '', false );
	$doc = DocumentRegistry::invoice( $order );
	$this->assertNotSame( '', $doc->assign_number() );
	$this->assertFalse( get_option( 'fpl_lock_invoice_' . $order->get_id() ) );
	$this->assertSame( 1, Sequence::current( 'invoice' ) );
}
```

  For the takeover, also test the private method through `ReflectionMethod`: two successive calls of `acquire_lock` on an expired lock → `true` then `false`.
- [ ] **Step 2:** run → FAIL on the first two tests.
- [ ] **Step 3:** implement.
  - In `NumberStore::assign()`, after `$order->save()`, call `$document->refresh_order()`. Also call it on the `return $already` path.
  - Lock busy: loop 8 × 250 ms on `$document->refresh_order(); $document->get_number()`, return as soon as it is non-empty.
  - Atomic takeover of an expired lock:
    - `$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", time(), $key, (string) $since ) )`;
    - the lock is acquired only if `1 === $affected`;
    - then `wp_cache_delete( $key, 'options' )`.
- [ ] **Step 4:** run → PASS.
- [ ] **Step 5:** commit `fix(numérotation): document rafraîchi après attribution, verrou repris atomiquement`.

### Task 4: number uniqueness, period tags, protected counter (H2, H3)

**Files:**
- Modify: `src/Numbering/NumberStore.php`, `src/Numbering/Sequence.php`, `src/Admin/NumberingTab.php:43-56,196-206`, `src/Plugin.php` (hook)
- Test: `tests/Integration/Numbering/UniquenessTest.php`

**Interfaces:**
- Produces `NumberStore::number_exists(string $number, string $type, int $exclude_order_id = 0): bool`.
  - Implemented with `wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'meta_query' => array( array( 'key' => $meta_key, 'value' => $number ) ), 'exclude' => ... ) )`, so it works under HPOS and legacy.
- Produces `Sequence::has_type(string $type): bool`: true if a key `= $type` or `LIKE $type\_%` exists.
- Produces `NumberingTab::guard_settings(array $settings): array`, hooked on `fpl_pre_save_settings`.
- Produces `Sequence::set(string $key, int $value): bool`, which now refuses (`false` + `Logger::warning`) any value lower than `current($key)`.

- [ ] **Step 1:** tests

```php
public function test_skips_number_already_issued(): void {
	$this->set_setting( 'numbering.format', 'FA-{number:4}' );
	$old = OrderFactory::create( array( 'status' => 'completed' ) );
	$old->update_meta_data( '_fpl_invoice_number', 'FA-0002' );
	$old->save();
	Sequence::set( 'invoice', 1 );
	$doc = DocumentRegistry::invoice( OrderFactory::create( array( 'status' => 'completed' ) ) );
	$this->assertSame( 'FA-0003', $doc->assign_number() );
}
public function test_start_number_only_applies_to_first_sequence_of_type(): void {
	$this->set_setting( 'numbering.start_number', 500 );
	Sequence::set( 'invoice_2025', 812 );
	$this->set_setting( 'numbering.reset_enabled', true );
	$this->set_setting( 'numbering.format', 'FA-{year}-{number:4}' );
	$doc = DocumentRegistry::invoice( OrderFactory::create( array( 'status' => 'completed' ) ) );
	$this->assertStringEndsWith( '-0001', $doc->assign_number() );
}
public function test_reset_without_period_tag_is_refused(): void {
	$settings = NumberingTab::guard_settings( array( 'numbering' => array( 'format' => 'FA-{number}', 'reset_enabled' => true, 'reset_period' => 'yearly' ) ) );
	$this->assertFalse( (bool) $settings['numbering']['reset_enabled'] );
}
public function test_enabling_yearly_reset_seeds_period_key_from_global_key(): void {
	Sequence::set( 'invoice', 42 );
	NumberingTab::guard_settings( array( 'numbering' => array( 'format' => 'FA-{year}-{number}', 'reset_enabled' => true, 'reset_period' => 'yearly' ) ) );
	$this->assertSame( 42, Sequence::current( 'invoice_' . wp_date( 'Y' ) ) );
}
public function test_counter_cannot_be_lowered(): void {
	Sequence::set( 'invoice', 10 );
	$this->assertFalse( Sequence::set( 'invoice', 9 ) );
	$this->assertSame( 10, Sequence::current( 'invoice' ) );
}
```

  Match the shape of `$settings` (nested arrays) to the one `SettingsRepository::save()` really receives, and adjust the tests if needed.
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** implement.
  - In `assign()`:
    - `$start = Sequence::has_type( $type ) ? 1 : $start_number`;
    - loop at most 50 times on `next()` + `build()` while `number_exists()`, each skipped value logged with `Logger::warning`;
    - after 50 skips → `Logger::error` and `return ''`.
  - `guard_settings()`:
    - yearly reset requires `{year}`, monthly reset requires `{year}` and `{month}`; otherwise `Notices::add_error()` and `reset_enabled` is forced to the previous value;
    - when the reset goes from off to on and the current-period key does not exist, `Sequence::set( current_key, Sequence::current( 'invoice' ) )`.
  - Update the confirmation message of the counter form ("only a higher value is accepted").
- [ ] **Step 4:** run → PASS (Tasks 2-3 included).
- [ ] **Step 5:** commit `fix(numérotation): unicité garantie, tags de période exigés, compteur non diminuable`.

### Task 5: archived, immutable invoice; temp files and storage protection (H1, M2, M5)

**Files:**
- Modify: `src/Storage/FileStore.php`, `src/Storage/PdfGenerator.php`, `src/Storage/Cleaner.php`, `src/Admin/OrderMetabox.php:196-230`, `src/Settings/Schema.php:104` (remove `retention_days`), `src/Settings/Migrator.php` (schema +1: remove the key), `src/Admin/DocumentationTab.php` (copy)
- Test: `tests/Integration/Storage/ArchiveTest.php`

**Interfaces:**
- `FileStore::has_cache()`, `is_expired()`, `expires_at()`, `retention_days()` are removed. Callers are updated, including `OrderMetabox` (expiry display).
- Produces `FileStore::archived_path(Document $document): ?string`, an alias of the current `path()`, which is renamed.
- Produces `FileStore::archive(Document $document, string $contents): ?string`:
  - writes `Y/m/<file>-<token>.pdf` and the meta `file` / `generated_at`;
  - refuses (`null`) if an archive already exists.
- Produces `FileStore::replace_archive(Document $document, string $contents, int $user_id): ?string`: used only by the admin regeneration; adds an order note "PDF de la facture %s régénéré par %s".
- Produces `PdfGenerator::contents(Document $document): string` (the `$force` parameter is removed) and `PdfGenerator::regenerate(Document $document, int $user_id): string`.
- Produces `Cleaner::run(): int`: purges only `get_temp_dir()/fpl-temp/*.pdf` older than 1 h. It never touches the storage folder.

- [ ] **Step 1:** tests

```php
public function test_first_generation_is_archived_and_served_afterwards(): void { /* contents() deux fois → même chemin archivé, même octets */ }
public function test_settings_change_does_not_alter_archived_pdf(): void { /* contents(), set_setting('company.name','Autre'), contents() → octets identiques */ }
public function test_cleaner_never_deletes_archives(): void { /* archive, touch(filemtime -400 jours), Cleaner::run() → fichier toujours présent */ }
public function test_cleaner_purges_old_temp_files(): void { /* fichier dans fpl-temp vieux de 2 h → supprimé */ }
public function test_legacy_issued_invoice_without_file_is_archived_with_same_number(): void {
	/* commande avec metas _fpl_invoice_number='FA-2026-0007', _fpl_invoice_date=<ts>, sans fichier ;
	   contents() → archive créée, get_number() toujours 'FA-2026-0007', Sequence::current('invoice') inchangé */
}
public function test_archived_pdf_survives_status_change(): void { /* contents(), $order->update_status('refunded'), is_available() true, archived_path() inchangé */ }
public function test_every_storage_subdirectory_has_index_php(): void { /* après archive : base/Y/index.php et base/Y/m/index.php existent */ }
```

  (Write the bodies following the inline comments; "same bytes" = `assertSame( md5_file(...), md5_file(...) )`.)
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** implement.
  - `PdfGenerator::contents()` reads the archive if it exists, otherwise generates then calls `archive()`.
  - `file()` returns the archive path. The temp file is only used if archiving fails.
  - `protect_directory()` is applied to the base, the year folder and the month folder.
  - Migrator: remove `general.retention_days` from the stored settings.
  - Metabox:
    - the "Régénérer" link only for `manage_woocommerce`, with a JS `confirm()` ("L'original archivé sera remplacé…");
    - shows "Archivée le …" instead of the expiry;
    - redirect fallback `OrderUtil::get_order_admin_edit_url( $order_id )`.
  - Remove the `fpl_retention_days` filter, and mention it in the CHANGELOG.
- [ ] **Step 4:** run → PASS.
- [ ] **Step 5:** commit `feat(stockage): facture archivée et immuable, purge limitée aux fichiers temporaires`.

### Task 6: number assigned at the trigger status; reliable email attachment (H4)

**Files:** Create `src/Numbering/StatusListener.php` · Modify `src/Plugin.php` (register the service), `src/Emails/Attacher.php:52-66` · Test `tests/Integration/Numbering/StatusListenerTest.php`

**Interfaces:**
- Produces `StatusListener::register(): void`: hooks `woocommerce_order_status_changed` (priority 5, so before the WooCommerce emails sent on the same hook).
- Produces `StatusListener::on_status_changed(int $order_id, string $from, string $to, \WC_Order $order): void`: if `$to` is in `general.trigger_statuses`, `DocumentRegistry::invoice( $order_id )?->assign_number()`. No PDF rendering here.
- Changes `Attacher::maybe_attach`: the document is built from the **id** (`DocumentRegistry::invoice( $object->get_id() )`), never from `$object`.

- [ ] **Step 1:** tests

```php
public function test_number_assigned_when_order_completed(): void {
	$order = OrderFactory::create( array( 'status' => 'processing' ) );
	$order->update_status( 'completed' );
	$this->assertNotSame( '', DocumentRegistry::invoice( $order->get_id() )->get_number() );
}
public function test_no_number_for_non_trigger_status(): void {
	$order = OrderFactory::create( array( 'status' => 'pending' ) );
	$order->update_status( 'on-hold' );
	$this->assertSame( '', DocumentRegistry::invoice( $order->get_id() )->get_number() );
}
public function test_numbers_follow_completion_order(): void { /* A créée puis B ; B complétée avant A → numéro(B) < numéro(A) */ }
public function test_completed_email_attachment_contains_number(): void {
	$this->set_setting( 'general.email_attachments', array( 'customer_completed_order' ) );
	$order = OrderFactory::create( array( 'status' => 'processing' ) );
	$stale = wc_get_order( $order->get_id() ); // objet chargé avant attribution
	$order->update_status( 'completed' );
	$files = ( new Attacher() )->maybe_attach( array(), 'customer_completed_order', $stale );
	$this->assertCount( 1, $files );
	$this->assertStringContainsString( DocumentRegistry::invoice( $order->get_id() )->get_number(), Renderer::render( DocumentRegistry::invoice( $order->get_id() ) ) );
}
```

- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** implement `StatusListener` and the `Attacher` change.
- [ ] **Step 4:** run → PASS.
- [ ] **Step 5:** commit `feat(numérotation): numéro attribué au passage du statut déclencheur`.

### Task 7: security: updater, placeholders, Dompdf chroot (M3, M4)

**Files:** Modify `src/Updater/GitUpdater.php:165,207`, `src/Placeholders/PlaceholderResolver.php:37-50`, `src/Rendering/PdfEngine.php:153-158` · Test `tests/Integration/SecurityTest.php`

**Interfaces:**
- Produces `GitUpdater::is_api_url(string $url): bool`: `'https' === scheme && 'api.github.com' === host` (via `wp_parse_url`), public static so it can be tested.
- Changes `PlaceholderResolver::resolve()`: every substituted value goes through `esc_html()`. Callers that output plain text (if any) must use `wp_specialchars_decode()`; check the 4 calls in `ContextBuilder` (lines 348, 349, 883, 926, 927).
- Dompdf chroot = `array( FPL_PLUGIN_DIR, self::font_cache_dir() )` only.

- [ ] **Step 1:** tests

```php
public function test_token_only_sent_to_github_api(): void {
	$this->assertTrue( GitUpdater::is_api_url( 'https://api.github.com/repos/a/b/releases/latest' ) );
	$this->assertFalse( GitUpdater::is_api_url( 'https://api.github.com.evil.tld/x' ) );
	$this->assertFalse( GitUpdater::is_api_url( 'https://evil.tld/?u=api.github.com' ) );
	$this->assertFalse( GitUpdater::is_api_url( 'http://api.github.com/x' ) );
}
public function test_placeholders_are_escaped(): void {
	$order = OrderFactory::create( array( 'billing' => array( 'first_name' => '<img src=x>' ) ) );
	$this->assertSame( 'Merci &lt;img src=x&gt;', PlaceholderResolver::resolve( 'Merci {customer_first_name}', $order ) );
}
public function test_pdf_renders_with_restricted_chroot(): void {
	$order = OrderFactory::create( array( 'status' => 'completed' ) );
	$this->assertStringStartsWith( '%PDF', PdfGenerator::contents( DocumentRegistry::invoice( $order ) ) );
}
```

  (Check the exact placeholder name for the first name in `PlaceholderResolver::values()`.)
- [ ] **Step 2:** run → FAIL on the first two.
- [ ] **Step 3:** implement, then upload a logo in the test (`self::factory()->attachment->create_upload_object()` + setting `company.logo`) and check that the PDF still contains an image (`/Subtype /Image`).
- [ ] **Step 4:** run → PASS.
- [ ] **Step 5:** commit `fix(sécurité): jeton limité à api.github.com, placeholders échappés, chroot Dompdf restreint`.

### Task 8: WordPress 7.1 / WooCommerce 11.2 compatibility

**Files:** Modify `facture-pdf-lumia.php:1-20`, `src/Plugin.php:85,159-166`, `src/Support/Requirements.php` (`FPL_MIN_WP` 6.5) · Test `tests/Integration/PluginBootTest.php` (add)

- [ ] **Step 1:** test

```php
public function test_textdomain_loaded_on_init_not_before(): void {
	$this->assertGreaterThan( 0, has_action( 'init', array( \Lumia\FacturePDF\Plugin::instance(), 'load_textdomain' ) ) );
}
public function test_header_declares_woocommerce_dependency(): void {
	$data = get_file_data( FPL_PLUGIN_FILE, array( 'requires' => 'Requires Plugins', 'tested' => 'WC tested up to' ) );
	$this->assertSame( 'woocommerce', $data['requires'] );
	$this->assertSame( '11.2', $data['tested'] );
}
```

- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:**
  - Headers: `Requires at least: 6.5`, `Requires Plugins: woocommerce`, `WC tested up to: 11.2`.
  - `load_textdomain()` becomes public and is hooked on `init` instead of being called in `boot()`.
- [ ] **Step 4:** run the full suite → PASS, no "translation loading triggered too early" notice in the output (`WP_DEBUG` is active in wp-env tests).
- [ ] **Step 5:** commit `chore(compat): en-têtes WP 7.1 / WC 11.2, Requires Plugins, traductions sur init`.

### Task 9: project documentation

**Files:** Create `README.md`, `CLAUDE.md` · Modify `.gitignore` (stop ignoring `CLAUDE.md`), `CHANGELOG.md` (`## [Unreleased]` section with everything from lot 1)

- [ ] **Step 1:** `README.md` (French), sections:
  - purpose;
  - requirements (PHP 8.1, WP 6.5, WC 8.0; tested 7.1/11.2);
  - installation from the release ZIP;
  - `wp-config.php` constants (`FPL_UPDATE_REPO`, `FPL_UPDATE_TOKEN`, `FPL_REMOVE_ALL_DATA`);
  - **Nginx** rule `location ~* /wp-content/uploads/facture-pdf-lumia/ { deny all; }`;
  - development (`npm run env:start`, `npm test`, `composer lint`);
  - release (tag `vX.Y.Z`, CHANGELOG entry required);
  - immutability and numbering rules.
- [ ] **Step 2:** `CLAUDE.md`:
  - architecture by folder (one line per `src/` subfolder);
  - the business invariants of the spec ("Rules that apply to the whole 2.x");
  - test commands;
  - commit conventions;
  - pointer to `docs/superpowers/specs/`.
  - It must not repeat the README.
- [ ] **Step 3:** check `git archive HEAD | tar -t` (through `git archive --format=tar HEAD | tar -t | grep -E '^(tests|docs|CLAUDE)'`) → empty output.
- [ ] **Step 4:** commit `docs: README, CLAUDE.md versionné, changelog du lot 1`.
