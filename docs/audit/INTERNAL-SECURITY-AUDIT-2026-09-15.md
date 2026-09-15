# SEO-WP — Internal Security Audit (2026-09-15)

An independent, from-scratch security review of the full plugin source, going beyond the WordPress.org Plugin Check pass (1.4.4) and the externally-authored SEC-001–006 audit (1.4.5, see `SECURITY-AUDIT-2026-09-15.md`). Five partitions of `src/` were each read in full by a dedicated reviewer with explicit adversarial instructions (authorization/CSRF/IDOR on every state-changing endpoint, output escaping, SQL usage, SSRF, ReDoS, deserialization, secret handling, cache-poisoning, business-logic integrity), every finding required a concrete exploit scenario before being reported, and every already-fixed item from the two prior audits was re-verified rather than re-flagged. All fixes below shipped in 1.4.6.

## High

**RoleManager: saving the roles form could silently lock the Administrator out of most of the plugin (`src/Admin/RoleManager.php`)**

`render_page()` renders every checkbox in the administrator row as `disabled`, so the browser never submits them. `handle_save()` rebuilt the matrix purely from submitted data, writing `false` for every administrator area except `role_manager` itself, which alone was force-restored. Only `role_manager`/`link_audit`/`duplicates` had a `manage_options` bypass in `grant_capabilities()` — the other 8 areas (Settings, Metabox, Bulk Editor, Redirects, Import, Diagnostics, 404 Monitor, Freshness) didn't, so any save of this form locked the real Administrator out of them, with no self-service recovery (the checkboxes render checked-but-disabled forever after).

**Fix:** `handle_save()` now forces every area `true` for `administrator`, not just `MANAGE_ROLES_CAP`. `grant_capabilities()` additionally gained an unconditional `manage_options` bypass as defense-in-depth, so a real Administrator can never be locked out of any `seo_wp_*` area even if the stored matrix somehow ends up wrong again.

## Medium

**Sitemap and head-fragment caches were flushed on every autosave (`src/Sitemaps/Router.php`, `src/Support/FragmentCache.php`)**

`save_post` fires unconditionally for every `wp_insert_post()` call, including autosaves and revisions. Neither cache invalidator guarded against this, so any editor's normal ~60-second autosave loop kept the entire sitemap and head-fragment caches perpetually cold.

**Fix:** both now bail out via `wp_is_post_revision()`/`wp_is_post_autosave()` before flushing.

**Unbounded sitemap page number enabled a cache-bypass DB-amplification vector (`src/Sitemaps/Router.php`, `src/Sitemaps/PostTypeSitemap.php`)**

`seo_wp_sitemap_page` was floored at 1 but never capped, so a crafted large page number reached `WP_Query`'s `paged`/`OFFSET` (or `get_terms()`) with a guaranteed cache-miss (each page number is its own cache key) and a real, unauthenticated DB cost per request — compounding with the cache-flush bug above.

**Fix:** `Router::maybe_render()` now validates the requested page against the sitemap's actual page count (`page_in_range()`) before ever touching the cache or a builder, 404ing immediately otherwise. `PostTypeSitemap::query_args()` also now only requests `SQL_CALC_FOUND_ROWS` when the caller (`max_pages()`) actually needs it, not on every render.

**Google Indexing accepted a PEM key argument that PHP could interpret as a stream-wrapper path (`src/Crawl/GoogleIndexing.php`)**

`build_jwt()` passed the admin-pasted `private_key` string straight to `openssl_pkey_get_private()`. PHP's OpenSSL functions follow `file://`/`phar://`/`http://` stream wrappers given as this argument — a known phar-deserialization/SSRF primitive — with no validation that the value actually looked like PEM key material.

**Fix:** rejects any value not matching `^-----BEGIN [A-Z ]*PRIVATE KEY-----` before it reaches `openssl_pkey_get_private()`.

**Canonical URL override had no URL/same-origin validation (`src/Meta/Canonical.php`, `src/Admin/Metabox.php`)**

