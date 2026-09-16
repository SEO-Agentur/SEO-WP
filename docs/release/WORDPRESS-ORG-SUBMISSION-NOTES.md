# Additional information for the WordPress.org Plugin Review Team

Paste the text below into the "Additional information for the plugin review team"
field on https://wordpress.org/plugins/developers/add/ when submitting SEO-WP.

---

## Plugin name and slug ("SEO-WP" / "seo-wp")

The Plugin Check tool flags "SEO-WP" for containing the restricted term "wp".
I'm aware of this and want to flag it proactively rather than have it come up
as a surprise in review. The plugin's name and branding (seo-wp.net) were
chosen deliberately and match a well-established, currently-listed precedent:
WPForms, WP Rocket, WP Fastest Cache, WP Super Cache and many other actively
listed plugins all use "WP" in their name today. My understanding of the
actual trademark policy is that it restricts the word "WordPress" itself (and
close misspellings), not "wp" as a substring — I'm submitting as-is rather
than pre-emptively renaming, but I'm glad to discuss this with the team if
it's actually a blocker.

## External services (6 total, all optional and user-initiated)

All six are already disclosed in readme.txt's "External services" section per
the guidelines. Summary:

- **Google Indexing API** (`indexing.googleapis.com`, `oauth2.googleapis.com`)
  — only when a site owner enables it and supplies their own Google Cloud
  service-account key. Pings on publish/update/delete of configured post types.
- **IndexNow** (Bing/Yandex/Seznam/Naver's shared endpoint) — only when
  enabled; submits a URL on publish/update or via a manual bulk action.
- **Anthropic API** (`api.anthropic.com`) — only when a site owner supplies
  their own Anthropic API key and clicks "Suggest" in the Bulk Editor.
- **OpenAI API** (`api.openai.com`) — same, bring-your-own-key, click-only.
- **DeepL API** — same, translates a title/description pair into other
  WPML/Polylang-linked posts, bring-your-own-key, click-only.
- **Resource Hint Scanner** — fetches the site's *own* homepage
  (`wp_remote_get( home_url() )`) to suggest dns-prefetch/preconnect hints;
  not a third-party service, listed for completeness since it's still an
  outbound HTTP request.

None of the above fire automatically without the site owner first enabling
the feature and, for the AI/IndexNow/Google Indexing integrations, supplying
their own credentials. No proxying, no bundled/shared API keys, no telemetry
back to me.

## Reading competing SEO plugins' own database tables

The Import Wizard (Yoast SEO / Rank Math / All in One SEO importers) reads
those plugins' own postmeta and, for redirects, their own custom tables
(e.g. `{$prefix}aioseo_redirects`) directly via `$wpdb`. This is read-only,
only runs when a site owner explicitly starts an import, only touches tables
belonging to a plugin already installed on the same site, and nothing is
transmitted anywhere — it's local WordPress data migrated into this plugin's
own meta keys.

## License and dependencies

GPL-2.0-or-later, `license.txt` bundled. Zero runtime PHP dependencies
(`composer.json`'s `require` is PHP-version-only; everything under
`require-dev` is dev tooling excluded from the shipped package via
`.distignore`).

## Companion plugin filter contract (slim-hreflang)

SEO-WP exposes five public filters (`seo_wp/title`, `seo_wp/meta_description`,
`seo_wp/canonical`, `seo_wp/opengraph/tags`, `seo_wp/schema/graph`) plus a
two-filter hreflang contract (`seo_wp/hreflang/alternates`,
`seo_wp/hreflang/managed_externally`) used by a separate companion plugin,
slim-hreflang, for cross-domain hreflang sync between separate WordPress
installs. All are documented in `docs/DEVELOPERS.md` (not shipped in the
distributable zip). Slash-namespaced hook names (`seo_wp/x` rather than
`seo_wp_x`) are a deliberate, precedented choice — Rank Math ships hooks the
same way (e.g. `rank_math/frontend/title`) — so Plugin Check's
`PrefixAllGlobals.NonPrefixedHooknameFound` warning on these is expected and,
I believe, not something to change.

## AI Client note

Plugin Check suggests WordPress 7.0's core `wp_ai_client_prompt()` as an
alternative to the direct Anthropic/OpenAI integration in
`src/Support/AiClient.php`. This is a real, newer core API I'm still
evaluating — happy to discuss migrating to it if the team has guidance on
whether it currently supports pluggable third-party providers the way this
plugin's BYOK model needs.
