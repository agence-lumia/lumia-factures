# Lot 1: Foundations. Implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** replace the 1.x code with the `lumia-factures` skeleton built on the lumia-tools conventions:
- core (modules, settings, activation, updater);
- admin shell with the design system;
- full tooling (PHPCS/PHPStan, wp-env + PHPUnit, i18n, ZIP build, CI, releases).

At the end of this lot, the plugin activates, shows its empty admin with modules, updates itself from GitHub, and produces a release ZIP. No invoice logic yet: that is lot 2.

**Architecture:** files from lumia-tools **v2.2.2** are copied and then renamed (namespace `Lumia\Tools` → `Lumia\Factures`, prefixes `lumia_` → `lumia_factures_`, constants `LUMIA_` → `LUMIA_FACTURES_`, text domain). Everything that is specific to lumia-tools modules is removed. The design-system CSS keeps its classes and tokens `lumia-` / `--lumia-*` **unchanged**, so it stays identical to lumia-tools. The CSS is scoped under `.lumia-factures-admin`, so it does not collide with lumia-tools when both are active.

**Tech Stack:** PHP 8.1+ (CI 8.3 and 8.5), WP 6.9+ (tested 7.1), WooCommerce 9.0+ (tested 11.2), Dompdf ^3.1 (runtime), `@wordpress/env`, PHPUnit 9.6 + `yoast/phpunit-polyfills`, WPCS 3, PHPStan 2 level 8, esbuild 0.28.2, WP-CLI 2.12.0.

**Spec:** `docs/superpowers/specs/2026-10-19-lumia-factures-v2-design.md`

## Global Constraints

- **Reference source:** `gh repo clone agence-lumia/lumia-tools ../lumia-tools-ref -- --branch v2.2.2 --depth 1`. Every "copy from" points to this tree.
- **Old code:**
  - tag the 1.x code `v1.1.1-legacy` before deleting it (`git tag v1.1.1-legacy main`);
  - lot 2 ports it with `git show v1.1.1-legacy:<path>`.
- **Language:**
  - code, comments, docblocks, UI strings, `docs/`, `README.md` and `CLAUDE.md` in **English**, with no accented French characters (only "Lümia" is allowed);
  - French lives only in `languages/lumia-factures-fr_FR.po/.mo`;
  - `docs/superpowers/` stays in French.
- **Guard:** `defined( 'ABSPATH' ) || exit;` on every PHP file (after `namespace`).
- **Never bump the version by hand.** The CI does it in `* Version:` and `LUMIA_FACTURES_VERSION`.
- **Capabilities:**
  - plugin admin = `manage_woocommerce`;
  - every form → `admin-post.php` + nonce + capability check + `wp_unslash` then sanitizer;
  - every AJAX call → `check_ajax_referer( 'lumia_factures_admin_nonce', 'nonce' )` + capability check.
- **Icons:** Lucide v1.34.0 only, official SVG copied as is.
- **Git:**
  - branch `dev`, one issue per task, branch `feat|chore|ci|docs/<n>-<subject>`, PR to `dev`;
  - commits in English Conventional Commits ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **No local PHP:**
  - PHP tests → `npx wp-env run tests-cli --env-cwd=wp-content/plugins/lumia-factures vendor/bin/phpunit` (shortened to `npm test`);
  - composer → `docker run --rm -v "${PWD}:/app" -w /app composer:2 <cmd>`.

## Review Focus

1. **lumia-tools and lumia-factures active at the same time (the Neonat case).** No class, function, constant, option, AJAX action, nonce, menu slug or CSS/JS handle may collide. The lumia-tools admin must keep working. Owner: Task 3, test `test_coexists_with_lumia_tools`.
2. **WooCommerce missing or deactivated after activation.** No fatal error; an admin notice; the modules do not start. Owner: Task 2, test `test_does_not_boot_without_woocommerce`.
3. **The old `facture-pdf-lumia` plugin is still active when `lumia-factures` is activated.** Both must not run in parallel (double email attachment, two counters). In lot 1: blocking admin notice + no `Invoices` module started as long as the old plugin is active. The migration itself comes in lot 3. Owner: Task 2, test `test_refuses_to_run_alongside_legacy_plugin`.
4. **GitHub unreachable or quota exhausted.** No slowdown on admin pages (negative cache), no PHP error. Owner: Task 4, test `test_failed_check_is_negatively_cached`.
5. **ZIP installed with `vendor/` missing or corrupted.** Clear error message instead of a fatal error when Dompdf is first used. Owner: Task 2, test `test_requirements_report_missing_dompdf`.

