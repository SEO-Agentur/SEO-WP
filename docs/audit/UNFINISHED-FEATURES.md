# SEO-WP — Stub / Dormant / Unfinished Code Audit

Forensic result across all 79 `src/` files (5 partition audits, full-file reads, structural + comment-based scanning): **no TODO/FIXME/XXX/HACK/TBD/WIP/@todo comments exist anywhere in `src/`. No empty method bodies, no unconditional null/false-return stubs, no placeholder UI, no unreachable code, no unused classes, and no registered-but-never-consumed hooks were found.** This is a genuinely complete, non-abandoned codebase — every class traced is wired into the bootstrap and reachable.

That said, five real findings fit this phase's purpose. Each is answered against the required question set.

---

## 1. `assets/admin/editor-sidebar.js` — Gutenberg sidebar panel

- **What exists?** A block-editor `PluginSidebar` registration (`SeoSidebar` component) mirroring the classic metabox's title/description fields with a live SERP preview and length-traffic-light badge.
- **What was it intended to do?** Let an editor see/adjust SEO-WP's title/description without scrolling to the metabox, per its own docblock and the readme.txt 1.4.0 changelog entry.
- **How complete is it?** Fully written, but contains a literal bug: `useSelect(fn, array())` / `useEffect(fn, array())` use PHP's `array()` instead of JavaScript's `[]` (lines 102, 106, 134). No global `array` function exists in JS or anywhere in this codebase.
- **Is it reachable?** Yes — it's enqueued and registered on every post-edit screen.
- **Does it work?** **No.** `ReferenceError: array is not defined` throws the instant the component renders, i.e. as soon as the block editor loads the "SEO-WP" sidebar panel. The panel is completely non-functional.
- **Is it tested?** No JS test suite exists for this codebase at all (consistent with its "vanilla JS, no build step" philosophy) — nothing would have caught this before now.
- **Is it safe?** Yes, in the narrow sense that the classic metabox (the actual data-entry surface) is entirely unaffected and continues to work. No data loss, no security issue — purely a broken secondary UI.
- **Is it redundant?** No — it's a genuinely distinct, intended feature (persistent visibility vs. scroll-to-metabox), not a duplicate of the metabox.
- **Required for the first production release?** **Yes** — it's already documented as shipped in 1.4.0's changelog and readme.txt's Description section. Shipping 1.0 with a documented-but-broken feature is worse than fixing a 3-character typo.
- **Recommendation: `FIX_FOR_RELEASE`.** Change both `array()` occurrences to `[]`. Trivial fix, high user-visible impact.

## 2. `_seo_wp_author_noindex` — per-author noindex flag

- **What exists?** `Meta\Robots::META_AUTHOR_NOINDEX` (`_seo_wp_author_noindex`), read at `Robots.php:145` and folded into the `wp_robots` output for author archives.
- **What was it intended to do?** Let a site owner mark specific authors' archive pages as noindex — a real, common SEO need (e.g. multi-author sites wanting to noindex low-content contributor pages).
- **How complete is it?** The read/apply side is fully implemented and correct. The **write side does not exist** — no user-profile screen field, no `show_user_profile`/`edit_user_profile` hook anywhere in `src/Admin/`, and no Bulk Editor column for it.
- **Is it reachable?** Only indirectly: it is written exclusively by `YoastImporter::map_author()` and `RankMathImporter::map_author()` during a migration import. A site that installs SEO-WP fresh (the majority case) has no way to ever set this flag.
- **Does it work?** Yes, for sites that migrated from Yoast/Rank Math and had the setting there. No, for every other site — the feature is invisible and unreachable.
- **Is it tested?** No.
- **Is it safe?** Yes — it degrades to a no-op (never noindexed) rather than erroring.
- **Is it redundant?** No.
- **Required for the first production release?** No — this is a real, working migration-compatibility feature, just missing a first-class settings UI. Not a release blocker; a genuinely valuable roadmap item.
- **Recommendation: `MOVE_TO_ROADMAP`.** Add a per-author noindex checkbox (Users → Edit User screen) in a future release. Document today's actual scope ("preserved on import from Yoast/Rank Math; no native UI yet") in the readme so it isn't silently misleading.

## 3. `seo_wp/hreflang/*` x-default explicit-language option

