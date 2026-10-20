# Seooptimierung Toolkit — Developer Integration Guide

This document is for developers who want to customize Seooptimierung Toolkit's output from
their own theme or plugin, or integrate another plugin with it. It is not
shipped in the distributable zip (see `.distignore`) — it lives in the
repository for anyone working with the source.

Everything here is either a public WordPress filter or a public PHP method.
None of it requires editing Seooptimierung Toolkit's own files, so none of it is lost on
update.

## Filters

**Breaking change in 1.5.0:** every filter below was renamed from `seo_wp/*`
to `seooptimierung/*` when the plugin itself was renamed from "SEO-WP" to
"Seooptimierung Toolkit". If you're integrating with an older install,
update your `add_filter()` calls to the new names — the old `seo_wp/*` names
no longer fire.

The first five filters below were added in **1.4.1**;
`seooptimierung/sitemap/excluded_post_ids` was added in **1.4.6**. Every one of them
wraps a value Seooptimierung Toolkit had already fully computed — a filter with nothing
attached to it changes nothing and costs essentially nothing (a function
call and an empty-array check).

### `seooptimierung/title`

Fires in `Seooptimierung\Toolkit\Meta\TitleDescription::filter_title()`, right before the
resolved title is handed back to WordPress core's `pre_get_document_title`.

```php
apply_filters( 'seooptimierung/title', string $title, mixed $queried_object ): string
```

- `$title` — the title Seooptimierung Toolkit would otherwise output (per-object override or
  rendered template, whichever applied).
- `$queried_object` — the result of `get_queried_object()` for the current
  request (`WP_Post`, `WP_Term`, `WP_User`, `WP_Post_Type`, or `null`).

```php
add_filter( 'seooptimierung/title', function ( string $title, $post ) {
    if ( $post instanceof WP_Post && 'product' === $post->post_type ) {
        return $title . ' – In Stock';
    }
    return $title;
}, 10, 2 );
```

### `seooptimierung/meta_description`

Fires in `Seooptimierung\Toolkit\Meta\TitleDescription::output_meta_description()`, right
before the `<meta name="description">` tag is printed. Returning an empty
string suppresses the tag entirely.

```php
apply_filters( 'seooptimierung/meta_description', string $description, mixed $queried_object ): string
```

Same two arguments as `seooptimierung/title`.

### `seooptimierung/canonical`

Fires in **both** `Seooptimierung\Toolkit\Meta\Canonical::filter_singular_canonical()` (singular
content) and `Canonical::output_non_singular_canonical()` (archives, terms,
the home/posts page) — one filter, applied identically in both paths, so a
listener only has to be registered once to affect every request type.

```php
apply_filters( 'seooptimierung/canonical', string $canonical_url, mixed $queried_object ): string
```

