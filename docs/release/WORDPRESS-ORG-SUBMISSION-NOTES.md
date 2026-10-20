# Notes for the WordPress.org "Add your plugin" form (Additional Information) — v1.8.0

Paste the text between the lines into the "Additional Information" box on
https://wordpress.org/plugins/developers/add/ together with the upload of
`seooptimierung-toolkit-1.8.0.zip`. Reply on the original review email thread
(Review in Progress: SEO-WP, 2026-09-18) with the first two paragraphs as well.

---

Hello Plugins Team,

this upload replaces the previously reviewed "SEO-WP" 1.4.8 and addresses the points of your review email.

**Name and slug.** The plugin is now called **Seooptimierung Toolkit**, slug **`seooptimierung-toolkit`** (text domain, folder and main file match). The discouraged term "wp" is gone from the name, slug, namespace (`Seooptimierung\Toolkit`), admin menu and all strings. Please reserve this slug instead of `seo-wp`.

**Ownership.** The WordPress.org account is `seooptimierung` (hallo@seooptimierung.de). Plugin URI: https://seooptimierung.de/seotoolkit/ (live, same domain as the account email). Author URI is a different address, as required. Source code is public: https://github.com/SEO-Agentur/SEO-WP (will be renamed to match the new name).

**Technical points from the review.** Inline CSS now goes through `wp_register_style()` / `wp_add_inline_style()` (no echoed `<style>` tags). Both admin notices (staging-site notice, diagnostics notice) are capability-gated, only shown when relevant and dismissible with the dismissal stored; I believe they comply with Guideline 11.

**Plugin Check (official tool, v1.8.0 zip):** 0 errors, 0 warnings. Form and server input is sanitized where it is read, the importers pass table names as `%i` identifiers, and the few intentional notes (noindex meta query in the sitemaps/llms.txt, the optional own-key AI fallback behind the WordPress AI client) carry an inline reason. The readme Description is under 2500 characters.

**What the plugin does:** meta tags and templates, Open Graph/Twitter, one JSON-LD graph (plus 28 content blocks such as FAQ, How-to, Recipe, Event, Job, Product, Review, Local business, Person and Q&A page), XML sitemaps (index, per type, image, video, news) with configurable names, robots.txt / llms.txt, redirects with a 404 monitor and automatic redirects when a page address changes, breadcrumbs, internal link suggestions, an image alt-text screen, security headers, WordPress clean-up switches, WPML/Polylang hreflang, import from Yoast SEO / Rank Math / All in One SEO. No tracking, no ads, no upsell, no nag screens; GPL-2.0-or-later, `license.txt` included, no bundled third-party code.

**External services — all optional, off by default, only after the site owner enables them / clicks an action (documented in the readme under "External services"):**
- Google Indexing API (own service-account key; sends the page URL; restricted by default to job-posting and live-stream pages, which is what Google supports)
- IndexNow (api.indexnow.org; page URLs + the site's own key)
- WordPress AI client (WordPress 7.0+, whichever provider the site owner connected in WordPress) — used first when available
- Anthropic and OpenAI (own API key, click only; text, and for alt-text suggestions the selected image) and DeepL (own key, click only)
- The resource-hint scanner only requests the site's own pages and stylesheets, never a third party.
Nothing is sent without the site owner's explicit action and credentials.

**Data handling.** One settings option (`seo_wp_settings`, internal key names unchanged from the earlier name so existing sites keep their data), a few log options and post/term/user meta; no custom tables. API keys are stored in a separate non-autoloaded option and never echoed back. `uninstall.php` removes all options.

**Importers.** The import wizard reads the other SEO plugins' own post meta and, for detection, their tables via `$wpdb` (read-only, user-initiated, nothing transmitted).

**Translations.** German (de_DE) translation and a .pot file are included in `/languages`.

**Companion plugin.** Public filters (`seooptimierung/*`, documented on GitHub in `docs/DEVELOPERS.md`) serve a separate companion plugin (slim-hreflang) for cross-domain hreflang; it will be submitted separately.

Thank you for the review.

---

## Checklist before pressing Upload
- Zip: `/Users/cyrusbadde/projects/plugins/seooptimierung-toolkit-1.8.0.zip` (readme Stable tag 1.8.0, header Version 1.8.0).
- Readme `Contributors:` shows the real username; `Donate link` is the intended one.
- Screenshots are optional for the review (assets can be added later via SVN).
- If more schema types are added before submission, rebuild the zip, bump the version and change the version in the first paragraph.
