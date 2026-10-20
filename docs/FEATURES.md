# Seooptimierung Toolkit — full feature list

Moved out of `readme.txt` (WordPress.org truncates the Description tab at 2500 characters). Current as of 1.7.0.

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

* Dynamic XML sitemaps with no physical files written to the webroot, cached in the object cache: an index (`index_sitemap.xml`, also reachable as `sitemap.xml`) plus one readable sitemap per content type and taxonomy (`sitemap_page.xml`, `sitemap_posts.xml`, `sitemap_category.xml`, `sitemap_tags.xml`, `sitemap_products.xml`, `sitemap_product-category.xml`, ...) and dedicated `sitemap_images.xml`, `sitemap_video.xml` and (optionally) Google News sitemaps. Every public post type and taxonomy is included automatically, WooCommerce products and product categories among them
* A dedicated Sitemaps page lists every sitemap with its own on/off switch and an editable file name, so any type (including products/product categories) can be switched off or renamed individually
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

* A dedicated WordPress Cleanup page with a large set of opt-in, off-by-default toggles, each with a note on when the feature is still needed: strip WordPress's own generator/RSD/wlwmanifest/shortlink/adjacent-post/X-Pingback/REST discovery output, disable emojis/embeds (including the oEmbed JSON endpoint)/Dashicons/block-library and global-styles CSS, drop `?ver=` from asset URLs, limit or disable post revisions, override the autosave interval, control the Heartbeat API, disable comments sitewide, block Google Maps scripts, trim WooCommerce assets outside shop pages, disable XML-RPC, application passwords and username enumeration, show one neutral login error, remove jQuery Migrate and the dashboard news widgets, disable or trim RSS feeds, disable self-pingbacks, and restrict the REST API to logged-in users
* A hand-written Critical CSS field (inlined in `<head>`) with an independent toggle to load every other stylesheet non-blocking — this plugin never guesses at critical CSS itself, since that requires actually rendering the page
* A manual resource-hints field (preconnect/dns-prefetch/preload/prefetch/prerender) plus a "Scan site for suggestions" button that loads your home page and the newest post of a few content types and the stylesheets they use, lists every external origin they pull scripts, styles, fonts, images, embeds and media from (how often, on how many pages, whether a hint already exists, plus font-file preload candidates), and lets you add the ones you want — never inserted automatically
* A dedicated Security Headers page: each of X-Content-Type-Options, Referrer-Policy, X-Frame-Options, Permissions-Policy, HSTS (max-age, includeSubDomains, preload) and Content-Security-Policy (report-only mode, editable policy; public pages only, never wp-admin or the login screen) has its own switch and options, plus a "Check headers now" button that requests your home page and shows which headers really arrive — including ones sent twice by another plugin or the server
* Settings > Server: HTTPS/canonical-URL redirects, CORS and modern MIME types applied in pure PHP, working on any web server; gzip/Brotli/browser caching, hotlink protection and directory-browsing prevention via a reviewable, one-click-apply `.htaccess` block on Apache/LiteSpeed (only where PHP genuinely can't reach — a static asset request never runs WordPress at all)

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


**Content blocks and structured data (1.7.0)**

* 42 content blocks — FAQ, How-to, Recipe, Event, Job posting, Course, Software, Video (incl. live stream), Product, Review, Book, Movie, Person, Organization, Local business (with the full business-type list), Service, Place, Podcast episode, Dataset, Q&A page, Fact check (ClaimReview), Property listing, Vehicle, Learning resource, Music album, Item list ("Top 10"), Licensable image, Forum post, Holiday rental, Quiz (flashcards), Math solver, Profile page, Estimated salary, Product with variants, Subscription content (paywall), Menu, TV series, Trip, Sports event, Certificate, Scientific article and Law: visible markup on the page plus a node in the single JSON-LD `@graph`; several per page are fine (an article with an FAQ and a recipe). FAQ questions join the one FAQPage node. `[seooptimierung_schema type="faq" q1="…" a1="…"]` as a shortcode fallback. New types are declared as data in `src/Schema/ExtraContentTypes.php` (see docs/DEVELOPERS.md).
* Breadcrumbs: block, `[seooptimierung_breadcrumbs]`, `seooptimierung_breadcrumbs()`; same trail as the BreadcrumbList schema (posts include their deepest category, post types their archive).

**Links and images (1.7.0)**

* Internal link suggestions in the block editor (scored from title, focus keyword and category words; one-click link on a phrase already in the text) (orphan pages are listed by the Link Audit).
* Automatic 301 redirect and internal-link rewrite when a published page's address (or a parent's) changes; chain-free; logged.
* Image SEO: images without alt text (inline edit, decorative flag, AI suggestion on click, optional fill on upload / output fallback) and a scan for `<img>` without alt in content.
* AI: WordPress 7.0's AI client when a provider is connected, otherwise your own Anthropic/OpenAI key.
