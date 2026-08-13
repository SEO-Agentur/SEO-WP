=== SEO-WP ===
Contributors: yourwpusername
Donate link: https://buymeacoffee.com/cyrusbadde
Tags: seo, wpml, sitemap, schema, hreflang
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.3.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A slim, page-type-agnostic SEO plugin with full WPML compatibility: meta tags, social meta, JSON-LD schema, XML sitemaps and crawl control.

== Description ==

SEO-WP covers the essentials of on-page and technical SEO without a scoring UI, nag screens or external tracking. It works out of the box with sensible defaults, and stays out of the way for any public post type or taxonomy your site adds — nothing here is hardcoded to "post" or "page".

Built and maintained by [Cyrus Badde](https://seo-wp.net). If it saves you time, you can [buy me a coffee](https://buymeacoffee.com/cyrusbadde) — entirely optional, the plugin stays fully free either way.

**Meta**

* Title and meta description templates, with a token system compatible with Yoast, Rank Math and AIOSEO syntax (`%title%`, `%sitename%`, `%sep%`, `%excerpt%`, `%category%`, `%date%`, `%page%`, `%year%` — always the current year, for evergreen "{title} {year}" titles)
* A read-only overview table at the top of the Content Types settings tab summarising every currently configured title/description template, so the full picture is visible without scrolling through the editable fields
* Robots meta directives (noindex, nofollow, noarchive, nosnippet, noimageindex), layered on top of WordPress core's own `wp_robots` output rather than duplicating it
* Self-referencing canonical URLs, correctly paginated, WPML-aware

**Social & structured data**

* Open Graph and Twitter Card meta, with a shared image fallback (per-object override → featured image → site logo → site icon) — the same resolution, and the same override field, also supplies the Article/Product schema image, so social and structured data never disagree
* A single deduplicated JSON-LD `@graph`: WebSite, Organization/Person/LocalBusiness, WebPage, Article (for non-hierarchical post types), BreadcrumbList, FAQPage, and Product when WooCommerce is active — exactly one primary content node per URL, never Article and Product stacked on the same page
* Local business identity: set "Site represents" to "A local business" for a full LocalBusiness node (or any more specific Schema.org business type — Restaurant, Store, ProfessionalService, and so on) with address, geo coordinates, phone, price range and opening hours, on the same Organization node rather than a second competing one
* The schema type of that primary content node is freely overridable per post *and* per taxonomy term (with autocomplete suggestions, but any Schema.org type name is accepted) — a specific article, product, product category, or plain category page can each be set independently
* Product schema includes an `aggregateRating` node (star ratings in search results) whenever a WooCommerce product has reviews, sourced directly from WooCommerce's own rating data
* A live Google search-result preview and social share-card preview in the metabox, updating as you type the title/description/social fields (falling back to the post title/excerpt when those are left empty)

**Sitemaps**

* Dynamic XML sitemaps (post types, taxonomies, images, news) with no physical files written to the webroot, cached in the object cache — every public post type and taxonomy is included automatically, WooCommerce products and product categories among them, with no special-casing needed
* Per-post-type and per-taxonomy inclusion toggles on the Sitemaps settings tab, so any type (including products/product categories) can be excluded individually
* Image sitemap entries are extracted from the fully rendered page output, not raw post content — so pages built with Divi, WPBakery or other shortcode/dynamic-content builders still get their images listed, not just Gutenberg block markup
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
* A redirect manager (exact source path → target URL, 301/302/307/410/451) and a 404 monitor that logs real broken-link hits with a one-click "create redirect" action
* Staging-site protection: a dismissible admin notice when the site's own URL looks like a staging/development environment (staging./dev./.local/.test subdomains, common host-provider staging domains, bare IP addresses), with a one-click "noindex this entire site" action — plus the same override as a plain settings checkbox, independent of detection. When on, it overrides every other robots setting on every page, site-wide

**Performance**

* A large set of opt-in, off-by-default toggles (Settings > Performance): strip WordPress's own generator/RSD/wlwmanifest/shortlink/adjacent-post links, disable emojis/embeds/Dashicons, limit or disable post revisions, override the autosave interval, control the Heartbeat API, disable comments sitewide, block Google Maps scripts, trim WooCommerce assets outside shop pages, disable XML-RPC, remove jQuery Migrate, disable or trim RSS feeds, disable self-pingbacks, and restrict the REST API to logged-in users
* A hand-written Critical CSS field (inlined in `<head>`) with an independent toggle to load every other stylesheet non-blocking — this plugin never guesses at critical CSS itself, since that requires actually rendering the page
* A manual resource-hints field (preconnect/dns-prefetch/preload/prefetch/prerender) plus a "Scan homepage for suggestions" button that fetches your own front page, lists every external script/stylesheet origin found, and lets you add the ones you want — never inserted automatically
* Settings > Server: HTTPS/canonical-URL redirects and security headers (HSTS, CORS, modern MIME types) applied in pure PHP, working on any web server; gzip/Brotli/browser caching, hotlink protection and directory-browsing prevention via a reviewable, one-click-apply `.htaccess` block on Apache/LiteSpeed (only where PHP genuinely can't reach — a static asset request never runs WordPress at all)

**WPML**

* Full hreflang support via WPML's own translation data — only existing, WPML-confirmed alternates are ever emitted, self-reference always included, `x-default` configurable
* The *same* hreflang output (and the same third-party compatibility filters) in both the document head and every sitemap URL entry
* Title/description templates are translatable per language via WPML String Translation
* Designed as a companion to the separate `slim-hreflang` plugin: this plugin is the sole hreflang sender and exposes the `slimseo/hreflang/alternates` and `slimseo/hreflang/managed_externally` filters so other plugins can extend, override, or fully take over its output

**Import**

* Non-destructive, dry-run capable, paginated import from Yoast SEO, Rank Math and All in One SEO — object meta, term meta, and (Yoast and Rank Math) author archive noindex settings; AIOSEO has no per-author SEO settings to import
* Site-wide settings (title/description templates, separator, Twitter handle) are also imported from Yoast (high confidence — a stable option layout) and Rank Math (moderate confidence — flag any surprising result and adjust by hand); AIOSEO's settings format isn't imported, since it was never verified with enough confidence to map safely
* Existing redirect rules from another plugin are never migrated automatically; the import wizard flags when a source has redirect data so you can recreate it in SEO-WP's own Redirects screen
* An explicit "Deactivate {plugin} now" button appears after a real (non-dry-run) import completes — never automatic, and only shown when that plugin is actually confirmed active
* Safe to re-run at any time: a field already set in SEO-WP — whether from an earlier import or edited by hand — is never overwritten, so running the import again only fills in what's still empty
* Coexistence Mode (Settings > General): for when Yoast SEO or Rank Math needs to stay active instead of being replaced — SEO-WP stops printing its own title/meta description/canonical/Open Graph/Twitter Card/schema output, and the Bulk Editor redirects to that plugin's own fields instead

**Admin**

* A tabbed metabox (General/Social/Schema) on every public post type's edit screen, and the same General/Social/Schema fields on taxonomy term edit screens (the FAQ repeater is post-only) — the General tab also has an optional focus keyword field, used as a hint by the AI bulk-edit suggestions below, not for any scoring feature
* A single settings screen (General/Content Types/Sitemaps/Crawl) — the Content Types tab is generated from your site's actual registered post types and taxonomies
* A bulk editor for Title/Description/Canonical/Robots across every public post type
* Bring-your-own-key AI in the Bulk Editor: Claude or OpenAI generate a title/description pair in a "{keyword}: {USP} | {USP} > {CTA}" template from the page's URL, content and focus keyword — written straight into the row's fields for review, never saved until you click "Save changes"; a one-click action offers suggestions for every still-empty field on the page. DeepL translates a row's title/description into every other WPML-linked translation of that post. Every provider is entirely optional, configured with your own API key on the new AI Settings page, and never called except on an explicit click — no proxying, no markup on your usage
* A Duplicate Content Checker finding posts that share the exact same *effective* title or meta description — the value actually used on the front end, whether from a manual override or a rendered template
* A persistent Link Audit finding orphan pages, broken links and redirect chains across content, rendered templates, menus and widgets; large audits continue server-side after the browser tab is closed
* A guided import wizard with a live progress log
* A Diagnostics page recording anything that went wrong during detection or import (a database error, an unexpected data shape), with a site-wide admin notice when something needs attention
* A Role Manager controlling which WordPress roles can access which parts of the plugin (settings, metabox editing, bulk editor, import, diagnostics, redirects, 404 monitor, duplicate checker, link audit), via virtual capabilities rather than rewriting your roles — safe to enable/disable at any time, and a no-op until you actively configure it

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it through the WordPress plugins screen.
2. Activate the plugin.
3. Visit the SEO-WP menu to review defaults; no configuration is required to start.

== Frequently Asked Questions ==

= Does this replace Yoast SEO, Rank Math or All in One SEO? =

Normally, yes — use the Import screen to bring over your existing titles, descriptions, robots settings, social meta and (Yoast/Rank Math) global settings, then use the "Deactivate now" button that appears after a real import to turn off the previous plugin (it's never deactivated automatically). If you need to keep Yoast or Rank Math active anyway, Settings > General has a Coexistence Mode: SEO-WP stops printing its own title/meta description/canonical/Open Graph/Twitter Card/schema output entirely (so you never get duplicate tags), and the Bulk Editor reads and writes that plugin's own fields instead of SEO-WP's. Everything else SEO-WP does — sitemaps, hreflang, redirects, the 404 monitor, IndexNow, Link Audit, Duplicate Checker, Role Manager, Performance — keeps working either way, since none of it duplicates what Yoast/Rank Math already do.

= Are redirects imported? =

No. Existing redirect *rules* from another plugin aren't migrated automatically — the import wizard tells you when it found some so you can recreate them by hand. SEO-WP does have its own Redirects screen (exact-path source → target URL, 301/302/307/410/451) and a 404 monitor for creating new ones going forward.

= Does this work without WPML? =

Yes. Every WPML-specific feature (hreflang, per-language templates) is a no-op when WPML isn't active — nothing here is a hard dependency.


== Changelog ==

= 1.3.5 =
* New Coexistence Mode (Settings > General): when Yoast SEO or Rank Math needs to stay active alongside SEO-WP, this stops SEO-WP printing its own title/meta description/canonical/Open Graph/Twitter Card/JSON-LD output — avoiding literal duplicate tags rather than just a value disagreement — and redirects the Bulk Editor's Title/Description/Canonical columns (including AI suggestions and DeepL translations) to that plugin's own postmeta fields. Off by default; everything else SEO-WP does is unaffected.

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
* The import log now distinguishes "the source plugin had nothing here" from "the source plugin had a value, but SEO-WP already had one too, so it was left alone" — previously both looked identical ("no changes"), making it impossible to tell why a field stayed empty. Confirmed Product schema is generated live from WooCommerce's own data (not imported), and that OpenGraph/Twitter already fall back to the resolved title/description when their own fields are empty — both already worked correctly, no gap found there.
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
