# Reply to the WordPress.org Plugins Team review (2026-09)

This replaces the earlier draft in this file, which argued for keeping the
name "SEO-WP" — since superseded by an actual rename following the review
email received 2026-09-18 (Review ID: `AUTOPREREVIEW seo-wp/seooptimierung/…`).
Reply to that same email thread with the text below (trimmed to what's
actually relevant per the reviewer's own request to keep replies short).

---

Thanks for the detailed review. I've renamed the plugin to **"Seooptimierung
Toolkit"**, slug **`seooptimierung-toolkit`** — please reserve that slug.

This also resolves the ownership question: the plugin's Plugin URI is now
`https://seooptimierung.de/seotoolkit/`, matching this account's email
domain (`hallo@seooptimierung.de`) directly, so no separate DNS/ownership
verification should be needed.

Also fixed per the technical checklist: `CriticalCss`'s inline `<style>`
output now goes through `wp_register_style()`/`wp_add_inline_style()`
instead of a raw echoed tag. I reviewed both admin notices in the plugin
(`StagingProtection`, `Diagnostics`) against Guideline 11 — both are
capability-gated, conditionally shown only when actually relevant, and
dismissible with the dismissal persisted — I believe these are compliant,
but flagging for your own check per your note.

A new version has been uploaded via the "Add your plugin" page reflecting
all of the above.

---

## Reference: other points from the original review, still relevant post-rename

**External services (6 total, all optional and user-initiated)** — already
disclosed in `readme.txt`'s "External services" section:

- **Google Indexing API** — only when enabled with the site owner's own
  Google Cloud service-account key.
- **IndexNow** — only when enabled.
- **Anthropic / OpenAI APIs** — bring-your-own-key, click-only, Bulk Editor.
- **DeepL API** — bring-your-own-key, click-only, translates a title/
  description pair into other WPML/Polylang-linked posts.
- **Resource Hint Scanner** — fetches the site's *own* homepage
  (`wp_remote_get( home_url() )`), not a third party, listed for
  completeness.

None fire automatically without the site owner enabling the feature and,
where applicable, supplying their own credentials.

**Reading competing SEO plugins' own database tables** — the Import Wizard
(Yoast SEO / Rank Math / All in One SEO importers) reads those plugins' own
postmeta and, for redirects, their own custom tables directly via `$wpdb`.
Read-only, user-initiated, only touches tables already on the same site,
nothing transmitted anywhere.

**License and dependencies** — GPL-2.0-or-later, `license.txt` bundled,
zero runtime PHP dependencies.

**Companion plugin filter contract (slim-hreflang)** — Seooptimierung
Toolkit exposes public filters (`seooptimierung/title`,
`seooptimierung/meta_description`, `seooptimierung/canonical`,
`seooptimierung/opengraph/tags`, `seooptimierung/schema/graph`,
`seooptimierung/sitemap/excluded_post_ids`) plus a two-filter hreflang
contract, used by a separate companion plugin (slim-hreflang) for
cross-domain hreflang sync between separate WordPress installs. Documented
in `docs/DEVELOPERS.md` (not shipped in the distributable zip). Renamed
from `seo_wp/*` in lockstep with this rename — slim-hreflang needs a
matching update.

**AI Client note** — WordPress 7.0's core `wp_ai_client_prompt()` remains
under evaluation as a possible alternative to the direct Anthropic/OpenAI
integration in `src/Support/AiClient.php`. Not changed in this pass.
