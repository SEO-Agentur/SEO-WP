# SEO-WP — Repository Audit

**Date:** 2026-09-16
**Auditor:** Claude (Principal Engineer pass), via 5 parallel partition audits + direct inspection
**Scope:** SEO-WP plugin only. slim-hreflang (companion plugin) referenced only as an external dependency/contract, not separately audited here.
**Method:** Source code is the ground truth. Every claim below was traced to an actual file/line, not inferred from docblocks or readme.txt.

---

## 1. Repository state (Phase 0 findings)

- Git: branch `main`, working tree clean at time of audit, **no tags, no releases, no remote configured** — this repository has never been pushed anywhere.
- 15+ commits, informal but consistent messages (`1.4.2: ...` style); no `feat:`/`fix:` conventional-commit prefixes used historically — adopt going forward per this plan.
- No CI/CD (`.github/` does not exist).
- Version `1.4.2` consistent across `seo-wp.php` header, `const VERSION`, and `readme.txt` `Stable tag`.
- `composer.json`: `require: {"php": ">=8.1"}`, `require-dev` only (phpcs, wpcs, phpcompatibility, phpunit) — **zero runtime dependencies**, nothing needs to ship inside the plugin zip.
- `.gitignore`: `/vendor/`, `/node_modules/`, `composer.lock`, `.phpunit.result.cache`, `.DS_Store`.

## 2. Bootstrap / entry-point map

```
seo-wp.php
 ├─ ABSPATH guard
 ├─ defines: VERSION, FILE, DIR, BASENAME
 ├─ require Autoloader.php; Autoloader::register()
 ├─ register_activation_hook   → Plugin::activate()
 ├─ register_deactivation_hook → Plugin::deactivate()
 └─ add_action('plugins_loaded', [Plugin::instance(), 'init'])

Plugin::init()
 ├─ load_plugin_textdomain('seo-wp', ...)
 ├─ unconditional:
 │   Head::register()          → wires 10 frontend head modules (see below)
 │   Sitemaps\Router::register()
 │   Wpml\StringTranslation::register()
 │   Admin\RoleManager::register()   — unconditional: user_has_cap must run everywhere
 │   Admin\LinkAudit::register()     — unconditional: its WP-Cron hook fires on wp-cron.php (never is_admin())
 │   Crawl\RobotsTxt, LlmsTxt, Verification, SpecialArchives, IndexNow,
 │   ImageLinks, Redirects, NotFoundMonitor, GoogleIndexing
 │   Performance\ServerHeaders, Optimizations
 │   Crawl\StagingProtection
 └─ if is_admin():
     Admin\Metabox, Settings, BulkEditor, ImportWizard, Diagnostics,
     RedirectsPage, NotFoundMonitorPage, GoogleIndexingPage, IndexNowPage,
     DuplicateChecker, ContentFreshness, AiSettings

Frontend\Head::register()
 └─ FragmentCache, ResourceHints, Meta\TitleDescription, Meta\Robots,
    Meta\Canonical, Social\OpenGraph, Social\TwitterCards, Schema\GraphBuilder,
    Wpml\Hreflang, Frontend\CriticalCss

Plugin::activate()   → add_option('seo_wp_settings', [], '', 'yes'); flush_rewrite_rules()
Plugin::deactivate() → wp_clear_scheduled_hook('seo_wp_check_links_batch'); flush_rewrite_rules()
uninstall.php         → WP_UNINSTALL_PLUGIN-guarded, deletes 3 options (see Phase 3 for completeness gap)
```

Every `new X()->register()` call in `Plugin.php`/`Head.php` corresponds to a real, present class file — no dangling bootstrap references found.

## 3. Module inventory (by directory)

