# SEO-WP — Feature Catalogue

76 discovered capabilities, code → capability (not marketing → assumed implementation). Full per-feature evidence (files, line numbers, hooks, storage keys, security notes) lives in the 5 partition-audit transcripts this catalogue was synthesized from; this document is the authoritative index. Machine-readable form: `FEATURE-CATALOGUE.json`.

**Status legend:** PR = PRODUCTION_READY · NH = FUNCTIONAL_NEEDS_HARDENING · UNEXP = IMPLEMENTED_UNEXPOSED · PART = PARTIAL · BROKEN · STUB · DORM = DORMANT · EXP = EXPERIMENTAL · DEPR = DEPRECATED · REDUN = REDUNDANT_CANDIDATE

## Meta (`src/Meta/`)

| ID | Feature | Status | Notes |
|---|---|---|---|
| SEO-META-001 | Document title & meta description | NH | **Unescaped `<title>` output — XSS gap, see Release Scope Group B.** Fully wired, memoized, filterable (`seo_wp/title`, `seo_wp/meta_description`). No test file. |
| SEO-META-002 | Canonical URL (singular + non-singular) | PR | Correctly excludes search/404/admin. Escaped. Only `pick()` unit tested. |
| SEO-META-003 | Robots meta directives | PR (core) / DORM (author-noindex) | Post/term noindex/nofollow/advanced fully wired. Per-author noindex flag (`_seo_wp_author_noindex`) is read but has **no UI to set it** on a fresh install — only ever written by the Yoast/Rank Math importers. No test file. |

## Schema (`src/Schema/`)

| ID | Feature | Status | Notes |
|---|---|---|---|
| SEO-SCHEMA-001 | JSON-LD `@graph` builder + dedup | PR | Filterable (`seo_wp/schema/graph`), fragment-cached, `wp_json_encode` (safe `</script>` breakout). |
| SEO-SCHEMA-002 | Opening Hours parser | PR | Pure logic, fully tested. No open/close time-order validation (cosmetic). |
| SEO-SCHEMA-003 | Article node | PR | No test file; logic sound. |
| SEO-SCHEMA-004 | BreadcrumbList node | PR | No test file. |
| SEO-SCHEMA-005 | FAQPage node | PR | No test file. |
| SEO-SCHEMA-006 | Organization / LocalBusiness node | PR | No test file. Falls back safely on invalid business type. |
| SEO-SCHEMA-007 | Person node | PR | No test file. |
| SEO-SCHEMA-008 | Product node (WooCommerce) | NH | **Can emit `"price": ""` for an unset/empty product price** — invalid per Google guidelines. Only pure helpers tested, `build()` untested. |
| SEO-SCHEMA-009 | WebPage node | PR | No test file. |
| SEO-SCHEMA-010 | WebSite node | PR | Trivial, no test file. |

## Social (`src/Social/`)

| ID | Feature | Status | Notes |
|---|---|---|---|
| SEO-SOCIAL-001 | Open Graph tags | PR | Filterable (`seo_wp/opengraph/tags`). No test file. |
| SEO-SOCIAL-002 | Twitter Card tags | PR | **No equivalent extension filter exists** (asymmetric with OpenGraph) — roadmap item, not a release blocker. No test file. |

## Frontend (`src/Frontend/`)

| ID | Feature | Status | Notes |
|---|---|---|---|
| SEO-FRONTEND-001 | Head orchestrator | PR | Pure DI wiring. |
| SEO-FRONTEND-002 | Manual resource hints | PR | Escaped, whitelisted types. No test file (distinct from `ResourceHintScannerTest`). |
| SEO-FRONTEND-003 | Critical CSS + stylesheet deferral | NH | Raw-CSS trust model reasoned as equivalent to Customizer Additional CSS — **contingent on `Admin\Settings` capability-gating the save path**, confirmed true (Settings requires `seo_wp_manage_settings`). No test file. |

## Sitemaps (`src/Sitemaps/`)

| ID | Feature | Status | Notes |
|---|---|---|---|
| SEO-SITEMAP-001 | Rewrite rules / router | PR | Activation/deactivation flush confirmed wired — really reachable, not just theoretical. |
| SEO-SITEMAP-002 | Sitemap index | PR | Correct sitemaps.org schema. |
| SEO-SITEMAP-003 | Per-type/taxonomy/video/hreflang inclusion toggles | PR (REDUN) | Two overlapping test files (`tests/Unit/InclusionTest.php` and `tests/Unit/Sitemaps/InclusionTest.php`) — consolidate. |
| SEO-SITEMAP-004 | Post type sitemap | PR | Correct image/video/xhtml namespaces. No direct integration test. |
| SEO-SITEMAP-005 | Taxonomy sitemap | PR | No direct integration test. |
| SEO-SITEMAP-006 | Google News sitemap | PR | Correct schema. No test file. Minor: 48h window relies on WP core `date_query` relative-time handling (unverified edge). |
| SEO-SITEMAP-007 | Image sitemap extension | PR (pure helper) / untested (integration) | `extract_image_urls()` tested; `images_for()` (WP-integrated) is not. |
| SEO-SITEMAP-008 | Video sitemap extension | PR (pure helper) / untested (integration) | Helpers tested; `videos_for()` untested. Thumbnail-required rule correctly enforced. |
| SEO-SITEMAP-009 | Sitemap hreflang alternates | PART | The `seo_wp/hreflang/*` contract itself is well-tested; `HreflangAlternates` (sitemap-side WPML element resolution) has zero direct tests. |

