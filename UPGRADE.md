# Upgrade

## 2.4 → 3.0

**The package requires `flyo/nitro-php` 3.0**, `composer update flyo/nitro-laravel` pulls it.
No code changes are required in a project which only uses the package, the sdk upgrade is
breaking for applications reading presentation data off sitemap items themselves, see below.

### What's new

1. **A draft link is never cached.** A draft link is a shareable, expiring snapshot of an entity
   which is still offline in Flyo, requested through the regular entity endpoints
   (`entityBySlug()`, `entityByUniqueid()`) with a token in place of the slug or the unique id. The
   api marks such a response with `is_draft`, the package turns that into a response nothing stores:

   ```
   Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private
   CDN-Cache-Control: no-store
   Vercel-CDN-Cache-Control: no-store
   Surrogate-Control: no-store
   X-Robots-Tag: noindex, nofollow
   ```

   `ETag` and `Last-Modified` are removed as well and the `server_cache_ttl` / `client_cache_ttl`
   config is ignored for the response. The snapshot is rewritten with every save of the editor and
   the link answers with a 404 once it expired, so a cached copy would keep serving content which is
   outdated or gone. The api does deliver `draft_expires_at`, but it is deliberately not used as a
   cache ttl: not caching at all is the safer contract.

   The headers are written by the new `Flyo\Laravel\Middleware\PreventDraftCaching`, registered by
   the service provider as the outermost **global** middleware, so a draft is covered on every route,
   also on one which does not use the `CachingHeaders` middleware. It does nothing until an entity
   was resolved as a draft.

2. **The draft state is readable.** `EntityController` hands `isDraft` (bool) and `draftExpiresAt`
   (unix timestamp or null) to the view, so a template can render a hint that this is not the live
   page. The same state is available anywhere through the new `Flyo\Laravel\DraftMode`:

   ```php
   Flyo\Laravel\DraftMode::isDraft();
   Flyo\Laravel\DraftMode::expiresAt();
   ```

3. **A route serving draft links needs two things.** The draft token does not look like a slug or a
   unique id, so a parameter pattern (`->where(...)`) has to let it through, and the entity type id
   does not apply to a token, so resolve without it:

   ```php
   Route::get('/tier/{slug}', function ($slug) {
       return app(EntityController::class)
           ->resolve(fn (EntitiesApi $api, $param) => $api->entityBySlug($param)) // no type id
           ->render($slug, 'tier');
   });
   ```

### Breaking changes

- **The sitemap endpoint returns its own model.** `Flyo\Api\SitemapApi::sitemap()` returns
  `Flyo\Model\SitemapinterfaceInner[]` instead of `Flyo\Model\EntityinterfaceInner[]`. The
  response was reduced to what a sitemap needs, a sitemap item only carries `entity_unique_id`,
  `updated_at` and `href` plus the deprecated `entity_type`, `entity_slug` and `routes`. The
  getters `getEntityTitle()`, `getEntityTeaser()`, `getEntityImage()`, `getEntityTimeStart()` and
  `getEntityTypeId()` are gone from sitemap items, they are still delivered by `SearchApi::search()`
  and the entities endpoints. Only an application calling the sitemap endpoint itself is affected,
  the same is true for a type hint against `Flyo\Model\EntityinterfaceInner`, which becomes
  `Flyo\Model\SitemapinterfaceInner`. `SitemapController` of the package is upgraded, it reads
  `href` and `updated_at` only.

### Behavior notes

- **A custom controller resolving an entity is covered as well**, as long as it assigns the meta
  data of the entity through `Flyo\Laravel\Components\Head::metaEntity($entity)` — that call
  detects a draft. A controller not using the head component flags it explicitly with
  `Flyo\Laravel\DraftMode::detect($entity)`, otherwise a draft response of that route can end up in
  a cache.
- **`EntityController` renders two more view variables**, `isDraft` and `draftExpiresAt`. A template
  defining variables of the same name through `@php` or a view composer wins over them as before.
- **Nothing changes for a regular request.** `is_draft` is `false` for every response which is not a
  draft link, the cache headers of such a response are the ones `flyo.server_cache_ttl` and
  `flyo.client_cache_ttl` configure.
- **The debug response headers gained `Flyo-Draft`**, sending `'1'`/`'0'` next to `Flyo-Live-Edit`
  when `APP_DEBUG` is on.