| Directory | Files | Role |
|---|---|---|
| `src/Meta/` | 3 | Title, description, canonical, robots meta output |
| `src/Schema/` | 2 + `Nodes/` (8) | JSON-LD `@graph` builder + 8 node types |
| `src/Social/` | 2 | Open Graph, Twitter Cards |
| `src/Frontend/` | 3 | Head orchestration, resource hints, critical CSS |
| `src/Sitemaps/` | 9 | XML sitemap routing/rendering, image/video/hreflang extensions |
| `src/Wpml/` | 2 | Hreflang computation + `seo_wp/hreflang/*` contract, string translation |
| `src/Crawl/` | 10 | Redirects, 404 monitor, robots.txt, llms.txt, IndexNow, Google Indexing, staging protection, verification, special archives, attachment-link stripping |
| `src/Performance/` | 4 | .htaccess rules, opt-in optimization toggles, resource-hint scanner, server headers |
| `src/Admin/` | 14 | Every wp-admin screen (largest module) |
| `src/Support/` | 8 | AI/DeepL HTTP clients, Ajax guard, content renderer, error log, fragment cache, staging detector, URL helper |
| `src/Templates/` | 2 | `{token}` template renderer + cross-plugin token-syntax conversion |
| `src/Import/` | 8 | Yoast/Rank Math/AIOSEO detection + migration |
| Root | `seo-wp.php`, `Plugin.php`, `Autoloader.php`, `uninstall.php` | Bootstrap |

**Total: 79 PHP files in `src/`, 32 test files in `tests/Unit/`.**

## 4. Infrastructure facts

- **No REST API endpoints** registered by SEO-WP itself (all admin interactivity is `admin-ajax.php`-based).
- **No custom database tables** (`dbDelta`) — all persistence is `wp_options` (single `seo_wp_settings` array plus ~15 dedicated option keys) and post/term/user meta.
- **No WP-CLI commands.**
- **One WP-Cron consumer**: `Admin\LinkAudit` (recurring scan schedule via `cron_schedules` filter, correctly registered; plus one-off job-tick events for the background worker).
- **Autoloader** (`src/Autoloader.php`): simple, correct PSR-4-style `SeoWp\` → `src/` mapping via `spl_autoload_register`; confirmed by `tests/Unit/AutoloaderTest.php` (root + nested namespace resolution, foreign-namespace no-op).
- **`languages/`** contains only `.gitkeep` — no `.pot`/`.po`/`.mo` files exist anywhere, despite `Domain Path: /languages` being declared and `load_plugin_textdomain()` being called.
- **`.distignore`**: excludes `.git`, `.github`, `tests`, `docs`, `.gitignore`, `.distignore`, `composer.json`, `composer.lock`, `phpcs.xml.dist`, `vendor`, `.phpunit.result.cache`. `phpunit.xml.dist` is **not** excluded (ships orphaned in the release zip — cosmetic only).
- **Test harness ceiling** (`tests/bootstrap/wp-stubs.php`): stubs only pure-logic surface — `add_filter`/`apply_filters`/`has_filter` (real in-memory hook system), `get_option`/`update_option`/`delete_option` (in-memory), `wp_parse_url`, `esc_url_raw`, `trailingslashit`, `wp_json_encode`, `maybe_unserialize`, `is_multisite`/`get_site_option`. **Not stubbed**: `$wpdb`, `WP_Query`, any `get_*_meta`/`update_*_meta`, `wp_remote_*`, `wp_cache_*`, `wp_send_json_*`, `check_ajax_referer`, `current_user_can`, `setup_postdata`, `home_url`, `deactivate_plugins`. This is a deliberate, documented scope decision, not an oversight — but it means **every class whose real logic touches the database, HTTP, or the object cache has zero automated coverage of that logic**, only of any pure-static helpers extracted from it.

## 5. Cross-cutting findings that affect the whole repo

1. **Only 2 custom hook prefixes exist in the entire plugin**: `seo_wp/*` (title, meta_description, canonical, opengraph/tags, schema/graph, hreflang/alternates, hreflang/managed_externally) and none else — Twitter Cards has no equivalent filter (asymmetry noted in Phase 3).
2. **`seo_wp_settings` is the dominant storage mechanism** — one autoloaded option array holding ~70+ sub-keys across every module. No sub-module owns a competing settings store.
3. **Escaping discipline is consistently good** except one confirmed gap (title tag — see Phase 3/Release Scope).
4. **Every `wp_remote_*` call in the plugin fires only from an explicit, capability-and-nonce-gated admin action or a real content-publish event** — no automatic/hidden telemetry found anywhere in `src/`.
5. **uninstall.php is significantly incomplete** relative to its own stated intent ("removes the plugin's own options") — full list in `UNFINISHED-FEATURES.md`.

Full per-feature detail is in `FEATURE-CATALOGUE.md`/`.json`. Stub/dormant/bug forensics are in `UNFINISHED-FEATURES.md`.