---

### Task 1: clean up the repo and set up the tooling

**Files:**
- Remove: `src/`, `templates/`, `assets/`, `vendor/`, `facture-pdf-lumia.php`, `uninstall.php`, `CHANGELOG.md`, `.gitattributes`, `phpcs.xml.dist`, `.github/workflows/release.yml`
- Create:
  - `composer.json`: `require` contains `php >=8.1` and `dompdf/dompdf ^3.1`; `require-dev` matches lumia-tools, plus `phpunit/phpunit ^9.6` and `yoast/phpunit-polyfills ^3.0`; scripts `lint`, `lint:fix`, `analyse`, `check`, `test`, `i18n:pot`, `i18n:mo`, `i18n:check`.
  - `phpcs.xml.dist`: copy, then text domain `lumia-factures`, prefixes `lumia_factures`, `LUMIA_FACTURES`, `Lumia\Factures`, `minimum_wp_version 6.9`, `testVersion 8.1-`.
  - `phpstan.neon.dist`: copy, level 8, plus `szepeviktor/phpstan-wordpress` and WooCommerce stubs (`php-stubs/woocommerce-stubs`).
  - `tools/phpstan-bootstrap.php`.
  - `.gitignore`: copy, plus `vendor/`, `node_modules/`, `/dist/`.
  - `.wp-env.json`: `{"phpVersion":"8.3","plugins":["https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip"],"mappings":{"wp-content/plugins/lumia-factures":"."}}`.
  - `package.json`: `@wordpress/env` pinned, scripts `env:start`, `test`.
  - `phpunit.xml.dist`, `tests/bootstrap.php` (loads WooCommerce then `lumia-factures.php` on `muplugins_loaded`, `WC_Install::install()` on `setup_theme`), `tests/Support/TestCase.php`.
  - `tools/i18n/` (copy, with `wp.sh` pinned and its SHA-512).
  - `tools/build/build-zip.sh` and `zip-excludes.txt` (copy and adapt, see step 3).
  - `.github/ISSUE_TEMPLATE/*`, `.github/PULL_REQUEST_TEMPLATE.md` (copies).

- [ ] **Step 1:** `git tag v1.1.1-legacy main`, then remove the listed files.
- [ ] **Step 2:** create the tooling files above.
- [ ] **Step 3:** adapt `build-zip.sh`:
  - run `composer install --no-dev --optimize-autoloader --classmap-authoritative` into a staging folder before the rsync;
  - remove `/vendor` from `zip-excludes.txt`;
  - replace the "vendor identical byte for byte" check with "`vendor/dompdf/dompdf/src/Dompdf.php` present and no `vendor/phpunit`";
  - root folder `lumia-factures/`, asset `lumia-factures-<version>.zip`.
- [ ] **Step 4:** `composer install` (Docker), then `npm i`, then `npx wp-env start`. Expected: the environment starts with WooCommerce active.
- [ ] **Step 5:** commit `chore: replace legacy code with lumia-tools based tooling`.

### Task 2: bootstrap, autoloader, core and requirements

**Files:**
- Create `lumia-factures.php`, `uninstall.php`.
- Create in `includes/Core/`: `Autoloader.php`, `Plugin.php`, `Settings.php`, `ModuleInterface.php`, `AbstractModule.php`, `Modules.php`, `Activator.php`, `Deactivator.php`, `Requirements.php`.
- Test `tests/Integration/Core/BootTest.php`, `tests/Integration/Core/ModulesTest.php`.

**Interfaces:**
- **Main file header:**
  - `Plugin Name: Lümia Factures`;
  - `Plugin URI` / `Update URI: https://github.com/agence-lumia/lumia-factures`;
  - `Version:     2.0.0-dev.0`;
  - `Requires at least: 6.9`, `Requires PHP: 8.1`, `Requires Plugins: woocommerce`;
  - `WC requires at least: 9.0`, `WC tested up to: 11.2`;
  - `Text Domain: lumia-factures`.
  - HPOS and `cart_checkout_blocks` declarations on `before_woocommerce_init`.
