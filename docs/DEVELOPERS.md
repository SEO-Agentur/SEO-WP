# SEO-WP — Developer Integration Guide

This document is for developers who want to customize SEO-WP's output from
their own theme or plugin, or integrate another plugin with it. It is not
shipped in the distributable zip (see `.distignore`) — it lives in the
repository for anyone working with the source.

Everything here is either a public WordPress filter or a public PHP method.
None of it requires editing SEO-WP's own files, so none of it is lost on
update.

## Filters

All five filters below were added in **1.4.1**. Every one of them wraps a
value SEO-WP had already fully computed — a filter with nothing attached to
it changes nothing and costs essentially nothing (a function call and an
empty-array check).

### `seo_wp/title`

Fires in `SeoWp\Meta\TitleDescription::filter_title()`, right before the
resolved title is handed back to WordPress core's `pre_get_document_title`.

```php
apply_filters( 'seo_wp/title', string $title, mixed $queried_object ): string
```

- `$title` — the title SEO-WP would otherwise output (per-object override or
  rendered template, whichever applied).
- `$queried_object` — the result of `get_queried_object()` for the current
  request (`WP_Post`, `WP_Term`, `WP_User`, `WP_Post_Type`, or `null`).

```php
add_filter( 'seo_wp/title', function ( string $title, $post ) {
    if ( $post instanceof WP_Post && 'product' === $post->post_type ) {
        return $title . ' – In Stock';
    }
    return $title;
}, 10, 2 );
```

### `seo_wp/meta_description`

Fires in `SeoWp\Meta\TitleDescription::output_meta_description()`, right
before the `<meta name="description">` tag is printed. Returning an empty
string suppresses the tag entirely.

```php
apply_filters( 'seo_wp/meta_description', string $description, mixed $queried_object ): string
```

Same two arguments as `seo_wp/title`.

### `seo_wp/canonical`

Fires in **both** `SeoWp\Meta\Canonical::filter_singular_canonical()` (singular
content) and `Canonical::output_non_singular_canonical()` (archives, terms,
the home/posts page) — one filter, applied identically in both paths, so a
listener only has to be registered once to affect every request type.

```php
apply_filters( 'seo_wp/canonical', string $canonical_url, mixed $queried_object ): string
```