- **What exists?** `Hreflang::x_default_language()` supports three states: `'off'`, `'default'` (WPML's own default language), or **any explicit WPML language code** as a string.
- **What was it intended to do?** Give a site owner fine-grained control over which language's URL is also marked `hreflang="x-default"`.
- **How complete is it?** Backend logic fully supports all three. The admin `<select>` (Settings → WPML tab) only renders two options: `off` and `default` — an explicit code can never be chosen through the UI.
- **Is it reachable?** Only by directly manipulating the `seo_wp_settings` option outside the UI (e.g. via `wp option update` or another plugin).
- **Does it work?** Yes, if reached; no UI path reaches it.
- **Is it tested?** The underlying resolution logic is tested; the UI gap is not (there's nothing to test).
- **Is it safe?** Yes.
- **Is it redundant?** No.
- **Required for the first production release?** No — `off`/`default` covers the overwhelming majority of real setups. A genuinely nice-to-have completion, not a blocker.
- **Recommendation: `MOVE_TO_ROADMAP`.** Expose a third "specific language" option with a language-code text field, gated behind WPML being active.

## 4. Duplicate `Inclusion` test files

- **What exists?** `tests/Unit/InclusionTest.php` and `tests/Unit/Sitemaps/InclusionTest.php`, both carrying `@covers \SeoWp\Sitemaps\Inclusion`, with overlapping (not identical) test scenarios — the root-level file tests the 4 fixed toggles, the nested one tests the dynamic per-post-type/taxonomy toggles.
- **What was it intended to do?** Test coverage for `Sitemaps\Inclusion` — apparently added in two separate passes without the second author checking whether coverage already existed.
- **How complete is it?** Both files are complete, passing tests — this is test-suite debt, not product code debt.
- **Is it reachable / does it work?** Yes, both run and pass; no functional impact on the product.
- **Is it tested?** N/A (it is the tests).
- **Is it safe?** Yes.
- **Is it redundant?** Yes — the two files should be one.
- **Required for the first production release?** No, purely cosmetic organizational debt.
- **Recommendation: `MERGE_WITH_EXISTING`.** Consolidate into `tests/Unit/Sitemaps/InclusionTest.php` (matching the class's own namespace location) and delete the root-level duplicate. Low priority, safe to do any time before or after release.

## 5. `uninstall.php` — incomplete option cleanup

- **What exists?** A `WP_UNINSTALL_PLUGIN`-guarded script that deletes exactly 3 options: `seo_wp_settings`, `seo_wp_indexnow_key`, `seo_wp_import_log`. Its own docblock states the intent: "Removes the plugin's own options" (post/term meta is *deliberately* preserved — a separate, correct, documented design decision, not a bug).
- **What was it intended to do?** Leave the site's database clean of every plugin-created *option* (not per-object meta) on uninstall.
- **How complete is it?** Incomplete. Cross-referencing all 5 partition audits' option-key inventories against this file's literal contents, the following options are created by the plugin but **never deleted**:
  - `seo_wp_error_log` (Support\ErrorLog)
  - `seo_wp_role_caps` (Admin\RoleManager)
  - `seo_wp_duplicate_scan` (Admin\DuplicateChecker)
  - `seo_wp_link_audit_scan`, `seo_wp_link_queue`, `seo_wp_broken_links`, `seo_wp_link_audit_job` (Admin\LinkAudit)
  - `seo_wp_google_indexing_token`, `seo_wp_google_indexing_log` (Crawl\GoogleIndexing)
  - `seo_wp_indexnow_log` (Crawl\IndexNow — note: `seo_wp_indexnow_key` *is* deleted, but its accompanying log is not)
  - `seo_wp_404_log` (Crawl\NotFoundMonitor)
  - `seo_wp_redirects` (Crawl\Redirects — includes any admin-authored, potentially cross-domain, redirect targets)
  - `seo_wp_staging_notice_dismissed` (Crawl\StagingProtection)

  That is **13 options** left behind, against 3 removed. (`seo_wp_link_audit_worker_lock` is a self-expiring 45-second transient — not a concern.)
- **Is it reachable?** The uninstall script itself runs correctly whenever a site owner deletes the plugin via wp-admin; the gap is in what it doesn't do, not a defect in what it does.
- **Does it work?** Partially — it does exactly what it does correctly, it just does far less than its own stated intent.
- **Is it tested?** No `uninstall.php` test exists (expected — this is integration-only territory, consistent with the test harness's documented scope).
- **Is it safe?** Yes, from a functional standpoint — orphaned options don't break anything. Two are worth flagging as more than cosmetic: `seo_wp_google_indexing_token` (a live-ish OAuth2 credential artifact) and `seo_wp_redirects` (would silently reactivate every previously-configured redirect, including any open-redirect targets, if the plugin were reinstalled with old option rows still present in the DB — a genuinely surprising behavior for a "clean reinstall").
- **Is it redundant?** No.
- **Required for the first production release?** **Yes** — an uninstall routine that doesn't match its own documented intent, discovered during a pre-release audit, is exactly the kind of "broken uninstall/upgrade behavior" the release principle calls a must-fix.
- **Recommendation: `FIX_FOR_RELEASE`.** Add the 13 missing `delete_option()` calls.

---

## Summary table

| # | Finding | Category | Recommendation |
|---|---|---|---|
| 1 | Block-editor sidebar panel JS crash | BROKEN | FIX_FOR_RELEASE |
| 2 | Per-author noindex has no UI | DORMANT | MOVE_TO_ROADMAP |
| 3 | Hreflang x-default explicit-language option unexposed | IMPLEMENTED_UNEXPOSED | MOVE_TO_ROADMAP |
| 4 | Duplicate Inclusion test files | REDUNDANT_CANDIDATE (tests only) | MERGE_WITH_EXISTING |
| 5 | uninstall.php leaves 13 options behind | Incomplete infrastructure | FIX_FOR_RELEASE |

No item on this list required inventing new functionality to "complete a stub" — every finding here is either a small, surgical fix or a legitimate roadmap deferral. This is the clearest possible evidence that SEO-WP is not a half-built product; it is a complete product with a short, concrete punch-list standing between it and release.
