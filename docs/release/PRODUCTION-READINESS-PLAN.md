# SEO-WP — Production Readiness Plan

**Current version:** 1.4.2
**Target release version:** 1.5.0 (first WordPress.org-ready production release)
**Status:** Phases 0–7 complete. Awaiting approval before Phase 8 (code changes) begins.

This plan enumerates every Group B item from `docs/release/RELEASE-SCOPE.md`, each with priority, risk, scope, dependencies, and a concrete definition of done. Nothing here requires new functionality — every item is a fix to existing, already-shipped code or documentation.

---

## Bugs requiring fixes

### B1 — `<title>` output unescaped (XSS)
- **Priority:** P0 (security)
- **Feature ID:** SEO-META-001
- **Risk:** Real, exploitable given default WP capabilities (`unfiltered_html` for Admin/Editor)
- **Scope:** Small — one `esc_html()` wrap in `TitleDescription::filter_title()`
- **Dependencies:** None
- **Definition of done:** A post title containing `<script>` or other markup renders as literal text in `<title>` view-source; existing `pre_get_document_title` filter behavior and the `seo_wp/title` extension filter both still function; no regression in the plain-text case.

### B2 — Block-editor sidebar panel crashes on render
- **Priority:** P0 (user-visible, documented feature is non-functional)
- **Feature ID:** SEO-ADMIN-011
- **Risk:** None beyond continued broken UX — no data-loss vector, classic metabox unaffected
- **Scope:** Trivial — `array()` → `[]` at 3 sites in `assets/admin/editor-sidebar.js`
- **Dependencies:** None
- **Definition of done:** Opening the block-editor "SEO-WP" sidebar panel produces no console error; the live SERP preview and length-badge update as the title/description fields are edited, matching the classic metabox's own preview behavior.

### B3 — `uninstall.php` leaves 13 options behind
- **Priority:** P1
- **Feature ID:** infra (cross-cutting)
- **Risk:** Low functional risk, but contradicts documented intent; a reinstall silently resurrects old redirect rules (including any admin-authored cross-domain targets) and a stale Google Indexing OAuth2 token
- **Scope:** Small — 13 additional `delete_option()` calls
- **Dependencies:** None
- **Definition of done:** Fresh install → configure every module → uninstall → `SELECT * FROM wp_options WHERE option_name LIKE 'seo_wp_%'` returns zero rows (post/term/user meta remains, by deliberate, documented design).

### B6 — Product schema can emit invalid `price: ""`
- **Priority:** P1
- **Feature ID:** SEO-SCHEMA-008
- **Risk:** Invalid structured data reaching Google Search Console for real store owners; not a security issue
- **Scope:** Small — guard in `Schema\Nodes\Product::build()` before emitting the `offers` block
- **Dependencies:** None
- **Definition of done:** A WooCommerce product with an empty/unset price either omits the `offers` block entirely or the whole `Product` node is skipped; a product with a real price is unaffected.

### B7 — CORS defaults to `*` when the origin field is blank
- **Priority:** P1 (security-adjacent default)
- **Feature ID:** SEO-PERF-004
- **Risk:** Permissive-by-default; no `Access-Control-Allow-Credentials` is ever sent alongside it, so not independently exploitable, but a surprising default
- **Scope:** Small — change `cors_origin()`'s empty-string fallback from `'*'` to "don't send the header"
- **Dependencies:** None
- **Definition of done:** Enabling CORS with a blank origin field sends no `Access-Control-Allow-Origin` header; setting an explicit origin works exactly as before.

---

## Incomplete/hardening items

### B4 — Google Indexing service-account key: plaintext, autoloaded, fully echoed
- **Priority:** P1 (security hardening)
- **Feature ID:** SEO-CRAWL-001 / SEO-ADMIN-007
- **Risk:** No confirmed leak path (verified clean against REST/logs/exports), but weaker handling than the AI keys for an equally sensitive credential
- **Scope:** Small–medium — write-only masked display (mirror `AiSettings`' pattern: show "key configured" placeholder, never the real value, only overwrite on non-empty submission); consider `autoload => false` for this sub-key or splitting it into its own non-autoloaded option
- **Dependencies:** None
- **Definition of done:** Visiting the Google Indexing settings page after a key is saved never shows the key's plaintext value in the rendered HTML; submitting the form with the field left blank preserves the existing key (matching `AiSettings`' existing UX contract).

### B5 — Redirects: no ReDoS guard on user-supplied regex
- **Priority:** P1 (security hardening)
- **Feature ID:** SEO-CRAWL-006
- **Risk:** DoS vector gated by admin/CSV-import trust; `is_valid_regex()` already fails closed on malformed patterns with no warning leak
- **Scope:** Small — add a pattern-length cap and/or a deny-list check for common catastrophic-backtracking shapes (nested quantifiers) in `is_valid_regex()`
- **Dependencies:** None
- **Definition of done:** A pathological pattern (e.g. `(a+)+$`) is rejected at save/import time with a clear admin-facing error, rather than being accepted and only causing trouble on a future matching request.

### B8 — RankMathImporter schema-type caveat missing from readme.txt
- **Priority:** P2 (docs)
- **Feature ID:** SEO-IMPORT-005
- **Risk:** None functional — a user-expectations gap only
- **Scope:** Docs-only — extend the existing Rank Math import caveat in readme.txt to explicitly name schema-type mapping as best-effort
- **Dependencies:** None
- **Definition of done:** readme.txt's Import section text matches what `RankMathImporter`'s own docblocks already say about confidence level.

