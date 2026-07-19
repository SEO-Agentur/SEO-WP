=== Slim SEO WPML ===
Contributors: yourwpusername
Tags: seo, wpml, sitemap, schema, hreflang
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A slim, page-type-agnostic SEO plugin with full WPML compatibility: meta tags, social meta, JSON-LD schema, XML sitemaps and crawl control.

== Description ==

Slim SEO WPML covers the essentials of on-page and technical SEO without a scoring UI, nag screens or external tracking:

* Title and meta description templates
* Robots meta directives (layered on top of WordPress core's own `wp_robots` output)
* Self-referencing canonical URLs, WPML-aware
* Open Graph and Twitter Card meta
* JSON-LD schema graph (WebSite, Organization/Person, WebPage, Article, BreadcrumbList, FAQPage, and Product when WooCommerce is active)
* Dynamic XML sitemaps (post types, taxonomies, images, news) with no physical files written to the webroot
* A virtual, editable robots.txt and llms.txt
* Non-destructive, dry-run capable import from Yoast SEO, Rank Math and All in One SEO

It is designed as a companion to the separate `slim-hreflang` plugin: this plugin is the sole hreflang sender and exposes the `slimseo/hreflang/alternates` filter so other plugins can extend or override its output.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it through the WordPress plugins screen.
2. Activate the plugin.
3. Visit Settings > SEO to review defaults; no configuration is required to start.

== Changelog ==

= 0.1.0 =
* Initial scaffold: plugin bootstrap, autoloader and module structure.