Returning `''` from the non-singular path suppresses the tag; the singular
path always has a value (WordPress core's own computed canonical, at worst).

### `seooptimierung/opengraph/tags`

Fires in `Seooptimierung\Toolkit\Social\OpenGraph::output()`, on the full `property => content`
array just before it's printed. Add, remove, or change any tag — including
ones Seooptimierung Toolkit doesn't set at all (e.g. `og:video`).

```php
apply_filters( 'seooptimierung/opengraph/tags', array $tags, mixed $queried_object ): array
```

```php
add_filter( 'seooptimierung/opengraph/tags', function ( array $tags, $queried ) {
    if ( $queried instanceof WP_Post && has_video( $queried ) ) {
        $tags['og:video'] = get_video_url( $queried );
    }
    return $tags;
}, 10, 2 );
```

### `seooptimierung/schema/graph`

Fires in `Seooptimierung\Toolkit\Schema\GraphBuilder::render()`, on the collected list of
JSON-LD nodes **before** `@id` deduplication. Add a node here (e.g. a
`Review` node from a reviews plugin); giving it the same `@id` as one of
Seooptimierung Toolkit's own built-in nodes and placing it earlier in the array makes it win
the dedup step, effectively replacing that node.

```php
apply_filters( 'seooptimierung/schema/graph', array $nodes, mixed $queried_object ): array
```

Each `$node` is a plain associative array matching JSON-LD shape (`@type`,
`@id`, and whatever properties apply) — see `Seooptimierung\Toolkit\Schema\Nodes\*` for the
shapes Seooptimierung Toolkit's own builders produce.

### `seooptimierung/sitemap/excluded_post_ids`

Fires in `Seooptimierung\Toolkit\Sitemaps\PostTypeSitemap::query_args()`, on the list of post
IDs to leave out of every Seooptimierung Toolkit post-type sitemap, regardless of their own
noindex meta. Intended for a companion plugin that noindexes some of its own
pages by another means (a cart, checkout or account page kept out of search
engines purely via the `wp_robots` filter, never through Seooptimierung Toolkit's own
per-post meta) — listing those IDs here keeps them out of the sitemap too,
matching Google's own guidance that a noindexed URL shouldn't appear in a
sitemap at all.

```php
apply_filters( 'seooptimierung/sitemap/excluded_post_ids', int[] $post_ids ): int[]
```

## Hreflang: WPML, Polylang and the slim-hreflang contract (a worked example of the same pattern)

`Seooptimierung\Toolkit\Wpml\Hreflang` computes alternate-language URLs from WPML when it's
active, or natively from Polylang when it's active and WPML isn't (the two
are mutually exclusive in practice). With neither installed, it also exposes
two of its own filters, `seooptimierung/hreflang/alternates` and
`seooptimierung/hreflang/managed_externally`, used by the companion
[slim-hreflang](https://seooptimierung.de/seotoolkit/) plugin to feed cross-domain hreflang data
into Seooptimierung Toolkit's output. It's the same mechanism as the filters above, just
plugin-to-plugin instead of theme-to-plugin — worth reading
`Seooptimierung\Toolkit\Wpml\Hreflang::apply_contract()` as a second example if you're
building an integration of comparable depth (e.g. a plugin that wants to
fully replace one piece of Seooptimierung Toolkit's output for URLs it manages itself). With
none of WPML, Polylang or a listener on these filters present, Seooptimierung Toolkit emits
no hreflang output at all — including no `x-default` — since there is no
localization plugin to make that entry meaningful.

```php
apply_filters( 'seooptimierung/hreflang/managed_externally', bool $managed, array $context ): bool
apply_filters( 'seooptimierung/hreflang/alternates', array $alternates, array $context ): array
```

`$context` is `array{url: string, object_id: int|null, lang: string}`.
Returning `true` from `managed_externally` discards Seooptimierung Toolkit's own computed
alternates entirely before `alternates` runs — full takeover, not just an
addition.

## Constants (meta keys and option key)

Reading or writing these directly is a valid integration path — e.g. for a
migration script, or a custom admin UI that needs to set an Seooptimierung Toolkit override
without going through wp-admin.

| Constant | Value | Purpose |
|---|---|---|
| `Seooptimierung\Toolkit\Meta\TitleDescription::META_TITLE` | `_seo_wp_title` | Per-post/term title override |
| `Seooptimierung\Toolkit\Meta\TitleDescription::META_DESCRIPTION` | `_seo_wp_description` | Per-post/term description override |
| `Seooptimierung\Toolkit\Meta\Canonical::META_CANONICAL` | `_seo_wp_canonical` | Per-post/term canonical override |
| `Seooptimierung\Toolkit\Social\OpenGraph::META_TITLE` | `_seo_wp_og_title` | Per-post/term `og:title` override |
| `Seooptimierung\Toolkit\Social\OpenGraph::META_DESCRIPTION` | `_seo_wp_og_description` | Per-post/term `og:description` override |
| `Seooptimierung\Toolkit\Social\OpenGraph::META_IMAGE` | `_seo_wp_og_image` | Per-post/term `og:image` override (a plain URL) |
| `Seooptimierung\Toolkit\Schema\GraphBuilder::META_SCHEMA_TYPE` | `_seo_wp_schema_type` | Per-post/term Schema.org `@type` override (e.g. `Article`, `LocalBusiness`) |

All settings (templates, separator, toggles, Coexistence Mode, …) live in a
single option, `seo_wp_settings` (a flat associative array) — read via
`get_option( 'seo_wp_settings', array() )`. There is no dedicated "settings
API" beyond that; treat the array shape used by `Seooptimierung\Toolkit\Admin\Settings` as the
schema.

## Useful public methods

These aren't filters — call them directly when your own code needs to ask
Seooptimierung Toolkit something, rather than override what it outputs.

- **`Seooptimierung\Toolkit\Support\CoexistenceMode::active(): bool`** — true when Seooptimierung Toolkit has
  been configured to leave title/meta/schema output to Yoast SEO or Rank
  Math instead. Check this before assuming Seooptimierung Toolkit is the one producing a
  page's meta output.
- **`Seooptimierung\Toolkit\Support\CoexistenceMode::source(): string`** — `'yoast'`,
  `'rank_math'`, or `''`.
- **`Seooptimierung\Toolkit\Support\CoexistenceMode::meta_key( string $field ): ?string`** —
  resolves `'title'|'description'|'canonical'` to the *other* plugin's own
  postmeta key when Coexistence Mode is active, or `null` when it isn't
  (fall back to Seooptimierung Toolkit's own constants above in that case).
- **`Seooptimierung\Toolkit\Meta\TitleDescription::resolve_title_for_post( WP_Post $post ): string`**
  and **`resolve_description_for_post( WP_Post $post ): string`** — resolve
  the *effective* title/description for an arbitrary post (override or
  rendered template), independent of the current request's global query.
  Useful for a report or export that needs every post's real, on-page value
  at once, not just whichever one is being viewed.
- **`Seooptimierung\Toolkit\Schema\GraphBuilder::is_valid_schema_type( string $type ): bool`**
  — validates a candidate Schema.org `@type` name (PascalCase, letters
  only) before writing it to `META_SCHEMA_TYPE`; an unrecognised value
  written there is silently ignored rather than emitted as invalid markup.
- **`Seooptimierung\Toolkit\Crawl\Redirects::match( string $path ): ?array`** and
  **`resolve_target( array $rule, string $path ): string`** — look up
  whether a path matches a configured redirect rule (exact or regex) and
  resolve its final target, including `$1`/`$2` backreference substitution
  for regex rules.
- **`Seooptimierung\Toolkit\Support\StagingDetector::looks_like_staging_host( string $host ): bool`**
  — the same staging/development hostname heuristic Seooptimierung Toolkit's own staging
  safeguard uses, in case another part of your stack needs the same check.

## Compatibility

Everything on this page is part of Seooptimierung Toolkit's stable public surface as of
**1.4.1** — a filter or method listed here won't be renamed or removed
without a corresponding changelog entry and, for a breaking rename, a major
version bump (see the `seooptimierung/hreflang/*` rename in 1.4.1/slim-hreflang 1.2.2
for what that looks like: both sides of a contract updated together, old
name never left dangling).


## Filters added in 1.7.0

| Filter | Arguments | Purpose |
|---|---|---|
| `seooptimierung/breadcrumbs/items` | `array $items` (`name`, `url`; home first) | Change the trail for both the visible breadcrumbs and the BreadcrumbList schema. |
| `seooptimierung/breadcrumbs/html` | `string $html, array $items` | Change the visible breadcrumb markup. |
| `seooptimierung/schema/block_html` | `string $html, string $slug, array $attrs` | Change the visible markup of a content block (any content-block slug, e.g. `faq`, `recipe`, `product`, `review`, `qa-page`; the full list is the keys of `ContentTypes::all()`). |
| `seooptimierung/indexing/google_eligible` | `bool $eligible, WP_Post $post` | Mark a page as a job/live-stream page for the Google Indexing API when the markup comes from somewhere else. |

Template function: `seooptimierung_breadcrumbs( array $args = [], bool $display = true ): string` — args: `separator`, `home_label`, `link_current`, `class`.

## Adding a content block type (1.8.0)

Content blocks are declared in `src/Schema/ExtraContentTypes.php`: one entry per type with its editor `fields` and a `build` map of Schema.org property => rule. A generic builder produces the JSON-LD node and the visible markup from the same values; the block, the shortcode and the editor form come from the registry automatically.

```php
'dataset' => array(
	'type'   => 'Dataset',
	'title'  => __( 'Dataset (structured data)', 'seooptimierung-toolkit' ),
	'fields' => array(
		self::f( 'name', __( 'Name', 'seooptimierung-toolkit' ), 'text' ),
		self::f( 'license', __( 'Licence link', 'seooptimierung-toolkit' ), 'url' ),
	),
	'build'  => array(
		'@type'   => array( 'const', 'Dataset' ),
		'name'    => array( 'text', 'name', 'title' ),   // falls back to the page title
		'license' => array( 'url', 'license' ),
	),
),
```

Rules: `text`, `url`, `image`, `date`, `datetime`, `minutes`, `seconds`, `number`, `posint`, `list`, `enum`, `boolenum`, `bool`, `upper`, `currency`, `type`, `countif`, `const`, `ctx`, `listitems`, `itemcount`, `entities`, `constif`, `urltemplate`, `imagelist`, `features`, `flashcards`, `enumlist`, `variants`, `menusections` and `entity` (a nested object that is left out when empty or when a required property is missing) — see the class comment. A type with `'page' => true` is itself a web page (QAPage, RealEstateListing, ProfilePage) and gets no `mainEntityOfPage`. A type with `'patch' => true` (the paywall block) is not a node of its own: its properties are merged into the page's Article node (or WebPage node) by `GraphBuilder::apply_patches()`. Add a test in `tests/Unit/ExtraContentTypesTest.php`; the registry tests check that every rule reads an existing field.