- **Constants:** `LUMIA_FACTURES_VERSION`, `_PLUGIN_FILE`, `_PLUGIN_DIR`, `_PLUGIN_URL`, `_INCLUDES_DIR`, `_TEMPLATES_DIR`, `_ASSETS_URL`.
- **Copied classes:** `Autoloader`, `Plugin`, `Settings`, `ModuleInterface`, `AbstractModule`, `Modules`, `Activator`, `Deactivator`. They are copied from lumia-tools and renamed:
  - option `lumia_factures_settings`, module options `lumia_factures_module_{id}`;
  - filter `lumia_factures_module_definitions`;
  - `AbstractModule::get_required_capability()` returns `manage_woocommerce`.
- **`Requirements::check(): array<int, string>`**: list of the blocking errors.
  - PHP < 8.1, WP < 6.9, WooCommerce missing or < 9.0;
  - `Dompdf\Dompdf` not loadable;
  - legacy plugin active (constant `FPL_VERSION` defined).
  - `Plugin` only starts the modules when the list is empty; otherwise it shows `admin_notices` with the reasons.
- **Initial modules:**
  - `Invoices`: core, `'locked' => true`, cannot be deactivated, empty `Module` class with `init()`.
  - `Bricks`: optional, inactive by default, empty `Module` class.
  - Lot 2 fills them in.

- [ ] **Step 1:** tests

```php
public function test_plugin_boots_with_woocommerce(): void {
	$this->assertTrue( defined( 'LUMIA_FACTURES_VERSION' ) );
	$this->assertTrue( \Lumia\Factures\Core\Plugin::instance()->modules()->is_active( 'invoices' ) );
}
public function test_textdomain_loaded_on_init(): void {
	$this->assertGreaterThan( 0, has_action( 'init', array( \Lumia\Factures\Core\Plugin::instance(), 'load_textdomain' ) ) );
}
public function test_invoices_module_cannot_be_deactivated(): void {
	\Lumia\Factures\Core\Plugin::instance()->modules()->deactivate( 'invoices' );
	$this->assertTrue( \Lumia\Factures\Core\Plugin::instance()->modules()->is_active( 'invoices' ) );
}
public function test_optional_module_toggles(): void { /* bricks: inactif par défaut, activate() → actif, deactivate() → inactif */ }
public function test_activation_creates_default_options_without_overwriting(): void {
	update_option( 'lumia_factures_settings', array( 'global' => array( 'update_channel' => 'dev' ) ) );
	\Lumia\Factures\Core\Activator::activate();
	$this->assertSame( 'dev', \Lumia\Factures\Core\Settings::get( 'global.update_channel' ) );
}
public function test_does_not_boot_without_woocommerce(): void {
	add_filter( 'lumia_factures_requirements_wc_active', '__return_false' );
	$this->assertNotEmpty( \Lumia\Factures\Core\Requirements::check() );
}
public function test_refuses_to_run_alongside_legacy_plugin(): void {
	define( 'FPL_VERSION', '1.1.1' ); // @runInSeparateProcess
	$this->assertContains( 'legacy_active', array_keys( \Lumia\Factures\Core\Requirements::check() ) );
}
public function test_requirements_report_missing_dompdf(): void {
	add_filter( 'lumia_factures_requirements_dompdf_class', fn() => 'Missing\\Dompdf' );
	$this->assertArrayHasKey( 'dompdf', \Lumia\Factures\Core\Requirements::check() );
}
```

  `Requirements::check()` returns an array keyed by reason: `php`, `wp`, `woocommerce`, `dompdf`, `legacy_active`. The two filters used in the tests exist only for that reason; document them as `@internal`.
- [ ] **Step 2:** `npm test -- --filter Core` → FAIL.
- [ ] **Step 3:** implement. `uninstall.php` is copied and reads `get_uninstall_keys()` from every module. It only deletes data when `LUMIA_FACTURES_REMOVE_ALL_DATA` is true, because invoices must be kept for 10 years.
- [ ] **Step 4:** `npm test` → PASS. `composer check` → 0 errors.
- [ ] **Step 5:** commit `feat(core): plugin bootstrap, modules and requirements`.

