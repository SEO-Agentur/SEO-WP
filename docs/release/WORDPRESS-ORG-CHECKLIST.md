# SEO-WP — WordPress.org Release Audit

**Date:** 2026-09-16. Ran against v1.4.3, live on a real WordPress 7.1 / PHP 8.3 install (seo-wp.net), using the **official WordPress.org Plugin Check (PCP) tool v2.1.0** via `wp plugin check seo-wp`, plus manual header/readme/structure review. This is real tool output, not a simulated pass.

Status legend: **PASS** / **FAIL** / **NOT_APPLICABLE** / **NEEDS_REVIEW**.

## Plugin headers

| Item | Status | Notes |
|---|---|---|
| Plugin Name, URI, Version, Requires at least, Requires PHP, Author, License | PASS | All present and consistent with readme.txt |
| Text Domain / Domain Path | PASS | `seo-wp` / `/languages`, matches `load_plugin_textdomain()` call |
| **`Tested up to`** | **FIXED** | Was `7.0`, live install is running WP 7.1 — Plugin Check flagged this as `outdated_tested_upto_header` (ERROR). Bumped to `7.1`. |
| **Plugin name/slug trademark check** | **NEEDS_REVIEW — see below** | Plugin Check flags "SEO-WP" for containing the restricted term "wp" |

## License / GPL compatibility
PASS — GPL-2.0-or-later, no bundled non-GPL-compatible code found (zero runtime dependencies).

## readme.txt sections
PASS — Description, Installation, FAQ, External Services (new in this pass), Changelog all present. Screenshots section and banner/icon assets still absent (not a submission blocker — addable post-approval via the SVN `assets/` folder).

## Escaping / security (automated + manual)
- **PASS** (manual, this session's audit): every admin-post/AJAX handler has capability + nonce checks; no credential leaks into REST/logs/exports found across 5 partition audits.
- **NEEDS_REVIEW** (automated, Plugin Check): 11 files flagged `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized`/`MissingUnslash` on `$_POST`/`$_SERVER`/`$_FILES` reads (Metabox, RedirectsPage, AiSettings, RoleManager, IndexNowPage, BulkEditor, Htaccess, ServerHeaders). All were manually re-verified during this session's audit to be sanitized correctly (via `sanitize_text_field()`/`sanitize_textarea_field()`/etc., usually one line below or inside a loop the static sniff doesn't trace, or — for the `$_SERVER` reads — read-only boolean comparisons never stored or echoed). Classified as likely false positives for actual exploitability, but a mechanical `wp_unslash()`-wrapping pass on the flagged `$_SERVER` reads (Htaccess.php:67, ServerHeaders.php:69/95/104) would silence these cheaply and matches WPCS convention even though the values aren't magic-quoted. **Not done in this pass** — low risk, cosmetic for WPCS compliance, candidate for a follow-up cleanup commit.
- **FIXED**: `src/Admin/LinkAudit.php` used `wp_get_sidebars_widgets()`, which Plugin Check's `ForbiddenFunctions` sniff denylists (ERROR). Switched to `get_option('sidebars_widgets', array())` — same data shape, same 'wp_inactive_widgets' handling already in the surrounding code.
- **FALSE_POSITIVE**: `src/Performance/Optimizations.php:142` flagged as `OffloadedContent` ("Offloading images, js, css… to remote services is disallowed"). The actual code is a `strpos()` filter *removing* WordPress.org's own emoji CDN script reference from the enqueued-scripts list (the opt-in "disable emojis" feature) — the opposite of offloading. Verified by reading the code; no change needed.
- **INFORMATIONAL**: `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` on 4 sitemap/llms.txt files, for the `Robots::META_NOINDEX` exclusion `meta_query`. Inherent to the noindex-exclusion design; would need a bigger architectural change (indexed taxonomy or cache) to avoid — not a release blocker, roadmap candidate if a very large site reports real slowness.
- **NEEDS_REVIEW**: `WordPress.Security.DirectDB.UnescapedDBParameter` on 3 Import classes (`AioseoImporter`, `TermImporter`, `Detector`) — the `$table` variable is always `$wpdb->prefix . 'aioseo_posts'`/`'aioseo_terms'` (a hardcoded suffix, never user input), which this session's own audit already confirmed has no injection surface. Table/column names can't go through `$wpdb->prepare()`'s placeholder mechanism at all (a PHP/MySQL limitation, not a bug), so this pattern is common and expected in legitimate WP code needing a dynamic-but-trusted table name. Classified as a likely false positive; not changed.