## WPML (`src/Wpml/`)

| ID | Feature | Status | Notes |
|---|---|---|---|
| SEO-WPML-001 | `seo_wp/hreflang/alternates` + `seo_wp/hreflang/managed_externally` contract | PR | Single sender, single call site (`apply_contract()`), used identically by head and sitemap. Verified as genuinely binding, not aspirational. Well tested. |
| SEO-WPML-002 | Head hreflang output | PR (core) / UNEXP (x-default) | Backend supports an explicit WPML language code as x-default; admin UI only offers `off`/`default`. |
| SEO-WPML-003 | WPML String Translation registration | NH | Depends on activation order (WPML active before/at settings save) — no self-healing re-registration if WPML is activated later without a settings re-save. |

## Crawl (`src/Crawl/`)

| ID | Feature | Status | Notes |
|---|---|---|---|
| SEO-CRAWL-001 | Google Indexing API | NH | **Service-account key stored plaintext, autoloaded, fully echoed into the settings textarea on every view** — inconsistent with AI keys' write-only masking. No leak into REST/logs/exports confirmed. |
| SEO-CRAWL-002 | Attachment-link stripping | PR | Pure helper fully tested. |
| SEO-CRAWL-003 | IndexNow | NH | Fully wired, well input-validated. **Zero test coverage at all.** |
| SEO-CRAWL-004 | /llms.txt + /llms-full.txt | NH | Fully wired, escaped. No test file. |
| SEO-CRAWL-005 | 404 monitor | PR | Referrer correctly sanitized on input. Unbounded-growth-churn risk from anonymous 404 flooding (low severity, capped storage). |
| SEO-CRAWL-006 | Redirects (exact + regex, CSV import/export) | NH | **Intentional open redirect** (`wp_redirect` not `wp_safe_redirect`, documented design choice — Redirection/Safe Redirect Manager precedent). **No ReDoS guard** on user-supplied regex patterns (compiles-only validation). Malformed regex fails closed, no warnings leak. Well tested otherwise. |
| SEO-CRAWL-007 | robots.txt extensions | PR | No test on `filter()` itself, helper tested. |
| SEO-CRAWL-008 | Special archive redirects (author/date/attachment) | NH | Correctly uses `wp_safe_redirect` (internal targets only). No test file. |
| SEO-CRAWL-009 | Staging/dev noindex protection | PR (detector) / NH (enforcement, untested) | Detection and enforcement are fully decoupled — a false-positive detection can never auto-noindex a site, only shows a dismissible notice. |
| SEO-CRAWL-010 | Site verification meta tags | PR | No test file, trivial. |

## Performance (`src/Performance/`)

| ID | Feature | Status | Notes |
|---|---|---|---|
| SEO-PERF-001 | .htaccess rules | NH | No free-form admin text ever reaches the file; fails gracefully on non-Apache/non-writable hosts. Zero test coverage. |
| SEO-PERF-002 | ~20 opt-in optimization toggles | NH | All off by default, each self-gated. Zero test coverage for the largest file in this module (559 lines). |
| SEO-PERF-003 | Resource-hint scanner | PR | Confirmed wired via `Admin\Settings`' `wp_ajax_seo_wp_scan_resource_hints` handler (cross-partition confirmed) — not dormant. Own HTTP fetch is un-attacker-influenceable (`home_url()` only). |
| SEO-PERF-004 | Server headers / canonical redirects / CORS | NH | Host-header handling correctly defensive against spoofing. **CORS defaults to `*` when the origin field is left blank** — permissive-by-default. No test file. |

## Admin (`src/Admin/`)