### Task 3: admin shell and design system

**Files:**
- Create `includes/Admin/Admin.php` (copy, cut down to menu, render, icons, notices, save handler, modules and settings pages).
- Create `templates/admin/{layout,dashboard,modules,settings,module-settings}.php` and `templates/components/sidebar.php` (copies).
- Create `assets/admin/css/{tokens,reset,layout,sidebar,components,buttons,notifications}.css` and `assets/admin/js/{admin,notifications}.js` (copies).
- Test `tests/Integration/Admin/AdminTest.php`.

**Interfaces:**
- **Menu:**
  - `add_submenu_page( apply_filters( 'lumia_factures_menu_parent', 'woocommerce' ), __( 'Invoices', 'lumia-factures' ), __( 'Invoices', 'lumia-factures' ), 'manage_woocommerce', 'lumia-factures', … )`, placed in the WooCommerce menu (fr: "Factures"), where the old "Facture PDF" sat.
  - No top-level menu and no toggle: navigation between sections (Overview, Modules, active modules, Settings) only happens through the internal sidebar of the lumia-tools layout, with `&tab=`.
- **Wrapper:** `.lumia-admin-wrap.lumia-factures-admin`. Every selector in `reset.css` is prefixed with `.lumia-factures-admin`.
- **Handles:** `lumia-factures-*`. JS object `window.lumiaFacturesAdmin` (`nonce`, `ajaxUrl`, `i18n`).
- **Form actions:** `lumia_factures_save_settings`, nonce `lumia_factures_nonce`.
- **Sidebar:** Overview, Modules, then active modules, then Settings.

- [ ] **Step 1:** tests

```php
public function test_menu_registered_under_woocommerce_for_shop_manager(): void {
	wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
	do_action( 'admin_menu' );
	global $submenu;
	$this->assertContains( 'lumia-factures', array_column( $submenu['woocommerce'] ?? array(), 2 ) );
}
public function test_save_settings_requires_nonce_and_capability(): void { /* POST sans nonce → wp_die ; abonné → wp_die ; admin + nonce → option mise à jour + redirection avec lumia_notice */ }
public function test_coexists_with_lumia_tools(): void {
	// Charger une copie minimale de lumia-tools (fixture tests/fixtures/lumia-tools-stub.php qui déclare
	// LUMIA_VERSION, la classe Lumia\Tools\Core\Plugin, le slug de menu 'lumia-tools' et le handle 'lumia-admin')
	// puis vérifier : nos handles sont distincts, nos options distinctes, aucun "Cannot redeclare".
}
```

- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** implement (copy then rename). Overview page: plugin version, state of the requirements (`Requirements::check()`), active modules, shortcuts.
- [ ] **Step 4:** run → PASS. Visual check: `npx wp-env start`, then open `http://localhost:8888/wp-admin/admin.php?page=lumia-factures` and compare with the lumia-tools admin (screenshot attached to the PR).
- [ ] **Step 5:** commit `feat(admin): admin shell with Lumia design system`.

### Task 4: GitHub updater

**Files:** Create `includes/Core/Updater.php` (copy) · Modify `templates/admin/settings.php` (channel + "Check for updates" + auto-update toggle) · Test `tests/Integration/Core/UpdaterTest.php`

**Interfaces:**
- `Updater::REPO = 'agence-lumia/lumia-factures'`.
- Transient `lumia_factures_github_update_{channel}` (12 h) + negative cache (15 min).
- Asset `/^lumia-factures-.*\.zip$/`.
- `User-Agent: LumiaFactures`.
- **Optional token:**
  - `LUMIA_FACTURES_GITHUB_TOKEN`, used only if defined;
  - sent only when `wp_parse_url( $url, PHP_URL_HOST ) === 'api.github.com'` and the scheme is `https`;
  - public static method `Updater::is_api_url( string $url ): bool`.

- [ ] **Step 1:** tests (HTTP mocked with `pre_http_request`)

