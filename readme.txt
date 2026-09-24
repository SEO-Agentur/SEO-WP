=== Seooptimierung Toolkit ===
Contributors: seooptimierung
Donate link: https://buymeacoffee.com/cyrusbadde
Tags: seo, wpml, polylang, sitemap, schema
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A slim SEO plugin with full WPML and Polylang support: meta tags, JSON-LD schema, XML sitemaps and crawl control.

== Description ==

Seooptimierung Toolkit covers the essentials of on-page and technical SEO without a scoring UI, nag screens or external tracking. It works out of the box with sensible defaults, and stays out of the way for any public post type or taxonomy your site adds — nothing here is hardcoded to "post" or "page".

Built and maintained by [Cyrus Badde](https://seooptimierung.de/seo-toolkit). If it saves you time, you can [buy me a coffee](https://buymeacoffee.com/cyrusbadde) — entirely optional, the plugin stays fully free either way.

**Meta**

* Title and meta description templates, with a token system compatible with Yoast, Rank Math and AIOSEO syntax (`%title%`, `%sitename%`, `%sep%`, `%excerpt%`, `%category%`, `%date%`, `%page%`, `%year%` — always the current year, for evergreen "{title} {year}" titles)
* A read-only overview table at the top of the Content Types settings tab summarising every currently configured title/description template, so the full picture is visible without scrolling through the editable fields
* Robots meta directives (noindex, nofollow, noarchive, nosnippet, noimageindex), layered on top of WordPress core's own `wp_robots` output rather than duplicating it
* Self-referencing canonical URLs, correctly paginated, WPML/Polylang-aware

**Social & structured data**

* Open Graph and Twitter Card meta, with a shared image fallback (per-object override → featured image → site logo → site icon) — the same resolution, and the same override field, also supplies the Article/Product schema image, so social and structured data never disagree
* A single deduplicated JSON-LD `@graph`: WebSite, Organization/Person/LocalBusiness, WebPage, Article (for non-hierarchical post types), BreadcrumbList, FAQPage, and Product when WooCommerce is active — exactly one primary content node per URL, never Article and Product stacked on the same page
* Local business identity: set "Site represents" to "A local business" for a full LocalBusiness node (or any more specific Schema.org business type — Restaurant, Store, ProfessionalService, and so on) with address, geo coordinates, phone, price range and opening hours, on the same Organization node rather than a second competing one
* The schema type of that primary content node is freely overridable per post *and* per taxonomy term (with autocomplete suggestions, but any Schema.org type name is accepted) — a specific article, product, product category, or plain category page can each be set independently
* Product schema includes an `aggregateRating` node (star ratings in search results) whenever a WooCommerce product has reviews, sourced directly from WooCommerce's own rating data, plus optional brand/GTIN/MPN/price-valid-until fields and the full WooCommerce image gallery — none of which WooCommerce core has a field for, so they're edited in the Seooptimierung Toolkit metabox's Schema tab
* A live Google search-result preview and social share-card preview in the metabox, updating as you type the title/description/social fields (falling back to the post title/excerpt when those are left empty) — mirrored in a block editor sidebar panel too, so the title/description fields and a live length traffic-light badge stay visible without scrolling down to the metabox

**Sitemaps**

* Dynamic XML sitemaps (post types, taxonomies, images, videos, news) with no physical files written to the webroot, cached in the object cache — every public post type and taxonomy is included automatically, WooCommerce products and product categories among them, with no special-casing needed
* Per-post-type and per-taxonomy inclusion toggles on the Sitemaps settings tab, so any type (including products/product categories) can be excluded individually
* Image and video sitemap entries are extracted from the fully rendered page output, not raw post content — so pages built with Divi, WPBakery or other shortcode/dynamic-content builders still get their media listed, not just Gutenberg block markup. A video entry is only added when a real thumbnail is actually available (a self-hosted `<video poster>`, or a YouTube embed's own well-known thumbnail URL) rather than guessing one
* An independent toggle to leave hreflang alternates out of sitemaps entirely (Settings > Sitemaps), for setups that want the per-page `<link rel="alternate">` tags to be the only source of truth
* Optionally disables WordPress core's own `/wp-sitemap.xml`

**Crawl control**

* A virtual, editable robots.txt with an AI-crawler opt-out toggle (GPTBot, ClaudeBot, CCBot, PerplexityBot and others) and a Sitemap line
* A virtual `/llms.txt` and optional `/llms-full.txt` — both auto-generated, plus a free-text field for content you write yourself, appended after the generated list
* Google/Bing/Baidu/Pinterest/Yandex site verification meta tags
* Optional author/date archive disabling
* Attachment pages: redirect to the parent post (default), keep reachable but noindexed, or leave to WordPress core
* Optionally strips the `<a>` WordPress wraps around an inserted image when it links to that image's own attachment page — the image itself is untouched, and only a genuine self-link is removed, never a deliberate link to something else
* IndexNow support (Bing, Yandex, Seznam, Naver — Google does not participate): pings on publish/update/delete, serves the required key file
* Google Instant Indexing: pings Google's Indexing API on publish/update/delete using a Google Cloud service account (signed JWT/OAuth2, no third-party library), per post type, with a submission history log
* A redirect manager (exact source path or regular expression → target URL, with `$1`/`$2` backreferences in the target for pattern rules, 301/302/307/410/451) with CSV import/export, and a 404 monitor that logs real broken-link hits with a one-click "create redirect" action
* Staging-site protection: a dismissible admin notice when the site's own URL looks like a staging/development environment (staging./dev./.local/.test subdomains, common host-provider staging domains, bare IP addresses), with a one-click "noindex this entire site" action — plus the same override as a plain settings checkbox, independent of detection. When on, it overrides every other robots setting on every page, site-wide

**Performance**

* A large set of opt-in, off-by-default toggles (Settings > Performance): strip WordPress's own generator/RSD/wlwmanifest/shortlink/adjacent-post links, disable emojis/embeds/Dashicons, limit or disable post revisions, override the autosave interval, control the Heartbeat API, disable comments sitewide, block Google Maps scripts, trim WooCommerce assets outside shop pages, disable XML-RPC, remove jQuery Migrate, disable or trim RSS feeds, disable self-pingbacks, and restrict the REST API to logged-in users
* A hand-written Critical CSS field (inlined in `<head>`) with an independent toggle to load every other stylesheet non-blocking — this plugin never guesses at critical CSS itself, since that requires actually rendering the page
* A manual resource-hints field (preconnect/dns-prefetch/preload/prefetch/prerender) plus a "Scan homepage for suggestions" button that fetches your own front page, lists every external script/stylesheet origin found, and lets you add the ones you want — never inserted automatically
* Settings > Server: HTTPS/canonical-URL redirects and security headers (HSTS, CORS, modern MIME types) applied in pure PHP, working on any web server; gzip/Brotli/browser caching, hotlink protection and directory-browsing prevention via a reviewable, one-click-apply `.htaccess` block on Apache/LiteSpeed (only where PHP genuinely can't reach — a static asset request never runs WordPress at all)

**WPML & Polylang**

* Full hreflang support via WPML's own translation data, or natively via Polylang when WPML isn't active — only existing, translation-plugin-confirmed alternates are ever emitted, self-reference always included, `x-default` configurable. With neither (nor a compatible plugin) active, no hreflang or `x-default` tags are output at all
* The *same* hreflang output (and the same third-party compatibility filters) in both the document head and every sitemap URL entry, for WPML and Polylang alike
* Title/description templates are translatable per language via WPML String Translation
* Designed as a companion to the separate `slim-hreflang` plugin: this plugin is the sole hreflang sender and exposes the `seooptimierung/hreflang/alternates` and `seooptimierung/hreflang/managed_externally` filters so other plugins can extend, override, or fully take over its output

**Import**

* Non-destructive, dry-run capable, paginated import from Yoast SEO, Rank Math and All in One SEO — object meta, term meta, and (Yoast and Rank Math) author archive noindex settings; AIOSEO has no per-author SEO settings to import. Rank Math's Schema.org type mapping specifically is best-effort — Rank Math's internal type slugs (both the legacy dropdown and the newer per-post Schema Generator) are translated via an inferred lookup that hasn't been verified against Rank Math's own source, so always spot-check imported schema types in the dry-run preview
* Site-wide settings (title/description templates, separator, Twitter handle) are also imported from Yoast (high confidence — a stable option layout) and Rank Math (moderate confidence — flag any surprising result and adjust by hand); AIOSEO's settings format isn't imported, since it was never verified with enough confidence to map safely
* Existing redirect rules from another plugin are never migrated automatically; the import wizard flags when a source has redirect data so you can recreate it in Seooptimierung Toolkit's own Redirects screen
* An explicit "Deactivate {plugin} now" button appears after a real (non-dry-run) import completes — never automatic, and only shown when that plugin is actually confirmed active
* Safe to re-run at any time: a field already set in Seooptimierung Toolkit — whether from an earlier import or edited by hand — is never overwritten, so running the import again only fills in what's still empty
* Coexistence Mode (Settings > General): for when Yoast SEO or Rank Math needs to stay active instead of being replaced — Seooptimierung Toolkit stops printing its own title/meta description/canonical/Open Graph/Twitter Card/schema output, and the Bulk Editor redirects to that plugin's own fields instead

**Admin**

* A tabbed metabox (General/Social/Schema) on every public post type's edit screen, and the same General/Social/Schema fields on taxonomy term edit screens (the FAQ repeater is post-only) — the General tab also has an optional focus keyword field, used as a hint by the AI bulk-edit suggestions below, not for any scoring feature
* A single settings screen (General/Content Types/Sitemaps/Crawl) — the Content Types tab is generated from your site's actual registered post types and taxonomies
* A bulk editor for Title/Description/Canonical/Robots across every public post type
* Bring-your-own-key AI in the Bulk Editor: Claude or OpenAI generate a title/description pair in a "{keyword}: {USP} | {USP} > {CTA}" template from the page's URL, content and focus keyword — written straight into the row's fields for review, never saved until you click "Save changes"; a one-click action offers suggestions for every still-empty field on the page. DeepL translates a row's title/description into every other WPML-linked translation of that post. Every provider is entirely optional, configured with your own API key on the new AI Settings page, and never called except on an explicit click — no proxying, no markup on your usage
* A Duplicate Content Checker finding posts that share the exact same *effective* title or meta description — the value actually used on the front end, whether from a manual override or a rendered template
* A Content Freshness report listing published posts that haven't been updated within a threshold you choose (3/6/12/24/36 months) — purely informational, nothing here changes anything automatically
* A persistent Link Audit finding orphan pages, broken links and redirect chains across content, rendered templates, menus and widgets; large audits continue server-side after the browser tab is closed
* A guided import wizard with a live progress log
* A Diagnostics page recording anything that went wrong during detection or import (a database error, an unexpected data shape), with a site-wide admin notice when something needs attention
* A Role Manager controlling which WordPress roles can access which parts of the plugin (settings, metabox editing, bulk editor, import, diagnostics, redirects, 404 monitor, duplicate checker, link audit, content freshness), via virtual capabilities rather than rewriting your roles — safe to enable/disable at any time, and a no-op until you actively configure it

**Developers**

* `seooptimierung/title`, `seooptimierung/meta_description` and `seooptimierung/canonical` filter the final title/description/canonical, after Seooptimierung Toolkit's own override and template resolution
* `seooptimierung/opengraph/tags` filters the full set of `og:*` tags before they're printed; `seooptimierung/schema/graph` filters the collected JSON-LD nodes before `@id` deduplication, so another plugin can add or replace a node
* None of the above are attached to anything by default — a site that never uses them pays no cost for their existence
* Full parameter reference, examples and the public constants/methods worth calling directly (redirect matching, staging detection, Coexistence Mode's own state) are in `docs/DEVELOPERS.md` in the plugin's source repository

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it through the WordPress plugins screen.
2. Activate the plugin.
3. Visit the Seooptimierung Toolkit menu to review defaults; no configuration is required to start.

== Frequently Asked Questions ==

= Does this replace Yoast SEO, Rank Math or All in One SEO? =

Normally, yes — use the Import screen to bring over your existing titles, descriptions, robots settings, social meta and (Yoast/Rank Math) global settings, then use the "Deactivate now" button that appears after a real import to turn off the previous plugin (it's never deactivated automatically). If you need to keep Yoast or Rank Math active anyway, Settings > General has a Coexistence Mode: Seooptimierung Toolkit stops printing its own title/meta description/canonical/Open Graph/Twitter Card/schema output entirely (so you never get duplicate tags), and the Bulk Editor reads and writes that plugin's own fields instead of Seooptimierung Toolkit's. Everything else Seooptimierung Toolkit does — sitemaps, hreflang, redirects, the 404 monitor, IndexNow, Link Audit, Duplicate Checker, Content Freshness, Role Manager, Performance — keeps working either way, since none of it duplicates what Yoast/Rank Math already do.

= Are redirects imported? =

No. Existing redirect *rules* from another plugin aren't migrated automatically — the import wizard tells you when it found some so you can recreate them by hand. Seooptimierung Toolkit does have its own Redirects screen (exact-path source → target URL, 301/302/307/410/451) and a 404 monitor for creating new ones going forward.

= Does this work without WPML or Polylang? =

Yes. Every WPML/Polylang-specific feature (hreflang, per-language templates) is a no-op when neither is active — nothing here is a hard dependency.

= Do I need slim-hreflang if I'm already using WPML or Polylang? =

No. slim-hreflang exists for cross-*domain* hreflang (separate WordPress installs, e.g. one per market) — a scenario WPML and Polylang don't cover since they translate within a single install. If you're using WPML or Polylang, Seooptimierung Toolkit's built-in hreflang support already covers your site; there's nothing more to install.

== External services ==

Seooptimierung Toolkit integrates with several third-party services. Every one is optional, off by default, and only ever contacted when you explicitly enable and use that specific feature — nothing below runs on its own without your action, with the sole exception of the two "on publish" pings noted, which only fire automatically once you've explicitly turned that toggle on.

**Google Indexing API** — notifies Google when you publish, update or delete content, if enabled under Seooptimierung Toolkit > Google Indexing API with your own Google Cloud service-account key. Sends the affected page's URL to Google on publish/update/delete. You provide and own the Google Cloud service account; Seooptimierung Toolkit never proxies this call or adds a markup. [Google APIs Terms of Service](https://developers.google.com/terms), [Google Privacy Policy](https://policies.google.com/privacy).

**IndexNow** (Bing, Yandex, Seznam, Naver) — pings the shared IndexNow endpoint (api.indexnow.org) on publish/update/delete if enabled under Seooptimierung Toolkit > Instant Indexing, or when you manually submit URLs there. Sends the affected page URL(s) and your site's IndexNow key (auto-generated, or one you choose). [IndexNow protocol & privacy](https://www.indexnow.org/privacy).

**Anthropic (Claude)** — contacted only when you click "Suggest" or "Translate" in the Bulk Editor with an Anthropic API key configured under Seooptimierung Toolkit > AI Settings and Anthropic selected as the provider. Sends the edited page's URL, your focus keyword, and up to roughly 1200 characters of its rendered content to Anthropic's Messages API, to generate a title/description suggestion. Bring-your-own-key: billed directly by Anthropic, never proxied or marked up by Seooptimierung Toolkit. [Anthropic Consumer Terms](https://www.anthropic.com/legal/consumer-terms), [Anthropic Privacy Policy](https://www.anthropic.com/legal/privacy).

**OpenAI** — same trigger and the same data as the Anthropic integration above, sent to OpenAI's Chat Completions API instead, only when an OpenAI key is configured and OpenAI is the selected provider. Bring-your-own-key, same as Anthropic. [OpenAI Terms of Use](https://openai.com/policies/terms-of-use), [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy).

**DeepL** — contacted only when you click "Translate" in the Bulk Editor on a WPML-enabled site with a DeepL API key configured. Sends the row's title/description text to DeepL's Translate API, once per target language, and writes the result straight into that language's own WPML-linked post. Bring-your-own-key. [DeepL Terms & Conditions](https://www.deepl.com/en/pro-license), [DeepL Privacy Policy](https://www.deepl.com/en/privacy).

**Resource Hint Scanner** — contacted only when you click "Scan homepage for suggestions" under Settings > Performance. Fetches your *own* site's homepage (never a third party) to detect external script/stylesheet hosts worth preconnecting to. The result is a list of suggested lines you may choose to add to your own settings — nothing is applied automatically and nothing is sent anywhere else.

== Changelog ==

= 1.5.0 =
* Renamed the plugin from "SEO-WP" to "Seooptimierung Toolkit" (slug: `seooptimierung-toolkit`), following a WordPress.org review that flagged the discouraged term "wp" in the name/slug. The public filter API is renamed to match: `seo_wp/*` is now `seooptimierung/*` (`seooptimierung/title`, `seooptimierung/meta_description`, `seooptimierung/canonical`, `seooptimierung/opengraph/tags`, `seooptimierung/schema/graph`, `seooptimierung/sitemap/excluded_post_ids`, and the two-filter hreflang contract `seooptimierung/hreflang/alternates`/`seooptimierung/hreflang/managed_externally`) — a breaking change for any site using these filters or the slim-hreflang companion plugin, which needs a matching update.
* Internal option and post/term meta key names (`seo_wp_settings`, `_seo_wp_title`, etc.) are unchanged, so existing installs keep all their configured settings and per-post/term SEO data across the rename with no migration needed.
* Fixed: `CriticalCss`'s inline `<style>` output now goes through `wp_add_inline_style()` instead of a raw echoed tag, per WordPress.org's plugin guidelines.

= 1.4.8 =
* Security: the new Polylang hreflang support (1.4.7) could emit the permalink of a draft/private translation on its published sibling's page — a common editorial workflow (linking a published post to an in-progress draft translation) previously leaked the draft's URL and slug in the hreflang tag and sitemap entry. Alternates now only resolve to a published post's URL, both in the document head and sitemap
* Performance: sitemap page-range validation now only runs on a cache miss instead of on every request — a cache hit (the common case once a sitemap has been crawled) no longer pays for an extra query it doesn't need
* Performance: the taxonomy sitemap's eligible-term lookup and the new `seooptimierung/sitemap/excluded_post_ids` filter are now each computed once per request instead of twice, on a cache miss
* Performance: the Duplicate Content scanner now primes the post meta cache for its batch, turning what was one meta query per post into one batched query
* Hardened: `max_pages()` for both sitemap types now rejects a nonexistent post type/taxonomy before running any query, matching the check `render()` already had
* This release followed a fresh, independent adversarial review of every change made in 1.4.6/1.4.7 (not just the original audit) — see `docs/audit/` for the full findings, including several specifically-tested items (URL validation bypasses, a PEM-check anchor bypass, a namespaced-constant check) that were verified clean, not just assumed

= 1.4.7 =
* New: native Polylang support for hreflang — alternate-language URLs are now computed directly from Polylang's own translation data (`pll_get_post_translations()`/`pll_get_term_translations()`) whenever WPML isn't active, in both the document head and every sitemap URL entry, with the same self-reference and configurable `x-default` behavior WPML already had
* New: with neither WPML nor Polylang (nor a compatible plugin via the `seooptimierung/hreflang/*` contract) active, hreflang output — including `x-default` — is now never emitted at all, closing the one path where an `x-default` tag could previously appear without a real localization plugin behind it
* New: `seooptimierung/sitemap/excluded_post_ids` filter — lets a companion plugin (e.g. an e-commerce plugin that noindexes its own cart/checkout/account pages by another means) keep specific post IDs out of every Seooptimierung Toolkit sitemap, matching Google's guidance that a noindexed URL shouldn't appear in a sitemap
* Canonical URLs, sitemaps and the JSON-LD `@graph` are all already Polylang-compatible with no code changes needed: canonical URLs use each translation's own real permalink, sitemaps are page-type-agnostic and cover any public post type Polylang translates, and `seooptimierung/schema/graph` is a documented public filter a WooCommerce-alternative product like WopCart can merge its own Product node into, the same way it already can with Yoast/Rank Math/AIOSEO

= 1.4.6 =
* Security: fixed an authorization bug in Role Manager where saving the roles form could silently strip the Administrator role's own access to Settings, the SEO metabox, Bulk Editor, Redirects, Import and Diagnostics, with no self-service recovery — every area is now correctly kept for Administrator on every save, plus a defense-in-depth `manage_options` bypass
* Security: sitemap and head-fragment caches are no longer flushed on every autosave/revision save (only on a real publish/update), and sitemap page numbers are now validated against the actual page count before querying — closing an unauthenticated cache-bypass amplification vector
* Security: the Google Indexing service-account key is now validated as an actual PEM key before use, rejecting `phar://`/`http://`/`file://` stream-wrapper values
* Security: the canonical URL override (post/term metabox field, writable by Author/Contributor-level roles by default) now requires a well-formed absolute http(s) URL, closing a canonical-hijacking vector
* Security: the Settings page now correctly enforces its own virtual capability when saved (previously fell back to requiring real `manage_options`, breaking Role Manager's own delegation for this page)
* Hardened: the sitemap cache key now includes the active WPML language, a `</style>` breakout guard is now case-insensitive, the video sitemap's HTML scan is now length-bounded, the content renderer restores global state even if a third-party filter throws, the autoloader rejects malformed class names, and the 404 monitor's referrer field is now length-capped
* Hardened: the Duplicate Content and Link Audit batch scanners now guard against overlapping concurrent runs corrupting shared scan state
* This release followed a second, independent security audit — going beyond the WordPress.org Plugin Check and the externally-authored audit both covered in 1.4.4/1.4.5 — see `docs/audit/` for the full findings and reasoning behind each fix

= 1.4.5 =
* Security: fixed an SSRF in the Link Audit broken-link checker — server-side HTTP requests (including every redirect hop) now go through wp_safe_remote_head()/wp_safe_remote_get(), rejecting loopback/private/reserved/link-local destinations
* Security: moved the Anthropic, OpenAI, DeepL and Google Indexing API keys off the autoloaded general settings option into their own dedicated, non-autoloaded storage
* Security: redirect regex patterns are now also checked at match time for PCRE backtrack/recursion-limit errors, not just at save time — a pattern that repeatedly hits the limit against real requests is automatically deactivated and flagged in the Redirects table
* Security: added file size, row count and field length ceilings to the redirect CSV importer
* Security: reduced database write amplification from anonymous 404-flooding (path length cap, less frequent log trimming)
* Security: the Link Audit background worker's authentication token is now invalidated as soon as its job completes or errors, closing a token-replay window
* This release followed an independent, verified security audit — see `docs/audit/` for the full findings and reasoning behind each fix

= 1.4.4 =
* Ran the official WordPress.org Plugin Check tool against a live install and fixed everything it flagged that was safe to fix: replaced a denylisted function (`wp_get_sidebars_widgets()`) in Link Audit's widget scanner, bumped "Tested up to" to WordPress 7.1, filled in the real Contributors username, and stopped shipping a stray dotfile in the languages folder
* See `docs/release/WORDPRESS-ORG-CHECKLIST.md` for the full findings — including two open questions (the plugin name's "wp" trademark flag, and WordPress 7.0's new AI Client core API) that need a product decision rather than a silent code change

= 1.4.3 =
* Security: escaped the document `<title>` output, closing an XSS gap on the `pre_get_document_title` short-circuit path where WordPress core doesn't apply its own escaping
* Security: the Google Indexing API's service-account key field is now write-only (never echoed back), matching how the AI provider keys already work
* Security: redirect regex patterns are now checked for the classic nested-quantifier shapes that cause catastrophic backtracking, and capped at 500 characters, before being accepted
* Security: enabling CORS with no origin configured now sends no CORS headers at all, instead of defaulting to `Access-Control-Allow-Origin: *`
* Fixed: the block editor's "Seooptimierung Toolkit" sidebar panel threw a JavaScript error on every post-edit screen and never actually rendered; the classic metabox was unaffected throughout
* Fixed: a WooCommerce product with no price set could emit an invalid `"price": ""` in its Product schema; the offers block is now omitted instead
* Fixed: uninstalling the plugin left 13 of its own options behind (role matrix, duplicate/link-audit scan state, Google Indexing token/log, IndexNow log, 404 log, redirects, a staging-notice flag, the error log) — all are now removed, matching the plugin's own documented intent. Post/term SEO data remains preserved on uninstall, as before
* Added a WordPress.org-required "External services" section to the readme, disclosing all 6 outbound integrations (Google Indexing, IndexNow, Anthropic, OpenAI, DeepL, the Resource Hint Scanner)
* Documented that Rank Math's Schema.org-type import mapping is best-effort/unverified
* This release followed a full production-readiness audit — see `docs/audit/` and `docs/release/` for the complete findings and scope decisions

= 1.4.2 =
* New public filter API for developers: `seooptimierung/title`, `seooptimierung/meta_description`, `seooptimierung/canonical`, `seooptimierung/opengraph/tags` and `seooptimierung/schema/graph` — customize any of Seooptimierung Toolkit's own head output from a theme or another plugin without editing this plugin's files. None attached by default, so existing sites are unaffected. See `docs/DEVELOPERS.md` for the full reference.

= 1.4.1 =
* Changed: the slim-hreflang compatibility contract's filter names were renamed from `slimseo/hreflang/alternates` / `slimseo/hreflang/managed_externally` to `seooptimierung/hreflang/alternates` / `seooptimierung/hreflang/managed_externally` — the old names collided with (and read as belonging to) the unrelated, discontinued third-party "Slim SEO" plugin, not this plugin's own companion. Both sides of the contract were updated together; requires slim-hreflang 1.2.2 or later to keep working.

= 1.4.0 =
* New Content Freshness report (Seooptimierung Toolkit > Content Freshness): published posts not updated within a threshold you choose (3/6/12/24/36 months), purely informational
* Redirects: regular-expression source patterns with `$1`/`$2` backreferences in the target (alongside the existing exact-path rules), plus CSV import/export
* New block editor sidebar panel mirroring the metabox's Title/Meta description fields, with a live SERP preview and a character-count traffic-light badge — the classic metabox gained the same badge. The sidebar is a UI mirror only; the metabox's own fields remain the single thing that's actually saved
* New video:video sitemap entries for self-hosted `<video poster>` elements and YouTube embeds, and an independent toggle to leave hreflang alternates out of sitemaps entirely (Settings > Sitemaps)
* Product schema gained optional brand/GTIN/MPN/price-valid-until fields (Seooptimierung Toolkit metabox's Schema tab, WooCommerce products only) and now lists the full WooCommerce image gallery, not just the primary image

= 1.3.5 =
* New Coexistence Mode (Settings > General): when Yoast SEO or Rank Math needs to stay active alongside Seooptimierung Toolkit, this stops Seooptimierung Toolkit printing its own title/meta description/canonical/Open Graph/Twitter Card/JSON-LD output — avoiding literal duplicate tags rather than just a value disagreement — and redirects the Bulk Editor's Title/Description/Canonical columns (including AI suggestions and DeepL translations) to that plugin's own postmeta fields. Off by default; everything else Seooptimierung Toolkit does is unaffected.

= 1.3.4 =
* Schema: "Site represents" gained "A local business" — address, geo coordinates, phone, price range and opening hours on the existing Organization node (LocalBusiness is itself an Organization subtype in Schema.org, so this isn't a second, competing node), with any Schema.org business subtype accepted (Restaurant, Store, ProfessionalService, etc.).
* Crawl: a staging-site safeguard — a dismissible admin notice when the site's own URL matches a common staging/development pattern, with a one-click site-wide noindex action; the same override is also a plain settings checkbox, reachable without waiting for the notice.

= 1.3.3 =
* Performance: documented that "Restrict the REST API to logged-in users" also blocks the slim-hreflang companion plugin's own multisite sync route, since that authenticates with its own secret rather than a logged-in WordPress session — deliberately not silently exempted, so the toggle always does exactly what it says.

= 1.3.2 =
* Performance: two more one-line `<head>` removals — the wlwmanifest (Windows Live Writer) link and rel="prev"/"next" adjacent-post links — closing out the last of the well-known WordPress "head bloat" list; everything else on it (generator tag, RSD link, shortlink, oEmbed discovery, emoji scripts, REST/RSS auto-discovery links) already had a toggle.
* Performance: a "Scan homepage for suggestions" button next to the Resource Hints field — fetches your own front page, lists every external script/stylesheet origin it finds (preconnect for origins with more than one resource, dns-prefetch for a single one), and lets you pick which to add. Never inserted automatically, and doesn't replace the manual field — this only suggests what to put there.
* Link Audit: the background broken-link check is now fully admin-configurable (Settings on the Link Audit screen) — manual-only by default, or daily/weekly/monthly if you opt in. Previously ran unconditionally every 15 minutes.

= 1.3.1 =
* Fixed the Yoast/Rank Math/AIOSEO import not showing up: writes went through update_post_meta()/update_term_meta()/update_option(), none of which invalidate the schema/hreflang fragment cache — a page rendered (and cached) before an import could keep serving its pre-import JSON-LD for up to 24 hours afterwards. The import now flushes that cache itself.
* The import log now distinguishes "the source plugin had nothing here" from "the source plugin had a value, but Seooptimierung Toolkit already had one too, so it was left alone" — previously both looked identical ("no changes"), making it impossible to tell why a field stayed empty. Confirmed Product schema is generated live from WooCommerce's own data (not imported), and that OpenGraph/Twitter already fall back to the resolved title/description when their own fields are empty — both already worked correctly, no gap found there.
* Bulk Editor: nicer pagination (item-count summary, per-page selector up to 200 rows, a "go to page" jump alongside the page-number links, above and below the table) for sites with many more than a handful of pages.
* Bulk Editor: a traffic-light character-count badge next to Title and Description — red/amber/green against the same length guidance a Google search result truncates to, updating live as you type.
* Fixed 5 failing unit tests and a large block of code-style debt in Link Audit, left over from the 1.2.9 rewrite (a test suite calling a method that had been renamed, not removed).

= 1.3.0 =
* Instant Indexing is now actually IndexNow (Bing, Yandex, Seznam, Naver): an optional custom API key, a manual "paste a URL list" submission form, one-click bulk indexing of every post type and taxonomy (all pre-selected, individually deselectable), and a submission history log. Google's Indexing API moved to its own "Google Indexing API" page so the two unrelated services are no longer conflated on one screen.
* Fixed the IndexNow key generator to always produce a key matching its own verification-file rewrite rule (previously could generate characters the rule didn't accept).
* Added an optional Google News sitemap toggle (Settings > Sitemaps): a dedicated, spec-compliant /news-sitemap.xml (last 48 hours, 1000 URL cap) for sites that publish news content; off leaves it out of the sitemap index entirely.
* Added a large set of opt-in performance toggles (Settings > Performance): disable emojis/embeds/Dashicons, limit or disable post revisions, override the autosave interval, control the Heartbeat API, disable comments sitewide, block Google Maps scripts, trim WooCommerce assets outside shop pages, disable XML-RPC, remove jQuery Migrate, remove the WP version/RSD link/shortlink, disable or trim RSS feeds, disable self-pingbacks, and restrict the REST API to logged-in users.
* Added a manual resource-hints textarea (Settings > Performance): preconnect/dns-prefetch/preload/prefetch/prerender entries, one per line, output early in <head>.
* Added a new Server settings tab: HTTP→HTTPS, WWW↔non-WWW and trailing-slash redirects, duplicate-slash canonicalization, security headers, HSTS, CORS, modern upload MIME types, and — applied via an explicit "Apply to .htaccess" button, never automatically — Gzip, Brotli, browser caching, hotlink protection and disabling directory browsing, each wrapped in <IfModule> so an unsupported module is simply skipped.

= 1.2.9 =
* Rebuilt Link Audit as a persistent server-side background job that continues after the browser tab is closed.
* Added automatic recovery when a worker is interrupted or a hosting loopback request is delayed.
* Increased content batches dynamically to up to 25 posts while retaining a conservative PHP runtime budget.
* Valid internal WordPress permalinks are resolved locally and no longer require a slow HTTP request.
* Unique URLs remain deduplicated while all individual source pages and source areas are preserved.
* Added persistent progress reporting for pages scanned, unique URLs checked, current item, elapsed time, and last activity.
* Reduced external request timeout and updated plugin/readme version metadata.

= 1.2.8 =
* Link Audit now shows the currently processed page or URL together with continuously updated elapsed time.
* Split large audits into small AJAX batches so the interface remains responsive and progress stays visible.
* Added a conservative runtime budget for link-check batches to avoid PHP max-execution-time failures on large sites or slow external servers.
* Reduced content-scan batch size for safer processing of shortcode- and page-builder-heavy pages.
* Updated plugin and readme version metadata.

= 1.2.7 =
* Combined content scanning and queued URL checking into one complete Link Audit action.
* Removed the redundant second manual link-check button.

= 1.2.6 =
* Added redirect, redirect-chain and redirect-loop reporting.
* Added source-page titles and source areas such as content, menu, widget and rendered template elements.
* Cleared stale findings during a fresh audit so repaired links disappear from the results.

= 1.2.5 =
* Fixed Link Audit and Role Manager admin-page registration order and capability handling.