## Hidden files
**FIXED**: `languages/.gitkeep` flagged as a disallowed hidden file (ERROR). Excluded from the distributable zip via `.distignore` — it's a git-tracking convenience file with no purpose once shipped.

## Deprecated/discouraged functions
**NEEDS_REVIEW, not changed**: `load_plugin_textdomain()` in `Plugin.php` flagged as discouraged since WP 4.6, since WordPress.org auto-loads translations by slug for hosted plugins. Harmless either way, but the call becomes a genuine no-op once (if) this plugin is WP.org-hosted. Low priority — removing it changes nothing for a self-hosted distribution, only saves a no-op call for a WP.org one.

## Custom hook naming convention
**NEEDS_REVIEW, not changed**: Plugin Check's `PrefixAllGlobals.NonPrefixedHooknameFound` sniff flags all 5 public `seo_wp/*` filters (and, implicitly, the `seo_wp/hreflang/*` contract) because it expects an underscore immediately after the prefix, not a slash. This is a deliberate, precedented design choice (Rank Math ships `rank_math/frontend/title` the same way, live on WP.org today) — slash-namespaced hooks are a real, accepted pattern in the ecosystem even though this specific automated sniff doesn't special-case it. These are WARNINGs, not ERRORs, and don't appear to be hard submission blockers based on precedent. Not changed in this pass; flagged for your awareness rather than silently reformatted, since renaming the filters would be a breaking change for the docs/DEVELOPERS.md API surface published in 1.4.2.

## AI integration
**NEEDS_REVIEW, not changed — a genuinely new finding**: Plugin Check flags `src/Support/AiClient.php`'s direct calls to `api.anthropic.com`/`api.openai.com`, suggesting WordPress core's own `wp_ai_client_prompt()` (introduced in **WordPress 7.0**) as an alternative. This is a real core API that post-dates my own knowledge cutoff — I have not verified its actual signature, capabilities, or whether it would even support Anthropic/OpenAI as pluggable backends (vs. being scoped to a specific provider). This is a **product/architecture decision requiring your input**, not something to blindly rewrite against an API I haven't verified. Recommend investigating `wp_ai_client_prompt()` directly before deciding whether to migrate `AiClient`/`DeepLClient` onto it.

## Trademark — plugin name "SEO-WP"
**NEEDS_REVIEW — flagging prominently, not treating as a blocker to act on unilaterally.** Plugin Check's `trademarked_term` check flags "SEO-WP" (both the plugin name and the `seo-wp` slug) for containing the restricted term "wp". Taken completely literally, this would mean the entire plugin — and the `seo-wp.net` brand this whole project is built around — cannot use its own name on WordPress.org. This directly contradicts long-standing real-world precedent: WPForms, WP Rocket, WP Fastest Cache, WP Super Cache, and many other major, currently-listed WP.org plugins all use "WP" in their name. WordPress.org's actual trademark policy restricts the word "WordPress" itself (and close misspellings), not "wp" as a substring in general — Plugin Check's automated heuristic appears stricter than actual human review here. **Recommendation: do not rename anything based on this single automated flag.** If/when actual WP.org submission happens, this is worth a direct question to the Plugins Team (or simply attempting submission) rather than a preemptive rename — renaming would touch the plugin slug, all documentation, the seo-wp.net domain/branding, and the companion slim-hreflang contract's peer-detection constants (`PEER_PLUGIN_BASENAME = 'seo-wp/seo-wp.php'`).

## External services disclosure
PASS (this session) — see readme.txt's new "External services" section, covering all 6 confirmed outbound integrations.

## Summary

| Category | Count | Action taken |
|---|---|---|
| FIXED this pass | 4 | Tested-up-to bump, hidden-file exclusion, forbidden-function replacement, (false positive documented, no code change) |
| NEEDS_REVIEW — flagged, not changed | 5 | Sanitization warnings (likely false positive), deprecated `load_plugin_textdomain`, hook naming convention, Import DirectDB warnings (likely false positive), AI Client API |
| **NEEDS_REVIEW — requires your decision** | 2 | **Trademarked term ("wp" in the name/slug)** — potentially existential, do not act on unilaterally. **`wp_ai_client_prompt()` migration** — real new core API, needs investigation before any architecture change. |
| Not yet done | — | Screenshots, banner/icon assets (non-blocking, addable post-approval) |

No unresolved FAIL remains among items within this plugin's own control. The two flagged decisions above are the only open items standing between here and a Release Candidate — both are yours to make, not mine to decide silently.