```php
public function test_injects_update_when_newer_release(): void { /* release 2.0.1 + asset zip → transient->response['lumia-factures/lumia-factures.php']->new_version === '2.0.1' */ }
public function test_dev_channel_picks_highest_prerelease(): void { /* releases 2.0.0, 2.0.1-dev.2, 2.0.1-dev.1 → 2.0.1-dev.2 */ }
public function test_failed_check_is_negatively_cached(): void { /* 1er appel → 500 ; 2e appel → aucune requête HTTP (compteur de pre_http_request = 1) */ }
public function test_token_only_sent_to_github_api(): void {
	$this->assertTrue( Updater::is_api_url( 'https://api.github.com/repos/a/b/releases/latest' ) );
	$this->assertFalse( Updater::is_api_url( 'https://api.github.com.evil.tld/x' ) );
	$this->assertFalse( Updater::is_api_url( 'https://evil.tld/?u=api.github.com' ) );
	$this->assertFalse( Updater::is_api_url( 'http://api.github.com/x' ) );
}
```

- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** implement.
- [ ] **Step 4:** run → PASS.
- [ ] **Step 5:** commit `feat(core): GitHub release updater with stable and dev channels`.

### Task 5: i18n, CI and releases

**Files:** Create `languages/lumia-factures.pot`, `languages/lumia-factures-fr_FR.po/.mo`, `.github/workflows/lint.yml`, `tests.yml`, `release-dev.yml`, `release-please.yml` (copies adapted to the slug)

- [ ] **Step 1:**
  - `composer i18n:pot`;
  - translate every string into `fr_FR.po`, matching the lumia-tools vocabulary (copy the matching translations from `lumia-tools-fr_FR.po`);
  - `composer i18n:mo` then `composer i18n:check`. Expected: 0 untranslated strings.
- [ ] **Step 2:** workflows.
  - `lint.yml`: copy (lint, i18n and build jobs).
  - `tests.yml`: PHP 8.3 / 8.5 matrix; `npm ci`, `npx wp-env start` (with `WP_ENV_PHP_VERSION`), composer install, `npm test`.
  - `release-dev.yml` and `release-please.yml`: copies, with `sed` on `LUMIA_FACTURES_VERSION` and the `lumia-factures.php` header.
- [ ] **Step 3:** `bash tools/build/build-zip.sh 2.0.0-dev.0 dist/test.zip` (through Docker or Git Bash). Expected:
  - the ZIP contains `lumia-factures/vendor/dompdf/` and `lumia-factures/languages/lumia-factures-fr_FR.l10n.php`;
  - it has no `tests/`, `docs/`, `tools/` or `CLAUDE.md`.
- [ ] **Step 4:** install the ZIP in wp-env (`npx wp-env run cli wp plugin install /var/www/html/wp-content/plugins/lumia-factures/dist/test.zip --force` is not suitable because of the mapping: use a second environment, or `wp plugin install` from a copy outside the mapping). Expected: activation without error and admin in French.
- [ ] **Step 5:** commit `ci: lint, tests, build and release workflows`.

### Task 6: project documentation

**Files:** Create `CLAUDE.md`, `README.md`, `docs/README.md`, `docs/core.md`, `docs/design-system.md` (copy with the "tokens are in tokens.css" fix), `docs/modules/invoices.md` (placeholder: invariants from the spec, filled in lot 2)

- [ ] **Step 1:** `CLAUDE.md`.
  - Copy the lumia-tools absolute rules, adapted to the slug.
  - Add the **invoicing invariants** (spec, "Invariants" section).
  - Document the two deviations: `vendor/` with Dompdf, and PHPUnit tests that are mandatory for any change to `Modules/Invoices`.
- [ ] **Step 2:** `README.md`. Sections:
  - what it does;
  - requirements;
  - installation (release ZIP), updates (stable/dev channels);
  - storage protection under Nginx (`location ^~ /wp-content/uploads/lumia-factures/ { deny all; }`);
  - development (wp-env, `npm test`, `composer check` through Docker);
  - release process.
- [ ] **Step 3:** check that `grep -rP "[éèàùçêâîôû]" includes templates assets docs/*.md README.md CLAUDE.md .github` returns nothing (apart from "Lümia").
- [ ] **Step 4:** commit `docs: project documentation`.
