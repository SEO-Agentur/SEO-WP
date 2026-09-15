# SEO-WP — Production Release Scope

Derived from `docs/audit/FEATURE-CATALOGUE.md`/`.json` and `docs/audit/UNFINISHED-FEATURES.md`. Every item below traces to a Feature ID or a named finding — nothing here is invented for this document.

## Group A — MUST SHIP (existing, complete, working)

**All 76 catalogued features except the ones explicitly named in Group B below.** This includes every Meta/Schema/Social/Frontend/Sitemap/WPML/Crawl/Performance/Admin/Support/Templates/Import feature marked `PRODUCTION_READY` or `FUNCTIONAL_NEEDS_HARDENING` in the catalogue where the "hardening" gap is a missing test, not a functional defect (e.g. IndexNow, llms.txt, Special Archives, .htaccess, the ~20 Optimization toggles, all 8 Import classes) — these work correctly as shipped; "needs hardening" here means "needs a test written," not "needs a behavior fixed."

No feature is being removed, hidden, or deferred silently. The full list with per-feature detail is the catalogue itself — this document does not repeat it.

## Group B — MUST FIX BEFORE RELEASE

| # | Feature ID | Issue | Why it blocks release | Fix scope |
|---|---|---|---|---|
| B1 | SEO-META-001 | `<title>` output unescaped on the `pre_get_document_title` short-circuit path | Real XSS: any Administrator/Editor-authored post title, term name, or display name containing markup reaches `<title>` unescaped | Small — wrap the return value in `esc_html()` (or equivalent) in `filter_title()` |
| B2 | SEO-ADMIN-011 | Block-editor sidebar panel throws `ReferenceError` on render (`array()` vs `[]`) | Shipping a documented, changelog-announced feature that is completely non-functional | Trivial — 2-character fix in `assets/admin/editor-sidebar.js`, 3 occurrences |
| B3 | (infra) | `uninstall.php` deletes 3 of 16 plugin-created options | Contradicts the file's own documented intent; a reinstall silently resurrects old redirect rules, cached credentials, and log data | Small — add 13 `delete_option()` calls |
| B4 | SEO-CRAWL-001 / SEO-ADMIN-007 | Google service-account key stored plaintext, autoloaded, fully round-tripped into the admin textarea on every view | Materially weaker handling than the AI keys (write-only masked) for an equally sensitive credential | Small–medium — switch to write-only masked display (matching `AiSettings`' pattern); consider `autoload => false` |
| B5 | SEO-CRAWL-006 | User-supplied redirect regex has no complexity/length guard | ReDoS vector, gated only by admin trust but worth a cheap mitigation before release | Small — cap pattern length, reject a short deny-list of catastrophic-backtracking shapes, or wrap matching in a bounded-time guard |
| B6 | SEO-SCHEMA-008 | Product schema can emit `"price": ""` for a product with no price set | Invalid structured data shipped to Google Search Console for real store owners | Small — skip the `offers` block (or the whole node) when price is empty |
| B7 | SEO-PERF-004 | CORS defaults to `Access-Control-Allow-Origin: *` when the origin field is left blank | Permissive-by-default for a security-adjacent toggle | Small — fail closed: don't send the header (or don't enable CORS) when the origin field is empty |
| B8 | SEO-IMPORT-005 | RankMathImporter's schema-type mapping is self-documented in code as unverified/best-effort, but readme.txt doesn't carry that caveat | Users relying on readme.txt's "moderate confidence" language don't know schema-type import specifically is weaker than that | Docs-only — extend the existing Rank Math caveat in readme.txt to explicitly name schema-type mapping |
| B9 | (infra) | `readme.txt` `Contributors: yourwpusername` is un-replaced placeholder | Hard WP.org submission blocker | Trivial — replace with the real WP.org username |
| B10 | (infra) | No "External services" disclosure section in readme.txt, despite 6 files making outbound calls (Google Indexing, IndexNow, Anthropic/OpenAI, DeepL, Link Audit checks, Resource Hint Scanner's self-fetch) | Hard WP.org submission requirement since the 2024 guideline update | Docs-only — one new readme.txt section per external service: what's sent, when, to whom, ToS/privacy links |
| B11 | (infra) | Plugin header `Description` is German; `readme.txt` short description is English | Inconsistent canonical metadata across the two files WP.org reads | Trivial — pick one language (recommend English, matching readme.txt) for the plugin header |
| B12 | SEO-SITEMAP-003 | Duplicate `InclusionTest.php` files | Test-suite hygiene, not a product defect — included here only because it's a 5-minute fix worth doing in the same pass | Trivial — merge, delete duplicate |
| B13 | (infra) | `phpunit.xml.dist` not in `.distignore` | Ships an orphaned config file in the release zip | Trivial — add one line to `.distignore` |

**None of these require new functionality.** B1, B2, B6, B7, B12, B13 are one-file, few-line fixes. B3 is a mechanical list completion. B4/B5 are hardening changes to existing code, not new features. B8/B9/B10/B11 are documentation-only.

## Group C — ROADMAP (valuable, not required for v1.0)

From this audit:
- **C1** — Per-author noindex UI (backend already implemented, `_seo_wp_author_noindex`; needs a Users-screen field)
- **C2** — Explicit-language-code option for hreflang `x-default` (backend already implemented; needs a UI field)
- **C3** — `seo_wp/twitter/tags` filter, for parity with Open Graph's `seo_wp/opengraph/tags`
- **C4** — Broader WordPress-integration test coverage (the current harness intentionally only unit-tests pure logic; a real `$wpdb`/`WP_Query`/`wp_remote_*`-capable test environment, e.g. via `wp-env` + a WP-integration test suite, would close the largest remaining risk surface — most of `Admin/`, `Crawl/`, `Performance/`, `Import/`'s actual runtime behavior)

Carried over from the pre-audit roadmap discussion (not re-verified against code in this pass, still valid as product direction):
- **C5** — AI-assisted internal linking suggestions
- **C6** — Google Search Console integration
- **C7** — Visible breadcrumbs (theme-facing render function/shortcode, distinct from the existing JSON-LD BreadcrumbList)
- **C8** — Polylang compatibility (currently WPML-only)

None of these block v1.0. C1–C3 are the only items directly surfaced by this audit; C4 is an investment decision, not a release blocker; C5–C8 were already deferred by product decision before this audit began.

## What this scope explicitly does NOT include

No feature catalogued as PRODUCTION_READY or FUNCTIONAL_NEEDS_HARDENING-for-missing-tests-only is being cut, hidden, or rewritten. The "hardening" work in Phase 8 is scoped exclusively to Group B — fixing the 13 concrete items above — not a general refactor, not new features, not chasing 100% test coverage before release.