Returning `''` from the non-singular path suppresses the tag; the singular
path always has a value (WordPress core's own computed canonical, at worst).

### `seo_wp/opengraph/tags`

Fires in `SeoWp\Social\OpenGraph::output()`, on the full `property => content`
array just before it's printed. Add, remove, or change any tag — including
ones SEO-WP doesn't set at all (e.g. `og:video`).

```php
apply_filters( 'seo_wp/opengraph/tags', array $tags, mixed $queried_object ): array
```

```php
add_filter( 'seo_wp/opengraph/tags', function ( array $tags, $queried ) {
    if ( $queried instanceof WP_Post && has_video( $queried ) ) {
        $tags['og:video'] = get_video_url( $queried );
    }
    return $tags;
}, 10, 2 );
```

### `seo_wp/schema/graph`

Fires in `SeoWp\Schema\GraphBuilder::render()`, on the collected list of
JSON-LD nodes **before** `@id` deduplication. Add a node here (e.g. a
`Review` node from a reviews plugin); giving it the same `@id` as one of
SEO-WP's own built-in nodes and placing it earlier in the array makes it win
the dedup step, effectively replacing that node.

```php
apply_filters( 'seo_wp/schema/graph', array $nodes, mixed $queried_object ): array
```

Each `$node` is a plain associative array matching JSON-LD shape (`@type`,
`@id`, and whatever properties apply) — see `SeoWp\Schema\Nodes\*` for the
shapes SEO-WP's own builders produce.

## The slim-hreflang contract (a worked example of the same pattern)

`SeoWp\Wpml\Hreflang` exposes two of its own filters,
`seo_wp/hreflang/alternates` and `seo_wp/hreflang/managed_externally`, used
by the companion [slim-hreflang](https://seo-wp.net) plugin to feed
cross-domain hreflang data into SEO-WP's output. It's the same mechanism as
the filters above, just plugin-to-plugin instead of theme-to-plugin — worth
reading `SeoWp\Wpml\Hreflang::apply_contract()` as a second example if you're
building an integration of comparable depth (e.g. a plugin that wants to
fully replace one piece of SEO-WP's output for URLs it manages itself).

```php
apply_filters( 'seo_wp/hreflang/managed_externally', bool $managed, array $context ): bool
apply_filters( 'seo_wp/hreflang/alternates', array $alternates, array $context ): array
```

`$context` is `array{url: string, object_id: int|null, lang: string}`.
Returning `true` from `managed_externally` discards SEO-WP's own computed
alternates entirely before `alternates` runs — full takeover, not just an
addition.

## Constants (meta keys and option key)

Reading or writing these directly is a valid integration path — e.g. for a
migration script, or a custom admin UI that needs to set an SEO-WP override
without going through wp-admin.

| Constant | Value | Purpose |
|---|---|---|
| `SeoWp\Meta\TitleDescription::META_TITLE` | `_seo_wp_title` | Per-post/term title override |
| `SeoWp\Meta\TitleDescription::META_DESCRIPTION` | `_seo_wp_description` | Per-post/term description override |
| `SeoWp\Meta\Canonical::META_CANONICAL` | `_seo_wp_canonical` | Per-post/term canonical override |
| `SeoWp\Social\OpenGraph::META_TITLE` | `_seo_wp_og_title` | Per-post/term `og:title` override |
| `SeoWp\Social\OpenGraph::META_DESCRIPTION` | `_seo_wp_og_description` | Per-post/term `og:description` override |
| `SeoWp\Social\OpenGraph::META_IMAGE` | `_seo_wp_og_image` | Per-post/term `og:image` override (a plain URL) |
| `SeoWp\Schema\GraphBuilder::META_SCHEMA_TYPE` | `_seo_wp_schema_type` | Per-post/term Schema.org `@type` override (e.g. `Article`, `LocalBusiness`) |

All settings (templates, separator, toggles, Coexistence Mode, …) live in a
single option, `seo_wp_settings` (a flat associative array) — read via
`get_option( 'seo_wp_settings', array() )`. There is no dedicated "settings
API" beyond that; treat the array shape used by `SeoWp\Admin\Settings` as the
schema.

## Useful public methods

These aren't filters — call them directly when your own code needs to ask
SEO-WP something, rather than override what it outputs.

- **`SeoWp\Support\CoexistenceMode::active(): bool`** — true when SEO-WP has
  been configured to leave title/meta/schema output to Yoast SEO or Rank
  Math instead. Check this before assuming SEO-WP is the one producing a
  page's meta output.
- **`SeoWp\Support\CoexistenceMode::source(): string`** — `'yoast'`,
  `'rank_math'`, or `''`.
- **`SeoWp\Support\CoexistenceMode::meta_key( string $field ): ?string`** —
  resolves `'title'|'description'|'canonical'` to the *other* plugin's own
  postmeta key when Coexistence Mode is active, or `null` when it isn't
  (fall back to SEO-WP's own constants above in that case).
- **`SeoWp\Meta\TitleDescription::resolve_title_for_post( WP_Post $post ): string`**
  and **`resolve_description_for_post( WP_Post $post ): string`** — resolve
  the *effective* title/description for an arbitrary post (override or
  rendered template), independent of the current request's global query.
  Useful for a report or export that needs every post's real, on-page value
  at once, not just whichever one is being viewed.
- **`SeoWp\Schema\GraphBuilder::is_valid_schema_type( string $type ): bool`**
  — validates a candidate Schema.org `@type` name (PascalCase, letters
  only) before writing it to `META_SCHEMA_TYPE`; an unrecognised value
  written there is silently ignored rather than emitted as invalid markup.
- **`SeoWp\Crawl\Redirects::match( string $path ): ?array`** and
  **`resolve_target( array $rule, string $path ): string`** — look up
  whether a path matches a configured redirect rule (exact or regex) and
  resolve its final target, including `$1`/`$2` backreference substitution
  for regex rules.
- **`SeoWp\Support\StagingDetector::looks_like_staging_host( string $host ): bool`**
  — the same staging/development hostname heuristic SEO-WP's own staging
  safeguard uses, in case another part of your stack needs the same check.

## Compatibility

Everything on this page is part of SEO-WP's stable public surface as of
**1.4.1** — a filter or method listed here won't be renamed or removed
without a corresponding changelog entry and, for a breaking rename, a major
version bump (see the `seo_wp/hreflang/*` rename in 1.4.1/slim-hreflang 1.2.2
for what that looks like: both sides of a contract updated together, old
name never left dangling).
