# SEO-WP — Follow-up Review (2026-09-15): performance + adversarial self-review of 1.4.6/1.4.7

Two more passes were run after 1.4.6 (fixes from the internal security audit) and 1.4.7 (native Polylang hreflang support, `seo_wp/sitemap/excluded_post_ids`): a performance-focused review, and an adversarial security review of every line changed in those two releases specifically — including deliberately re-testing several of this session's own fixes rather than assuming they were correct. All real findings below shipped in 1.4.8.

## Security

**[Medium, fixed] Polylang hreflang could leak a draft/private translation's permalink**

Neither the new Polylang code path (document head or sitemap) checked the status of a translated post before resolving and emitting its URL. `pll_get_post_translations()` returns whatever translation relationship exists in Polylang regardless of status — linking a published post to an in-progress draft translation is a completely normal editorial workflow, and doing so caused the *published* post's own page to emit an `hreflang` tag (and a sitemap `xhtml:link` entry) pointing at the draft's real URL and slug, disclosing it before publication.

**Fix:** new `Hreflang::resolve_published_post_permalink()`, used as the URL resolver everywhere a translation ID is resolved to a URL — both the Polylang current-request path (`Wpml\Hreflang`) and the shared post resolver in `Sitemaps\HreflangAlternates::for_post()` (which also closes the equivalent, previously-unnoticed gap in the WPML sitemap path, since both branches share that one resolver).

**Verified clean, not re-flagged:** `Canonical::sanitize_override()` (tested `//evil.com`, `https:evil.com`, control characters, a 100k-char string — all correctly rejected by `FILTER_VALIDATE_URL`); `GoogleIndexing::build_jwt()`'s PEM check (confirmed the `^` anchor genuinely blocks a `phar://`/`http://` prefix, and a string starting with `-----BEGIN` structurally cannot also be a bare stream-wrapper path); the RoleManager admin-lockout fix (confirmed robust against the "different role literally named administrator" edge case); WopCart's `defined('SeoWp\VERSION')` check (confirmed semantically correct — `defined()` takes a plain string, unaffected by the calling file's own namespace).

**Noted, not changed:** a Settings-trusted role also implicitly gets Role Manager/Link Audit/Duplicate Checker access (pre-existing, intentional, documented in code — not introduced this session); the scan-batch locks added in 1.4.6 have a low-severity TOCTOU and can be held open by an already-authorized user re-triggering requests near the 30s TTL (same-privilege-tier availability nuisance, not an escalation — no fix applied, flagged for awareness).

## Performance

**[Fixed] Sitemap page-range validation ran on every request, not just cache misses.** `Router::maybe_render()` validated the requested page (`page_in_range()` → `max_pages()`, a real query) *before* the cache lookup — meaning a fully cached, previously-crawled sitemap page paid for a full extra query on every hit, most of the point of the 1-hour cache. Moved the check inside `cached_render()`, after the cache lookup, so it only runs on a miss (an out-of-range page is still cheap — it never reaches `render()`'s own, potentially large-offset query, and the resulting "nothing to show" is itself cached).

**[Fixed] `TaxonomySitemap` computed its full eligible-term list twice per cache miss** (once via `max_pages()`, again via `render()`). Memoized per-taxonomy for the request.

**[Fixed] The new `seo_wp/sitemap/excluded_post_ids` filter re-ran on every `query_args()` build** within a request. Memoized for the request — the result can't legitimately change mid-request, and this also protects against a companion plugin's filter callback doing its own DB work more than once.

**[Fixed] `max_pages()` for both sitemap types didn't reject a nonexistent post type/taxonomy before querying**, unlike `render()`, which does. Both now check the same way.

**[Fixed, pre-existing] `DuplicateChecker`'s scan batch had a real N+1**: its `WP_Query` explicitly disabled meta-cache priming (`update_post_meta_cache => false`) while the loop immediately after it calls `get_post_meta()` per post via `TitleDescription`'s override lookups — 50 individual meta queries per AJAX batch step instead of one batched query. Now primes the cache.

**Verified acceptable, not changed:** `GraphBuilder::collect_nodes()`'s 8 node builders run no uncached DB queries and sit behind the 1-day fragment cache; the Polylang hreflang path's `pll_get_post_translations()` + `pll_get_post_language()` (two separate API calls per sitemap entry where one could suffice) is a micro-optimization Polylang's own internal caching already absorbs — left as a known, low-priority item.