The per-post/term canonical override is writable by any role granted `seo_wp_edit_meta`, which by default follows core's `edit_posts` — Contributors and Authors included. The raw value was only run through `sanitize_textarea_field()`, then later echoed as `<link rel="canonical" href="...">`. `esc_url()` at render time prevented XSS but not a non-URL or cross-domain value — a classic canonical-hijacking / negative-SEO vector.

**Fix:** new `Canonical::sanitize_override()` requires a well-formed absolute `http`/`https` URL, otherwise stores nothing (falls back to the computed canonical). Wired into `Metabox::save_common_fields()` for both the post and term paths.

## Low / Informational

- **Settings page capability mismatch (`src/Admin/Settings.php`):** the form submits through core's `options.php`, whose permission check defaults to requiring real `manage_options` unless told otherwise. Every other settings screen already uses its own capability-checked handler; this one didn't, silently breaking Role Manager's own delegation for the Settings area. Fixed via `option_page_capability_{$option_group}`.
- **Sitemap cache key omitted the WPML language dimension (`src/Sitemaps/Router.php`):** unlike `FragmentCache`, which already folds the active language into its key. Fixed the same way.
- **`</style>` breakout guard was case-sensitive (`src/Frontend/CriticalCss.php`):** `</STYLE>` wasn't neutralized. Switched to a case-insensitive replace.
- **Video sitemap HTML scan had no length bound (`src/Sitemaps/VideoSitemap.php`):** the `<video>...</video>` extraction regex is quadratic (not exponential) on adversarial content with many unclosed tags. Rendered content is now truncated before scanning.
- **`ContentRenderer::render()` didn't restore global state on a throwing filter (`src/Support/ContentRenderer.php`):** a third-party `the_content` callback throwing left `$GLOBALS['post']` pointed at the wrong post for the rest of a scanning-loop request. Wrapped in try/finally.
- **Autoloader didn't validate class-name characters (`src/Autoloader.php`):** no currently-reachable exploit path (no `unserialize()`/dynamic class instantiation anywhere in the plugin), but hardened anyway — malformed class names are now rejected before path-building.
- **404 monitor's referrer field had no length cap (`src/Crawl/NotFoundMonitor.php`):** unlike the already-capped `path` field. Now capped the same way.
- **Unlocked read-modify-write races in the legacy batch scanners (`src/Admin/DuplicateChecker.php`, `src/Admin/LinkAudit.php`):** two concurrent scan requests could interleave and corrupt shared option state. Added short-lived transient locks around each batch step, matching the pattern the persistent Link Audit worker already used.

## Verified clean (re-checked, not re-flagged)

SQL query construction across all four competing-plugin importers (`$wpdb->prepare()` on every value, hardcoded table names), outbound HTTP destinations in `AiClient`/`DeepLClient`/`IndexNow`/`GoogleIndexing` (all hardcoded endpoint constants), JSON-LD `</script>` breakout resistance (`wp_json_encode()`'s default `/` escaping), `Secrets` storage, `maybe_unserialize()` usage (no raw `unserialize()` anywhere in the plugin), every one of the 33 state-changing admin handlers' CSRF/capability/IDOR checks, sitemap XML well-formedness/escaping, and the `seo_wp/hreflang/*` public filter's trust boundary (this plugin's own escaping runs after any third-party filter, not before).

## Summary

| Severity | Count | Status |
|---|---|---|
| High | 1 | Fixed |
| Medium | 4 | Fixed |
| Low/Informational | 8 | Fixed |

All 13 fixes shipped in 1.4.6. Full phpunit (249 tests) and phpcs pass; new unit tests added for `Canonical::sanitize_override()`, `GoogleIndexing::build_jwt()`'s stream-wrapper rejection, and the autoloader's path-traversal rejection — the rest of the fixes touch WordPress-integration code (options, `WP_Query`, transients, admin rendering) outside this project's hand-rolled unit-test harness boundary and were verified by direct code review instead.