- **`updated_at` of a sitemap item is unchanged in meaning**, it only moves when the delivered
  content of the page or entity actually changed, a rebuild producing identical output does not bump
  it. Entries without a resolvable url are omitted by the api now, the controller skipped them
  before already.

## 2.1 → 2.2

**No breaking changes.** `composer update flyo/nitro-laravel` is enough, no code changes are required in a project. It pulls `flyo/nitro-php` 2.2, which is the sdk release exposing the sitemap fields used below.

### What's new

1. **Blocks can be marked as editable in raw php templates.** In blade the `@editable($block)` directive stays the way to do it, everywhere else (plain php views, a controller, markup built as a string) use `Flyo\Laravel\Editable`:

   ```blade
   {{-- blade --}}
   <div @editable($block)>...</div>
   ```

   ```php
   // raw php
   <section <?= Flyo\Laravel\Editable::attr($block); ?>>...</section>
   ```

   Both render the same `data-flyo-uid` marker, escaped, and nothing at all when live edit is disabled. `Editable::uid($block)` returns the raw uid and `Editable::isEnabled()` the live edit state. The javascript which makes the marker interactive is loaded by the `<x-flyo::head />` component, so the layout has to include it.

2. **`@editable` can be used in templates compiled from the console.** `php artisan view:cache` failed on templates using the directive before.

3. **The bridge url is configurable.** New optional config key, use it to self host the nitro js bridge or to pin an exact version:

   ```php
   'live_edit_bridge_url' => env('FLYO_LIVE_EDIT_BRIDGE_URL', 'https://unpkg.com/@flyo/nitro-js-bridge@1.5.0/dist/nitro-js-bridge.umd.cjs'),
   ```

   An already published `config/flyo.php` does **not** have to be updated, the CDN url pinned to the major version is used when the key is absent.

4. **The sitemap sends a `lastmod` for every entry.** The nitro api delivers an `updated_at` unix timestamp per sitemap item, it is rendered as W3C datetime:

   ```xml
   <url><loc>https://example.com/news/a-news</loc><lastmod>2025-08-12T14:40:00+00:00</lastmod></url>
   ```

   The timestamp reflects the last time the delivered content of that page or entity actually changed, a rebuild producing identical output does not move it. Items the api sends without a timestamp are written without `lastmod`.

5. **The sitemap links the `href` delivered by the api.** Every sitemap item carries the resolved url path of the page or entity, it is used instead of rebuilding the url from `entity_slug` and the `default_route` route.

6. **Editor messages are no longer handled twice.** Depending on the load order, page refresh and scroll-to-block could be registered twice, so a single message from the editor was handled twice. Page refresh and scroll-to-block also work now when the click-to-edit overlay is unavailable.

### Behavior notes

- **The nitro js bridge 1.5.0 is picked up automatically**, because the url is pinned to the major version on the CDN. Its live edit hover affordance was rebuilt: hovering an editable block fades in a highlight ring plus the pencil button, drawn in a single element outside of your markup, so it can not touch your css or your layout. The most visible difference for an editor: **the pencil appears after roughly 0.6s of hovering** instead of instantly, which stops it flickering while the mouse crosses the page. It also can not be styled from your site's css anymore.
- **The rendered marker lost its surrounding spaces.** `@editable($block)` used to echo `' data-flyo-uid="uid" '`, it now echoes `data-flyo-uid="uid"`. Templates like `<div @editable($block) class="…">` are unaffected, only html snapshot tests could notice.
- **The `Flyo-Live-Edit` debug response header** sends the string `'1'`/`'0'` instead of the integer `1`/`0`. Identical over the wire.
- **The sitemap can contain more urls than before.** It used to list an entity only when the route configured in `flyo.default_route` was resolvable for it, now every entity the api resolved a url for is listed. Entities without a resolvable url are still skipped, duplicate urls are listed once. In multi lingual setups the locale prefix is part of the delivered url, urls built from `entity_slug` were missing it.
- **`flyo.default_route` is no longer read by the package.** The config key stays in `config/flyo.php` so applications using it keep working, the sitemap does not use it anymore.
- **`SitemapController` takes a `Flyo\Api\SitemapApi`** instead of the config repository and the api configuration, the api client is resolved from the container. Only relevant when the controller was instantiated or extended manually, the route keeps working unchanged.
