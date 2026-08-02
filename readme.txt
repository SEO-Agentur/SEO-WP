=== SEO-WP ===
Contributors: yourwpusername
Tags: seo, wpml, sitemap, schema, hreflang
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A slim, page-type-agnostic SEO plugin with full WPML compatibility: meta tags, social meta, JSON-LD schema, XML sitemaps and crawl control.

== Description ==

SEO-WP covers the essentials of on-page and technical SEO without a scoring UI, nag screens or external tracking. It works out of the box with sensible defaults, and stays out of the way for any public post type or taxonomy your site adds — nothing here is hardcoded to "post" or "page".

**Meta**

* Title and meta description templates, with a token system compatible with Yoast, Rank Math and AIOSEO syntax
* Robots meta directives (noindex, nofollow, noarchive, nosnippet, noimageindex), layered on top of WordPress core's own `wp_robots` output rather than duplicating it
* Self-referencing canonical URLs, correctly paginated, WPML-aware

**Social & structured data**

* Open Graph and Twitter Card meta, with a shared image fallback (featured image → site logo → site icon)
* A single deduplicated JSON-LD `@graph`: WebSite, Organization or Person, WebPage, Article (for non-hierarchical post types), BreadcrumbList, FAQPage, and Product when WooCommerce is active

**Sitemaps**

* Dynamic XML sitemaps (post types, taxonomies, images, news) with no physical files written to the webroot, cached in the object cache
* Optionally disables WordPress core's own `/wp-sitemap.xml`

**Crawl control**

* A virtual, editable robots.txt with an AI-crawler opt-out toggle (GPTBot, ClaudeBot, CCBot, PerplexityBot and others) and a Sitemap line
* A virtual `/llms.txt` and optional `/llms-full.txt`
* Google/Bing/Pinterest/Yandex site verification meta tags
* Optional author/date archive disabling and attachment-page redirects
* IndexNow support (Bing, Yandex, Seznam, Naver — Google does not participate): pings on publish/update/delete, serves the required key file

**WPML**

* Full hreflang support via WPML's own translation data — only existing, WPML-confirmed alternates are ever emitted, self-reference always included, `x-default` configurable
* The *same* hreflang output (and the same third-party compatibility filters) in both the document head and every sitemap URL entry
* Title/description templates are translatable per language via WPML String Translation
* Designed as a companion to the separate `slim-hreflang` plugin: this plugin is the sole hreflang sender and exposes the `slimseo/hreflang/alternates` and `slimseo/hreflang/managed_externally` filters so other plugins can extend, override, or fully take over its output

**Import**

* Non-destructive, dry-run capable, paginated import from Yoast SEO, Rank Math and All in One SEO — object meta, term meta, and (Yoast and Rank Math) author archive noindex settings; AIOSEO has no per-author SEO settings to import
* Redirects are never migrated; the import wizard flags when a source has redirect data it won't touch

**Admin**

* A tabbed metabox (General/Social/Schema) on every public post type's edit screen, and the same General/Social fields on taxonomy term edit screens
* A single settings screen (General/Content Types/Sitemaps/Crawl) — the Content Types tab is generated from your site's actual registered post types and taxonomies
* A bulk editor for Title/Description/Canonical/Robots across every public post type
* A guided import wizard with a live progress log
* A Diagnostics page recording anything that went wrong during detection or import (a database error, an unexpected data shape), with a site-wide admin notice when something needs attention

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it through the WordPress plugins screen.
2. Activate the plugin.
3. Visit the SEO-WP menu to review defaults; no configuration is required to start.

== Frequently Asked Questions ==

= Does this replace Yoast SEO, Rank Math or All in One SEO? =

Yes — SEO-WP is meant to be used instead of them, not alongside them. Use the Import screen to bring over your existing titles, descriptions, robots settings and social meta before deactivating your previous SEO plugin.

= Are redirects imported? =

No. Redirect management is out of scope for this plugin; the import wizard tells you when redirect data was found so you can migrate it separately.

= Does this work without WPML? =

Yes. Every WPML-specific feature (hreflang, per-language templates) is a no-op when WPML isn't active — nothing here is a hard dependency.

== Changelog ==

= 1.0.0 =
* Meta: title/description templates, robots directives, self-referencing canonical URLs.
* Social: Open Graph and Twitter Card meta.
* Schema: a single deduplicated JSON-LD graph across WebSite, Organization/Person, WebPage, Article, BreadcrumbList, FAQPage and Product.
* Sitemaps: dynamic XML sitemaps for any public post type/taxonomy, with images and news.
* WPML: hreflang in the head and in sitemaps via the shared slim-hreflang compatibility contract; translatable templates.
* Crawl: robots.txt, llms.txt, site verification, archive controls, IndexNow.
* Import: non-destructive, dry-run capable import from Yoast SEO, Rank Math and All in One SEO.
* Admin: metabox, settings, bulk editor, import wizard.
