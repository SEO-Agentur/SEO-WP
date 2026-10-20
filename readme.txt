=== Seooptimierung Toolkit ===
Contributors: seooptimierung
Donate link: https://buymeacoffee.com/cyrusbadde
Tags: seo, wpml, polylang, sitemap, schema
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A slim SEO plugin with full WPML and Polylang support: meta tags, JSON-LD schema, XML sitemaps and crawl control.

== Description ==

Seooptimierung Toolkit covers the essentials of on-page and technical SEO without a scoring UI, nag screens or external tracking. It works out of the box with sensible defaults, and stays out of the way for any public post type or taxonomy your site adds — nothing here is hardcoded to "post" or "page".

Built and maintained by [Cyrus Badde](https://seooptimierung.de/seotoolkit/). If it saves you time, you can [buy me a coffee](https://buymeacoffee.com/cyrusbadde) — entirely optional, the plugin stays fully free either way.

**Meta & social:** title/description templates with Yoast/Rank Math/AIOSEO token syntax, robots meta, canonicals, Open Graph, Twitter Cards, live previews.

**Structured data:** one deduplicated JSON-LD graph (WebSite, Organization/Person/LocalBusiness with a type dropdown, WebPage, Article, BreadcrumbList, FAQPage, Product), plus content blocks to place FAQ, How-to, Recipe, Event, Job posting, Course, Software and Video markup inside any page.

**Links & images:** breadcrumbs (block, shortcode, template function), internal link suggestions in the editor, automatic 301 redirects and link updates when a page's address changes, and an Image SEO screen that finds images without alt text.

**Sitemaps:** an index plus one readable, renamable sitemap per content type and taxonomy (`sitemap_page.xml`, `sitemap_posts.xml`, `sitemap_products.xml` …), dedicated image, video and news sitemaps, each switchable.

**Crawl control:** robots.txt, llms.txt / llms-full.txt, redirects with a 404 monitor, IndexNow, Google Indexing API (for job and live-stream pages), staging protection.

**Performance & security:** a WordPress Cleanup page for unused core features, critical CSS, a resource-hint scanner, and a Security Headers page (HSTS, CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy, X-Content-Type-Options) with a live check.

**Multilingual:** hreflang for WPML and Polylang, x-default control, optional companion plugin for separate installations.

**Import & admin:** non-destructive import from Yoast SEO, Rank Math and All in One SEO, bulk editor with optional AI suggestions (your own key), link audit, duplicate checker, role manager, public filters for developers.

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

**WordPress AI** — on WordPress 7.0+ the plugin can use the AI provider you connected in WordPress itself (Settings → Connectors). The same click-only rule applies; which service receives the request is whatever you connected there.

**Anthropic (Claude)** — contacted only when you click "Suggest" or "Translate" in the Bulk Editor or "Suggest" on the Image SEO screen with an Anthropic API key configured under Seooptimierung Toolkit > AI Settings and Anthropic selected as the provider. Sends the edited page's URL, your focus keyword, and up to roughly 1200 characters of its rendered content to Anthropic's Messages API, to generate a title/description suggestion; for an alt-text suggestion it sends the image itself (a resized copy up to 4 MB) with its title, caption and the title of the page it is used on. Bring-your-own-key: billed directly by Anthropic, never proxied or marked up by Seooptimierung Toolkit. [Anthropic Consumer Terms](https://www.anthropic.com/legal/consumer-terms), [Anthropic Privacy Policy](https://www.anthropic.com/legal/privacy).

**OpenAI** — same trigger and the same data as the Anthropic integration above, sent to OpenAI's Chat Completions API instead, only when an OpenAI key is configured and OpenAI is the selected provider. Bring-your-own-key, same as Anthropic. [OpenAI Terms of Use](https://openai.com/policies/terms-of-use), [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy).

**DeepL** — contacted only when you click "Translate" in the Bulk Editor on a WPML-enabled site with a DeepL API key configured. Sends the row's title/description text to DeepL's Translate API, once per target language, and writes the result straight into that language's own WPML-linked post. Bring-your-own-key. [DeepL Terms & Conditions](https://www.deepl.com/en/pro-license), [DeepL Privacy Policy](https://www.deepl.com/en/privacy).

**Resource Hint Scanner** — contacted only when you click "Scan site for suggestions" under Settings > Performance. Fetches your *own* site's pages (the home page and the newest post of a few content types) and your *own* stylesheets — never a third-party URL — to detect which external origins they load scripts, styles, fonts, images and media from. The result is a list of suggested lines you may choose to add to your own settings — nothing is applied automatically and nothing is sent anywhere else.

== Changelog ==

= 1.7.1 =
* Code quality: every value read from a form or from the server environment is now sanitized where it is read (URL list, bulk editor, FAQ fields, role matrix, API-key form, CSV upload, request method, host and URI).
* Code quality: the importers pass table names to `$wpdb->prepare()` as identifiers (`%i`).
* The remaining Plugin Check notes (noindex meta query in the sitemaps and llms.txt, the optional own-key AI fallback) are marked in the code with their reason.

= 1.7.0 =
* New: **structured-data content blocks** — FAQ, How-to, Recipe, Event, Job posting, Course, Software and Video (with live-stream support). Each shows its content on the page and adds a matching node to the page's single JSON-LD graph, so an article can carry an FAQ and a recipe at the same time. FAQ questions join the page's one FAQPage node. A `[seooptimierung_schema]` shortcode covers editors without the block editor.
* New: **breadcrumbs** — a Breadcrumbs block, the `[seooptimierung_breadcrumbs]` shortcode and `seooptimierung_breadcrumbs()` for themes. The trail (including the deepest category of a post and the archive of a post type) is the same one written into the BreadcrumbList schema. Label, separator, category use and current-page link are set under Settings > General.
* New: **internal link suggestions** — a block editor panel proposes pages that fit the text and, where a matching phrase already exists, links it with one click. Pages that nothing links to are listed by the existing Link Audit.
* New: **automatic redirect and link update on address change** — changing the slug of a published page (or of a parent page) creates a 301 redirect, re-points existing redirects instead of chaining them, and rewrites internal links in content and menu links to the new address. Both are switchable under Settings > Crawl; every change is logged on the Redirects screen.
* New: **Image SEO** screen — lists images without alt text (inline editing, "use title", mark as decorative), optional AI suggestions (the image is sent only when you click), a scan for `<img>` tags without an alt attribute in your content, and two opt-in helpers: fill alt text from the file name on upload, and show the image title where an image has no alt attribute at all.
* New: **WordPress AI** — on WordPress 7.0 and later the plugin uses the AI provider connected in WordPress (no key stored here); your own Anthropic or OpenAI key remains available.
* New: German translation (de_DE) and a translation template (.pot).
* Changed: the Google Indexing API screen now submits only job posting and live-stream pages by default, which is what Google supports; the old behaviour (any page) is one checkbox away.
* Fixed: **settings saved by the Google Indexing, Instant Indexing, AI Settings and Staging screens were silently dropped** (for example the "Enable Google Instant Indexing" box came back unchecked after saving, and a custom IndexNow key or an imported setting did not stick). The settings whitelist now applies only to the form posted to options.php.

= 1.6.2 =
* Changed: the local business "Business type" is now a dropdown of the Schema.org LocalBusiness types (restaurants, shops, medical, legal, trades, hotels, automotive and more, grouped; the exact Schema.org name is shown in brackets) instead of a free-text field. A type saved earlier stays selectable.
* Fixed: pasting the whole `<meta name="…" content="…" />` tag from Google Search Console, Bing Webmaster Tools, Yandex, Pinterest or Baidu into a verification field saved an empty value; the code is now taken from the tag.

= 1.6.1 =
* Fixed: image (and video) sitemap entries could list a path that doesn't work, e.g. `/assets/photo.webp` instead of the theme's real image URL. Themes and plugins that rewrite such paths inside their `the_content` filter only do so when the page is the main query (`is_page_template()`, `is_singular()`), which is not the case while a sitemap is being built. Page content is now rendered inside a query for that very page, so the sitemap sees the same image URLs the page itself prints. The same renderer feeds the video sitemap and the link audit.

= 1.6.0 =
* New: a dedicated **Security Headers** page. X-Content-Type-Options, Referrer-Policy (policy selectable), X-Frame-Options (SAMEORIGIN/DENY), Permissions-Policy (editable), HSTS (max-age, includeSubDomains, preload with validation) and a Content-Security-Policy (report-only by default, editable policy, public pages only) each have their own switch. A "Check headers now" button shows which headers the home page really sends and flags duplicates. Sites that used the old combined "security headers" / "HSTS" checkboxes keep the same behaviour until the page is saved.
* New: sitemaps are served under readable, configurable names — `index_sitemap.xml` plus `sitemap_posts.xml`, `sitemap_page.xml`, `sitemap_category.xml`, `sitemap_tags.xml`, `sitemap_products.xml`, `sitemap_product-category.xml`, and dedicated `sitemap_images.xml` and `sitemap_video.xml` (image/video entries move out of the content sitemaps while those are on). The Sitemaps page has an on/off switch and an editable file name per sitemap. The old `sitemap.xml`, `sitemap-posts-*`, `sitemap-taxonomies-*` and `sitemap-news.xml` URLs keep working. robots.txt now advertises `index_sitemap.xml`.
* New: a dedicated **WordPress Cleanup** page (the former "unused scripts and assets" toggles from the Performance tab, grouped, each with an "only needed if" note) with nine more switches: X-Pingback header, block-library CSS, global styles/SVG filters, application passwords, username enumeration, neutral login errors, `?ver=` removal, Recent Comments style and dashboard news widgets. All saved settings carry over unchanged.
* Changed: the resource-hint scanner now loads the home page plus sample pages and their stylesheets, reports scripts, styles, fonts, images, embeds and media per origin with counts, page counts and "already hinted" markers, and suggests font preloads.
* Changed: rewrite rules are flushed automatically once after a plugin update, so new sitemap URLs work without re-saving permalinks. Sitemap caches are cleared whenever settings are saved.
* New: a "Noindex this author's archive" checkbox on every user profile (the setting existed internally and was only reachable through the Yoast/Rank Math importers), and the x-default hreflang setting can now be pinned to one specific active language, not just the translation plugin's default or off.
* Fixed: sitemap and llms.txt URLs that answer "not found" (unknown, switched off or empty) now return a real 404 instead of a redirect to a trailing-slash URL. A video that appears twice in one post (pasted URL plus iframe) is listed once. The "Check headers now" result also tells apart headers a CDN or proxy replaces.
* Removed: unused internal helpers (`Url::with_trailing_slash()`, `Url::equals()`, the previous scanner's host-count helpers).
* Changed: every entry in `/llms.txt` now carries the page's meta description (override or template, falling back to the excerpt). `/llms-full.txt` additionally includes each page's plain-text body (shortcodes are stripped, never executed; capped at 3000 characters per entry).
* Fixed: titles in `/llms.txt` showed raw HTML entities such as `&#038;`; they are now plain text.
* Fixed: the Sitemaps settings tab only mentioned core's `/wp-sitemap.xml`; it now states this plugin's sitemap index URL.

= 1.5.1 =
* Fixed: the wp-admin menu icon was painted at its full 256px source size over the sidebar (visible bottom-left on every admin screen) instead of a 20px icon. Both icon states are now drawn as a fixed 20px background.

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