| ID | Feature | Status | Notes |
|---|---|---|---|
| SEO-ADMIN-001 | Settings (6 tabs) | PR | No test file (Settings.php is the largest single class in the plugin). |
| SEO-ADMIN-002 | AI Settings (BYOK) | PR | Write-only masked secret fields — correct pattern. |
| SEO-ADMIN-003 | Bulk Editor + AI suggest/translate | PR | End-to-end wiring confirmed (JS→AJAX→AiClient/DeepLClient→response→UI). No API key ever reaches the browser. |
| SEO-ADMIN-004 | Content Freshness report | PR | Only pure helpers tested. |
| SEO-ADMIN-005 | Diagnostics (error log viewer) | PR | Confirmed no settings/secrets ever surface here. |
| SEO-ADMIN-006 | Duplicate Content Checker | PR | |
| SEO-ADMIN-007 | Google Indexing settings page | PR | See SEO-CRAWL-001 for the credential-exposure note (this is the UI side of that finding). |
| SEO-ADMIN-008 | Import Wizard | PR | |
| SEO-ADMIN-009 | IndexNow settings page | PR | Validates custom key format, validates each manual URL. |
| SEO-ADMIN-010 | Link Audit (scan + background job) | NH | Thoughtful design (token-authenticated loopback + cron fallback + lock), but the **entire persistent-job state machine is untested** — only pure helpers covered. |
| SEO-ADMIN-011 | SEO Metabox (classic) + block-editor sidebar panel | PR (metabox) / **BROKEN (sidebar)** | **`editor-sidebar.js` throws `ReferenceError: array is not defined`** (PHP `array()` used instead of JS `[]`, lines 102/106/134) the instant the sidebar panel renders — breaks the whole feature. Classic metabox is unaffected and remains the real data-entry surface. |
| SEO-ADMIN-012 | 404 Monitor page | PR | Path-scoped nonces correct. |
| SEO-ADMIN-013 | Redirects page (CRUD + CSV) | PR | No dedicated test file (existing `RedirectsTest.php` covers the underlying `Crawl\Redirects`, not this admin page). |
| SEO-ADMIN-014 | Role Manager | PR | Every virtual capability maps 1:1 to a real screen; no orphans either direction. "Always trusted" carve-out for 3 areas is a documented historical-bug workaround, not a defect. |

## Support (`src/Support/`)

| ID | Feature | Status | Notes |
|---|---|---|---|
| SEO-SUPPORT-001 | AiClient (Anthropic/OpenAI) | NH | Key never logged/echoed, no SSRF surface (hardcoded endpoints). HTTP methods untestable under current harness. |
| SEO-SUPPORT-002 | DeepLClient | NH | Same pattern as AiClient. |
| SEO-SUPPORT-003 | Ajax output-buffer guard | PR | Used consistently across every batch-style admin screen. |
| SEO-SUPPORT-004 | ContentRenderer (renders via `the_content` outside main loop) | PR | Correctly restores global `$post` state. |
| SEO-SUPPORT-005 | ErrorLog | PR | Well tested, 100-entry cap. **Not deleted on uninstall** (see Release Scope). |
| SEO-SUPPORT-006 | FragmentCache | PR | Explicitly, correctly invalidated after import writes (a real past bug, now fixed and documented). |
| SEO-SUPPORT-007 | StagingDetector | PR | Explicitly a "nudge, not a security boundary" — matches actual usage. |
| SEO-SUPPORT-008 | Url helper | PR | Well tested. |

## Templates (`src/Templates/`)

| ID | Feature | Status | Notes |
|---|---|---|---|
| SEO-TEMPLATES-001 | Renderer (token substitution + separator cleanup) | PR | Well tested. |
| SEO-TEMPLATES-002 | Tokens (Yoast/Rank Math/AIOSEO ↔ internal syntax) | PR | Well tested; load-bearing for the Import module's cross-plugin template claim. |

## Import (`src/Import/`)

| ID | Feature | Status | Notes |
|---|---|---|---|
| SEO-IMPORT-001 | Detector (competing-plugin data detection) | NH | Direct-SQL, correctly prepared, no injection surface. Untestable under current harness (`$wpdb`). |
| SEO-IMPORT-002 | PluginRegistry (locate/deactivate competing plugin) | PR | Matches readme.txt's "never automatic" deactivation claim exactly. |
| SEO-IMPORT-003 | Batch (paginated import runner + log) | NH | Idempotency guard (`filter_to_empty_fields`) well tested; the four page-runner methods are not. |
| SEO-IMPORT-004 | YoastImporter | PR | Read-only, matches readme.txt claims exactly. |
| SEO-IMPORT-005 | RankMathImporter | NH | Schema-type mapping **self-documented in code as unverified/best-effort — this caveat is not surfaced in readme.txt** (a documentation gap, not a code defect). |
| SEO-IMPORT-006 | AioseoImporter | PR | Deliberately split for testability; matches readme.txt's "no per-author settings" claim. |
| SEO-IMPORT-007 | SettingsImporter (site-wide, Yoast/Rank Math only) | NH | Logic matches readme.txt's confidence framing exactly. No test file. |
| SEO-IMPORT-008 | TermImporter | NH | Correctly source-specific storage handling (option vs termmeta vs custom table). No test file. |

---

**Totals:** 76 features. 51 PRODUCTION_READY, 22 FUNCTIONAL_NEEDS_HARDENING, 1 BROKEN, 1 PARTIAL, 1 REDUNDANT_CANDIDATE (counts by primary status tag; several PR entries carry a secondary DORMANT/UNEXPOSED sub-note). Zero true STUB/EXPERIMENTAL/DEPRECATED code found anywhere in the plugin.
