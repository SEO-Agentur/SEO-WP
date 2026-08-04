=== SEO-WP ===
Contributors: yourwpusername
Donate link: https://buymeacoffee.com/cyrusbadde
Tags: seo, wpml, sitemap, schema, hreflang
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.2.4
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
* A single deduplicated JSON-LD `@graph`: WebSite, Organization or Person, WebPage, Article (for non-hierarchical post types), BreadcrumbList, FAQPage, and Product when WooCommerce is active — exactly one primary content node per URL, never Article and Product stacked on the same page
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

**Admin**

* A tabbed metabox (General/Social/Schema) on every public post type's edit screen, and the same General/Social/Schema fields on taxonomy term edit screens (the FAQ repeater is post-only) — the General tab also has an optional focus keyword field, used as a hint by the AI bulk-edit suggestions below, not for any scoring feature
* A single settings screen (General/Content Types/Sitemaps/Crawl) — the Content Types tab is generated from your site's actual registered post types and taxonomies
* A bulk editor for Title/Description/Canonical/Robots across every public post type
* Bring-your-own-key AI in the Bulk Editor: Claude or OpenAI generate a title/description pair in a "{keyword}: {USP} | {USP} > {CTA}" template from the page's URL, content and focus keyword — written straight into the row's fields for review, never saved until you click "Save changes"; a one-click action offers suggestions for every still-empty field on the page. DeepL translates a row's title/description into every other WPML-linked translation of that post. Every provider is entirely optional, configured with your own API key on the new AI Settings page, and never called except on an explicit click — no proxying, no markup on your usage
* A Duplicate Content Checker finding posts that share the exact same *effective* title or meta description — the value actually used on the front end, whether from a manual override or a rendered template
* A Link Audit finding orphan pages (published content nothing links to, checking both post content and every navigation menu) and broken links (checked in the background via WP-Cron, a handful every fifteen minutes)
* A guided import wizard with a live progress log
* A Diagnostics page recording anything that went wrong during detection or import (a database error, an unexpected data shape), with a site-wide admin notice when something needs attention
* A Role Manager controlling which WordPress roles can access which parts of the plugin (settings, metabox editing, bulk editor, import, diagnostics, redirects, 404 monitor, duplicate checker, link audit), via virtual capabilities rather than rewriting your roles — safe to enable/disable at any time, and a no-op until you actively configure it

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it through the WordPress plugins screen.
2. Activate the plugin.
3. Visit the SEO-WP menu to review defaults; no configuration is required to start.

== Frequently Asked Questions ==

= Does this replace Yoast SEO, Rank Math or All in One SEO? =

Yes — SEO-WP is meant to be used instead of them, not alongside them. Use the Import screen to bring over your existing titles, descriptions, robots settings, social meta and (Yoast/Rank Math) global settings, then use the "Deactivate now" button that appears after a real import to turn off the previous plugin — it's never deactivated automatically.

= Are redirects imported? =

No. Existing redirect *rules* from another plugin aren't migrated automatically — the import wizard tells you when it found some so you can recreate them by hand. SEO-WP does have its own Redirects screen (exact-path source → target URL, 301/302/307/410/451) and a 404 monitor for creating new ones going forward.

= Does this work without WPML? =

Yes. Every WPML-specific feature (hreflang, per-language templates) is a no-op when WPML isn't active — nothing here is a hard dependency.

== Changelog ==

= 1.2.4 =
* Role Manager, Link Audit and Duplicate Content Checker are registered with their virtual capability again (1.2.3's switch to the real manage_options capability actively broke access for a role managed entirely through this plugin's own system, with no real manage_options at all — exactly the case Role Manager exists for). RoleManager's capability filter now grants all three to any role already trusted with Settings, in addition to real manage_options — covering both a genuine WordPress Administrator and a custom, plugin-managed role.

= 1.2.3 =
* Fixed Role Manager, Link Audit and Duplicate Content Checker being unreachable (registered with a virtual capability that itself depended on this exact feature's own data/filter — circular by construction). All three are now registered with WordPress's real manage_options capability, so they're never gated on anything that could itself be broken; the finer-grained virtual capability is still checked once inside each page.

= 1.2.2 =
* Fixed AJAX batch loops (import, duplicate scan, link audit, bulk-edit AI) silently stopping partway through when stray PHP output corrupted the JSON response — now captured and routed to the Diagnostics page instead of corrupting or being discarded.
* Fixed import aborting entirely on one unusual post/term/author instead of skipping it and continuing.
* Fixed Role Manager, Link Audit and Duplicate Content Checker becoming unreachable — even for administrators — on a site where the role matrix was saved before those areas existed in the plugin, or during the window the bug above was live. Anyone who can manage_options now always has access to these three regardless of what's stored.
* Fixed a fatal ArgumentCountError on add_option_seo_wp_settings when the settings option didn't already exist.

= 1.2.0 =
* Admin: bring-your-own-key AI title/description suggestions (Claude/OpenAI) and translation (DeepL) in the Bulk Editor; a new optional focus-keyword metabox field; a Duplicate Content Checker; a Link Audit (orphan pages + background broken-link checking).
* Import: re-running an import (or one of its stages) is now idempotent — an already-set SEO-WP field, from any source, is never overwritten again.
* Fixed a real production bug: a missing accepted_args on an add_option_* hook caused a fatal ArgumentCountError when the settings option didn't already exist.

= 1.1.0 =
* Import: site-wide settings import from Yoast/Rank Math (object/term meta import already existed); an explicit, admin-clicked "Deactivate now" button after a completed import; fixed a display bug ("#undefined") in the import log for settings entries.
* Schema: Product gained an aggregateRating node from WooCommerce's own review data.
* Meta: added the %year% token; a read-only overview table on the Content Types tab.
* Admin: a live Google search-result and social share-card preview in the metabox; a Role Manager for per-role access control across every SEO-WP admin screen.
* Crawl: Google Instant Indexing (Indexing API, service-account JWT/OAuth2) alongside IndexNow; a redirect manager (301/302/307/410/451) and a 404 monitor with a one-click "create redirect" action.
* Social/Schema: the og:image override field now also supplies the Article/Product schema image, so social and structured data never disagree.
* Crawl: llms.txt gained a free-text field for manually written content; added Baidu site verification.
* Crawl: attachment pages are now a tri-state setting (redirect/noindex/off), and inserted images can optionally have their attachment-page link stripped.
* Sitemaps: added per-post-type/per-taxonomy inclusion toggles; image sitemap entries now come from fully rendered content, so builder-generated (Divi, WPBakery, etc.) images are included, not just Gutenberg block markup.

= 1.0.0 =
* Meta: title/description templates, robots directives, self-referencing canonical URLs.
* Social: Open Graph and Twitter Card meta.
* Schema: a single deduplicated JSON-LD graph across WebSite, Organization/Person, WebPage, Article, BreadcrumbList, FAQPage and Product.
* Sitemaps: dynamic XML sitemaps for any public post type/taxonomy, with images and news.
* WPML: hreflang in the head and in sitemaps via the shared slim-hreflang compatibility contract; translatable templates.
* Crawl: robots.txt, llms.txt, site verification, archive controls, IndexNow.
* Import: non-destructive, dry-run capable import from Yoast SEO, Rank Math and All in One SEO.
* Admin: metabox, settings, bulk editor, import wizard.
