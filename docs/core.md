# Plugin core

Architecture, contracts and pitfalls of the core (`includes/Core/`, `includes/Admin/`, `AbstractModule`). Each module has its own page under [modules/](README.md#modules). The core is the lumia-tools v2.2.2 core, renamed and trimmed to what this plugin uses.

## Boot sequence

```
plugins_loaded -> Plugin::instance() (singleton)
  |- Settings, Modules, Updater::init(), Admin   <- built whatever the requirements
  `- init hook
       |- load_textdomain()
       `- boot()
            |- Requirements::check()
            |- errors?  -> all_admin_notices notice, STOP (no module starts)
            |- Modules::register_default_modules()  <- filter lumia_factures_module_definitions
            `- Modules::init_active_modules()       <- Module::init() on every active module
```

`lumia-factures.php` also loads `vendor/autoload.php` when readable (Dompdf), registers the autoloader, declares HPOS and cart/checkout-blocks compatibility, and wires the activation/deactivation hooks. `Plugin` exposes `modules()`, `settings()`, `updater()`, `admin()` and `requirement_errors()`; tests rely on those accessors.

## Requirements gate

`Requirements::check()` runs on `init` (WooCommerce and the legacy plugin may load after this one) and returns `reason => message` for each blocking problem:

| Key | Condition |
|---|---|
| `php` | PHP < 8.1 |
| `wp` | WordPress < 6.9 |
| `woocommerce` | WooCommerce missing, or < 9.0 |
| `dompdf` | `Dompdf\Dompdf` cannot be loaded (`vendor/` missing or damaged in the installed ZIP) |
| `legacy_active` | the old `facture-pdf-lumia` plugin is active (it defines `FPL_VERSION`) |

With any error, no module starts. `Plugin::render_requirements_notice()` prints the list for users with `activate_plugins`, on **`all_admin_notices`** and not `admin_notices`: lumia-tools buffers `admin_notices` into its notification drawer, which would hide a blocking error. The `lumia_factures_requirements_wc_active` and `lumia_factures_requirements_dompdf_class` filters are test seams, not extension points.

The **updater is registered before and independently of this check**: a site blocked by a missing dependency must still be able to receive a fixed version. `Admin` is built as well, so the page can explain why nothing runs.

## Autoloader

`Lumia\Factures\` maps directly to `includes/`: `Lumia\Factures\Modules\Invoices\Module` -> `includes/Modules/Invoices/Module.php`. Home-grown, no Composer autoloading of the plugin's own code. Dompdf is the only runtime Composer dependency and is autoloaded from `vendor/autoload.php`.

## Settings storage

| Scope | Option key | Access |
|---|---|---|
| Global plugin | `lumia_factures_settings` | `Settings::get('global.update_channel')` (dot notation) |
| Module state | `lumia_factures_settings` -> `modules.{id}` | `Settings::get('modules.bricks')` |
| Module settings | `lumia_factures_module_{id}` | `AbstractModule::get_module_settings()` |

Unlike lumia-tools, `Settings` keeps no copy of the option on the instance: every read goes through `get_option()` (already cached in `alloptions`). A private copy went stale as soon as the option was written elsewhere.

`AbstractModule::get_module_settings()` merges the stored values over the defaults **recursively** (associative arrays are descended, lists replaced as a whole). `wp_parse_args()` alone only merges the first level: a sub-key added in a later version would be missing from existing installs.

`Activator::activate()` creates the options with their defaults (only if absent: a reactivation keeps the settings). `Activator::MODULE_CLASSES` is the single module list shared with `uninstall.php`, which does not boot the plugin.

## Module system

Each module extends `AbstractModule` (implements `ModuleInterface`). Required: `init()` (register hooks here), `get_settings()`, `save_settings()` (sanitize and persist yourself, the core applies nothing), `static get_defaults()`, `static get_uninstall_keys()`. Optional overrides: `get_admin_css()`, `get_admin_js()`, `get_admin_js_deps()`, `get_admin_js_data()`, `get_required_capability()`, `on_activate()`, `on_deactivate()`.

**`init()` timing.** A module `init()` runs inside the `init` action at priority 10. `woocommerce_init`, `woocommerce_loaded` and `before_woocommerce_init` have therefore already fired: a callback hooked on them from a module never runs. Likewise, an `init` callback added from a module at priority <= 10 never runs (that priority is being dispatched); use a later priority.

Modules are defined in `Modules::register_default_modules()` and extended through the `lumia_factures_module_definitions` filter (array of `id => [name, description, menu_label, menu_desc, class, icon, locked]`) plus the `lumia_factures_register_modules` action. Current modules:

| Id | Class | Notes |
|---|---|---|
| `invoices` | `Modules\Invoices\Module` | `locked => true`: always active, `activate()` / `deactivate()` are no-ops for it |
| `bricks` | `Modules\Bricks\Module` | optional, inactive by default |

`get_uninstall_keys()` declares `options`, `meta` (**post** meta), `user_meta`, `post_type`, `taxonomy`, `tables` (own tables, without prefix) and `cron`; every key is optional. A key filed in the wrong channel is never deleted.

`get_required_capability()` is `manage_woocommerce` (shop managers handle invoices). It guards the module tab in `Admin::render_page()` as well as the menu: the tab URL is guessable.

### Adding a module

1. Create `includes/Modules/MyModule/Module.php` extending `AbstractModule`.
2. Register it in `Modules::register_default_modules()` (or through the filter).
3. Add the class to `Activator::MODULE_CLASSES`.
4. Add `docs/modules/<module>.md` and a row in [README.md](README.md#modules).
5. Any change under `includes/Modules/Invoices/` needs PHPUnit integration tests (see [CLAUDE.md](../CLAUDE.md#deviations-from-lumia-tools)).

### `ABSPATH` guard

Every PHP file carries `defined( 'ABSPATH' ) || exit;` after the `namespace` line (otherwise after the docblock). Without it a direct request to a template runs it outside WordPress and discloses server paths.

## Admin page

`Admin` registers **one** submenu, **WooCommerce > Invoices** (slug `lumia-factures`, capability `manage_woocommerce`, `admin_menu` priority 60). The parent is `woocommerce` and can be changed with the `lumia_factures_menu_parent` filter. There is no top-level menu: navigation is the internal sidebar, `admin.php?page=lumia-factures&tab=<tab>` with `dashboard` (default), `modules`, `settings`, and `module_{id}` for an active module. An unknown tab or an inactive module falls back to `dashboard`. Templates: `templates/admin/` (layout, dashboard, modules, settings, module-settings) and `templates/components/sidebar.php`.

Assets load on the plugin page only (`$hook === hook_suffix`): `tokens.css` first (it only holds custom properties), then reset, layout, sidebar, components, buttons, notifications, `admin.js` and `notifications.js`, plus the CSS/JS of the displayed module tab. See [design-system.md](design-system.md).

### Forms

All forms post to `admin-post.php`; `SaveHandler` verifies a nonce and `manage_woocommerce`, then redirects with `lumia_notice` (shown as a toast):

| Action | Nonce field | Purpose |
|---|---|---|
| `lumia_factures_save_settings` | `lumia_factures_nonce` | global settings (update channel, automatic updates) and activation of the optional modules (locked ones are skipped) |
| `lumia_factures_check_updates` | `lumia_factures_check_nonce` | purge the update caches and check now |

The automatic-update toggle writes WordPress's own `auto_update_plugins` site option (single source of truth, nothing stored in the plugin settings).

### AJAX

Naming `wp_ajax_lumia_factures_{module}_{action}`; every handler starts with `check_ajax_referer( 'lumia_factures_admin_nonce', 'nonce' )` then a capability check, and answers with `wp_send_json_success()` / `wp_send_json_error()`. The nonce is localized as `lumiaFacturesAdmin.nonce`. No endpoint exists yet in lot 1.

### JS globals and strings

Every global is namespaced `lumiaFactures*` (lumia-tools defines `lumiaAdmin`, `lumiaModal`... on the same admin); see the table in [design-system.md](design-system.md#coexistence-with-lumia-tools). JS strings are passed from PHP in `lumiaFacturesAdmin.i18n`, so a single `.mo` serves PHP and JS: no `wp.i18n`, no JSON translation files.

### Icons

Inline SVGs rendered by `Admin::render_icon()` -> `Admin\Icons::render()`, from Lucide v1.34.0 official files only. A missing icon is fetched from Lucide as is, never drawn or edited; `Icons::FALLBACK` is `package`.

## Translations

Text domain `lumia-factures`, loaded on `init`. The procedure to add a string is in [CLAUDE.md](../CLAUDE.md#translations-text-domain-lumia-factures); the tooling (`tools/i18n/`) in its [README](../tools/i18n/README.md). Only `languages/lumia-factures-fr_FR.po` is edited by hand; the `.pot` and `.mo` are generated, and `composer i18n:check` (also run by CI) fails when they drift.

## Updater

`Core/Updater.php` reads the public repository `agence-lumia/lumia-factures` through the GitHub API and hooks `pre_set_site_transient_update_plugins` and `plugins_api`.

- **Channels.** `stable` (releases) or `dev` (highest version among pre-releases and stable releases, so a dev site also sees stable releases), stored in `global.update_channel`. The release asset must match `lumia-factures-*.zip`.
- **Cache.** The response is cached in a transient (`lumia_factures_github_update_{channel}`) for 12 h, and **a failure for 15 min** (negative cache): an unreachable GitHub or an exhausted anonymous quota (60 req/h) must not cost a slow HTTP call on every admin page. "Check for updates" deletes both transients first. `get_status()` only reads the transient, so displaying the status never triggers an HTTP call. Caches are purged on channel change and after the plugin is updated.
- **Optional token.** `LUMIA_FACTURES_GITHUB_TOKEN` (wp-config.php) raises the API quota. It is attached **only** to `https://api.github.com/repos/agence-lumia/lumia-factures/...` URLs (`is_repo_api_url()`), never to another repository or host, and it is stripped from the request when a redirect leaves the GitHub API (the asset endpoint answers 302 to a storage host that rejects it and must not receive it). Both behaviors are covered in `tests/Integration/Core/UpdaterTest.php`.
- **Changelog.** Requested as `application/vnd.github.html+json` (already rendered), passed through `wp_kses_post()`; on the dev channel the notes of the last 10 versions are cached.
- **Always on.** The updater runs even when `Requirements::check()` fails.

## Uninstall

`uninstall.php` does **nothing** unless `LUMIA_FACTURES_REMOVE_ALL_DATA` is defined to `true` in `wp-config.php`: invoices must be kept 10 years. When set, it deletes the global option and, for each class of `Activator::MODULE_CLASSES`, the module option and whatever `get_uninstall_keys()` declares. Never add data deletion outside this gate.

## Development tooling

Composer holds the dev tooling **and** the one runtime dependency (Dompdf). No PHP on the machine: everything goes through Docker or wp-env.

```bash
docker run --rm -v "${PWD}:/app" -w /app composer:2 check    # PHPCS (WPCS 3 + PHPCompatibilityWP) then PHPStan level 8
docker run --rm -v "${PWD}:/app" -w /app composer:2 lint:fix # phpcbf
npm run env:start                                            # wp-env: WordPress + WooCommerce, plugin mapped from the working tree
npm test                                                     # PHPUnit 9.6 in the wp-env tests-cli container
```

- **Tests.** `tests/Integration/` (Admin, Core: boot, modules, updater) with `tests/Support/TestCase.php`; `tests/fixtures/lumia-tools-stub.php` simulates lumia-tools being active (coexistence test). CI runs them on PHP 8.3 and 8.5 (`WP_ENV_PHP_VERSION`). `composer.json` pins the platform to PHP 8.1.99 so one `vendor/` works in every container.
- **No baseline.** PHPCS and PHPStan run without one: any finding blocks CI. Fix it, or add a justified `phpcs:ignore` / `@phpstan-ignore`. PHPStan stops at level 8.
- **CI.** `lint.yml` (PHPCS, PHPStan, PHP syntax, language-file gate, ZIP build and content check) and `tests.yml` on every PR to `dev` / `main`; `release-dev.yml` on push to `dev`; `release-please.yml` by manual dispatch.

### Release zip

`tools/build/build-zip.sh <version> <output.zip>` is the only build, called by both release workflows and the `build` job of `lint.yml`:

1. installs the runtime dependencies into the staging folder with `composer install --no-dev --optimize-autoloader --classmap-authoritative` (Dompdf ships; the dev `vendor/` never does);
2. copies the working tree without what `tools/build/zip-excludes.txt` lists (`.github`, `tools`, `tests`, `docs`, `CLAUDE.md`, `composer.*`, `package*.json`, `.wp-env.json`, `.superpowers`...) and without the `.po` / `.pot`;
3. compiles each `languages/*.po` into a `.l10n.php` next to the `.mo` (WordPress 6.5+ reads it first);
4. minifies `assets/**/*.js` / `*.css` with a pinned esbuild (transform mode, no bundling);
5. fails if Dompdf is missing, if a dev dependency or a development folder ends up in the ZIP, or if an asset, a `.mo` or a `.l10n.php` is missing; the zip's only top folder is `lumia-factures/`.

Without PHP on the machine, run it in a container (`composer:2` image plus `apk add bash rsync zip unzip nodejs npm`, `--entrypoint sh`).

### Version and releases

The version lives in `* Version:` and `LUMIA_FACTURES_VERSION` of `lumia-factures.php` and is bumped by the workflows only. A push to `dev` publishes `X.Y.Z-dev.N`; stable releases are triggered with `gh workflow run release-please.yml -f bump=patch|minor|major`.