### B12 — Duplicate `InclusionTest.php` files
- **Priority:** P3 (test-suite hygiene)
- **Feature ID:** SEO-SITEMAP-003
- **Risk:** None — cosmetic only
- **Scope:** Trivial — merge into `tests/Unit/Sitemaps/InclusionTest.php`, delete the root-level duplicate
- **Dependencies:** None
- **Definition of done:** `vendor/bin/phpunit` still passes with the same assertion count minus the removed duplicates; one `InclusionTest.php` remains.

### B13 — `phpunit.xml.dist` not in `.distignore`
- **Priority:** P3 (packaging hygiene)
- **Feature ID:** infra
- **Risk:** None — cosmetic
- **Scope:** Trivial — one line added to `.distignore`
- **Dependencies:** None
- **Definition of done:** The next distributable zip does not contain `phpunit.xml.dist`.

---

## WordPress.org work

### B9 — `Contributors: yourwpusername` placeholder
- **Priority:** P0 (hard submission blocker)
- **Scope:** Trivial
- **Definition of done:** readme.txt's `Contributors:` line carries the real WP.org username before any submission attempt.

### B10 — Missing External Services disclosure
- **Priority:** P0 (hard submission blocker)
- **Scope:** Docs-only — one readme.txt subsection per service: Google Indexing API, IndexNow, Anthropic, OpenAI, DeepL, and the Resource Hint Scanner's self-fetch (what's sent, when, to whom, ToS/privacy policy links)
- **Definition of done:** readme.txt contains a WP.org-compliant "External services" section covering all 6 integrations, matching the actual BYOK/no-proxy behavior confirmed in the audit.

### B11 — Plugin header language inconsistency
- **Priority:** P2
- **Scope:** Trivial — pick one language (recommend English, matching readme.txt) for `seo-wp.php`'s `Description` field
- **Definition of done:** Plugin header and readme.txt short description are in the same language.

### Not yet run: full WordPress.org Release Audit (Phase 10)
- **Priority:** Runs after all Group B items above are resolved
- **Scope:** Produces `docs/release/WORDPRESS-ORG-CHECKLIST.md`, PASS/FAIL/NOT_APPLICABLE/NEEDS_REVIEW per item, including running the current Plugin Check tool
- **Known-in-advance gaps:** no screenshots section, no banner/icon assets (both addable post-approval via SVN `assets/`, not submission blockers)

---

## Security work summary
Covered above: B1 (XSS), B4 (credential storage), B5 (ReDoS), B7 (CORS default). Additionally reviewed and accepted as-is: `Crawl\Redirects`' intentional open redirect (documented design choice, comparable to established redirect-manager plugins) — no code change, but must be named explicitly in the B10 external-services/security disclosure so it isn't a surprise to a reviewer or a security-conscious site owner.

## Performance work summary
No release-blocking performance issues found. Two minor, non-blocking notes carried in Asana (Performance section) for future attention: sitemap object-cache staleness on settings changes (up to 1h), and one avoidable `WP_Query` per post type on `/sitemap.xml` cache misses.

## Compatibility work
No compatibility defects found. Verified accurate: readme.txt's Import-module claims (Yoast/Rank Math/AIOSEO), the BYOK/no-proxy claim, and the `seo_wp/hreflang/*` contract with slim-hreflang. No multisite-specific or WooCommerce-specific defects found during this pass (WooCommerce integration reviewed as part of SEO-SCHEMA-008/Admin Metabox; multisite not separately stress-tested — flag as a Phase 9 testing task, not a known defect).

## Testing work
No Group B item requires new tests to ship v1.5.0 — B12 is the only test-suite change in scope, and it's a deletion, not an addition. The broader test-coverage gap (documented throughout the catalogue) is intentionally scoped to Roadmap C4, not this release, per the release principle: don't chase 100% coverage before shipping.

## Documentation work
B8, B10, B11 above, plus (not blocking, tracked in Asana) the absence of any `.pot`/`.po`/`.mo` translation catalog — acceptable to defer to WP.org's own GlotPress tooling if that's the distribution path.

## Release packaging work
B3 (uninstall completeness) and B13 (`.distignore`) are the only packaging-relevant items. Once all Group B items are resolved: rebuild the distributable zip, verify via `unzip -t` + version grep (established pattern from this session), and — per Phase 11 — test the actual zip on a clean WordPress install (activation → configuration → frontend → admin → deactivation → reactivation → uninstall), not just the repository checkout.

---

## Summary table

| ID | Item | Priority | Scope |
|---|---|---|---|
| B1 | Unescaped `<title>` (XSS) | P0 | small |
| B2 | Broken sidebar panel | P0 | trivial |
| B9 | Contributors placeholder | P0 | trivial |
| B10 | External services disclosure | P0 | docs |
| B3 | uninstall.php gaps | P1 | small |
| B4 | Google key exposure | P1 | small–medium |
| B5 | Redirect ReDoS guard | P1 | small |
| B6 | Product schema empty price | P1 | small |
| B7 | CORS default | P1 | small |
| B8 | RankMath readme caveat | P2 | docs |
| B11 | Header language mismatch | P2 | trivial |
| B12 | Duplicate test file | P3 | trivial |
| B13 | .distignore entry | P3 | trivial |

**13 items, zero requiring new functionality, all with a clear definition of done.**

---

## Request for approval

This concludes Phases 0–7. Per the process this audit follows, I am stopping here and requesting your explicit approval before Phase 8 (production hardening / code changes) begins.

Please confirm:
1. The Group B scope above (13 items) — proceed with all of them, or adjust?
2. Priority order — proposed above (P0 → P3) as the working order, or a different sequence?
3. Anything to explicitly exclude from this release pass (e.g. defer the WP.org-specific items B9/B10/B11 if WP.org submission isn't the near-term goal)?
