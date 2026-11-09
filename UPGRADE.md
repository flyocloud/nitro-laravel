# Upgrade

## 3.6 → 4.0

**A major release, routing changed.** Until 3.x the package asked the Flyo api for the config while
booting, on every single request, and registered a route for every CMS page. 4.0 serves all CMS
pages through one fallback route and loads the config only when something needs it. That brings:

- **No api request for requests without a page.** Json endpoints, redirects, form posts, file
  downloads, image proxies and `/up` no longer wait for the config. A page with a layout still
  loads it once, as before.
- **`php artisan route:cache` and `php artisan optimize` work.** Until 3.x they removed every CMS
  page.
- **A Flyo outage no longer takes the whole application down.** Only the requests which need the
  config fail.

4.0 requires **PHP 8.4** and **Laravel 12 or 13**. PHP 8.4 brings lazy objects, which the package
uses for `$config` in the views (see below). Laravel 11 is no longer supported, it is end of life and
Composer refuses to install it because of open security advisories.

For most projects `composer require flyo/nitro-laravel:^4.0` is all it takes. Go through the
checklist below to be sure, it takes a few minutes.

### Checklist

0. **Still on PHP 8.3 or Laravel 11?** Move the server to PHP 8.4 and the project to Laravel 12
   first, while it is still on 3.6 of this package, which supports both. See
   [From Laravel 11](#from-laravel-11).

1. **Does `routes/web.php` contain a `Route::fallback()`?** Then it replaces the route of all CMS
   pages, see [Own fallback route](#own-fallback-route). The package tells you: outside of
   production a request fails with an explanation, in production it is logged.

   ```sh
   grep -rn "Route::fallback" routes app
   ```

2. **Is there a route with a parameter in the first segment, like `/{slug}`, `/{any}` or
   `/{category}/{slug}`?** It now wins over the CMS pages, see
   [Wildcard routes](#wildcard-routes).

   ```sh
   php artisan route:list
   ```

3. **Does code look at the route of a CMS page?** Search for `route()->uri()`, `Route::current()`,
   `Route::getRoutes()` and `Route::has(`, see [Breaking changes](#breaking-changes) 3.

4. **Optional: cache the routes.** Add `php artisan route:cache` (or `php artisan optimize`) to the
   deploy. Never with 3.x.

### Own fallback route

Laravel has room for one `Route::fallback()`. The package registers it now, a fallback of the
application loads later and replaces it, and then every CMS page ends up in the fallback of the
application.

**The fallback only renders a custom 404 page:** remove it and move the page to
`resources/views/errors/404.blade.php`. Laravel renders that view for every unknown url, with your
layout and `$config` if you like.

```diff
 // routes/web.php
-Route::fallback(fn () => response()->view('not-found', [], 404));
```

**The fallback does more** (redirects of old urls, a proxy, a search): turn the fallback of the
package off and hand the CMS pages over from yours with `PageRoutes::dispatch()`. It runs the same
page check, `page_middleware` and cache headers as the route of the package:

```diff
 // config/flyo.php
+'page_fallback' => false,
```

```diff
 // routes/web.php
+use Flyo\Laravel\PageRoutes;

 Route::fallback(function (Request $request) {
     if ($target = LegacyRedirects::for($request->path())) {
         return redirect($target, 301);
     }

-    abort(404);
+    return PageRoutes::dispatch($request);
 });
```

`PageRoutes::find($request)` returns the path of the CMS page the request points to, or `null`, if
your fallback has to decide first.

### From Laravel 11

Laravel 12 is a small release, follow the [official upgrade guide](https://laravel.com/docs/12.x/upgrade)
and bump the framework while the project is still on 3.6 of this package:

```sh
composer require laravel/framework:^12.0 --with-all-dependencies
```

Check that the site works, then update to 4.0. Of the changes in Laravel 12, these can touch a Flyo
project:

- **Carbon 3 is required** (Laravel 11 also allowed Carbon 2). `Carbon::createFromTimestamp()` now
  creates the date in UTC instead of the timezone of the application. Flyo delivers its dates as unix
  timestamps (`draftExpiresAt`, `updated_at`, date fields of entities), so pass the timezone where
  you format one:

  ```diff
  -Carbon::createFromTimestamp($draftExpiresAt)->format('d.m.Y H:i')
  +Carbon::createFromTimestamp($draftExpiresAt, config('app.timezone'))->format('d.m.Y H:i')
  ```

  `diffForHumans()` is not affected. `diffInDays()` and the other `diffIn*()` methods return a float
  now, and a negative number for a date in the past. `<x-flyo::debug-info />` already passes the
  timezone.

- **A constructor parameter with a default value gets that default** when nothing is bound in the
  container, Laravel 11 created an empty object instead. `?Flyo\Model\Page $page = null` in a
  component is now `null` on a page which is not a CMS page (until then an empty page), the same holds
  for `ConfigResponse` and `Configuration` in a queue job or an artisan command. Check such
  parameters for `null`, or leave the default away where the binding always exists.

- **The tests run on PHPUnit 11 or 12.** Nothing in this package depends on it.

### Wildcard routes

Until 3.x a CMS page won over a route like `Route::get('/{slug}', ...)`, because the page routes were
registered first. In 4.0 every route of the application wins, so such a route now catches the CMS
pages as well. Narrow it to what it really serves:

```diff
-Route::get('/{slug}', [NewsController::class, 'show']);
+Route::get('/news/{slug}', [NewsController::class, 'show']);
+// or
+Route::get('/{slug}', [NewsController::class, 'show'])->where('slug', 'news-[0-9]+');
```

A route with exactly the path of a CMS page (`/kontakt`) won over the page in 3.x already, nothing
changes there.

### Breaking changes

1. **A `Route::fallback()` of the application replaces the route of the CMS pages.** See
   [Own fallback route](#own-fallback-route).

2. **Wildcard routes win over CMS pages.** See [Wildcard routes](#wildcard-routes).

3. **CMS pages are no longer routes of their own.** All of them share one route: `route:list`,
   `Route::has()` and `Route::getRoutes()` no longer list single pages, and
   `request()->route()->uri()` is `{fallbackPlaceholder}` on every page. Monitoring which groups by
   route (Sentry, Nightwatch, Pulse, Telescope) shows all CMS pages as one entry. Read the path from
   `request()->path()` instead, and tell a CMS page apart with:

   ```php
   request()->routeIs('flyo.page');
   request()->attributes->get('flyo.page'); // the path of the page, as the config delivers it
   ```

4. **Other methods on an unknown url answer with 405 instead of 404.** `POST`, `PUT`, `PATCH` and
   `DELETE` get `405 Method Not Allowed`, `OPTIONS` gets `200` with an `Allow` header. Laravel does
   this for every application with a route which matches every path. Adjust monitoring or firewall
   rules which count 404s, if any.

5. **`$config` in the views is a lazy object.** It is still shared with every view and is a
   `Flyo\Model\ConfigResponse` for every type check, but it loads the config only when a view reads
   it. Nothing has to change in your views. The only difference: it is not the same object as
   `app(\Flyo\Model\ConfigResponse::class)`, so compare the two with `==`, not `===`, if you ever
   do.

6. **A Flyo outage only fails the requests which need the config.** Until 3.x every request failed
   with a 500, so an uptime check of `/up` noticed it. Monitor a CMS page if you want to know. If
   your error page renders the layout with `$config`, Laravel falls back to its plain error page
   while Flyo is down. A missing token still fails every request, as before.

7. **The config is loaded in the locale which is set when it is first needed.** Until 3.x it was
   the locale of the first url segment (with more than one `flyo.locales`), at boot. That detection
   is unchanged and still happens at boot, so nothing changes unless your application sets the
   locale itself, in a middleware for example. A CMS page loads the config right after the global
   middleware, before the `web` group.

8. **`PageRoutes::boot()` is removed.** The package registers its route itself.
   `PageRoutes::middleware()` is unchanged.

9. **The route exists in tests and on the command line.** An unknown url in a test of your
   application now passes the page check of the package and still answers with a 404, without a
   request to the api. `/sitemap.xml` is a route in tests as well. See the "Testing" section of the
   readme to render a CMS page in a test.

10. **Octane and other long running workers load the config for every request.** Until 3.x it was
    loaded once per worker, including the list of pages, which therefore went out of date.

11. **The route cache contains the page middleware.** If you cache routes, run
    `php artisan route:cache` again after changing `page_middleware`, `sitemap` or `page_fallback`.

12. **PHP 8.4 and Laravel 12 or 13 are required.** See the beginning of this section.

### What's new

1. **`page_fallback`** (default `true`): turn the fallback route of the package off and register
   your own, see above.
2. **`$config` costs nothing until it is used.** A view, a partial, a mail or a Livewire update
   which does not read `$config` no longer loads the config, without any setting.
3. **`PageRoutes::dispatch($request)`** and **`PageRoutes::find($request)`** for an own fallback
   route.
4. **Route names:** the route of the CMS pages is `flyo.page`, the sitemap `flyo.sitemap`.

A project which published `config/flyo.php` before does not have the new key, it falls back to its
default, so republishing the config file is not required.

### Unchanged

- The `cms` view, the `Flyo\Model\Page` binding, the meta data of the head component.
- The `page_middleware` and their order, after the `web` group and `CachingHeaders`. They still run
  for CMS pages only: the page check comes first, so `auth` does not send a guest to the login for
  an unknown url and `throttle` does not count it.
- An unknown url still answers a `GET` request with a 404 before a session is started.
- Cache headers, draft links, live edit, the sitemap content and `EntityController`.
- `Flyo\Configuration` is bound on every request, `new EntitiesApi()` without a configuration keeps
  working.
- Upper and lower case, trailing slashes, url encoded paths and `HEAD` requests match a CMS page
  the same way as before.

## 3.5 → 3.6

**No breaking changes.** `composer update flyo/nitro-laravel` is enough, no code changes are
required in a project. Without the new option the page routes carry the same middleware as in 3.5.

### What's new

1. **Middleware for the CMS page routes.** The new `flyo.page_middleware` adds middleware to the
   routes the package registers for the pages of the Flyo config, and to nothing else. They run
   after the `web` group and `CachingHeaders`, and before the page is loaded from the api, so a
   redirect or an `abort()` costs no api request. See the "Page Middleware" section of the readme.

   ```diff
    // config/flyo.php
   +'page_middleware' => [
   +    \App\Http\Middleware\RedirectToFirstChild::class,
   +],
   ```

   A project which published `config/flyo.php` before does not have the key, it falls back to
   `[]`, so republishing the config file is not required.

   A project which appended a middleware to the `web` group only to reach the CMS pages, and
   checked inside it whether the request is for one of them (for example against
   `ConfigResponse::getPages()`), can move it to `page_middleware` and drop that check.

2. **The page routes are registered by `Flyo\Laravel\PageRoutes`.** The service provider calls
   `PageRoutes::boot()` instead of registering the routes inline. The routes, their paths and the
   `cms` view they render are unchanged. The loaded page is still bound as `Flyo\Model\Page` in the
   container.

3. **`CachingHeaders` passes every response through.** `handle()` now returns a
   `Symfony\Component\HttpFoundation\Response` instead of an `Illuminate\Http\Response`. Before, a
   redirect, json or streamed response behind the middleware ended in a `TypeError`, which a
   redirecting page middleware would have run into. A class which extends `CachingHeaders` and
   overrides `handle()` has to widen its return type the same way.

## 3.4 → 3.5

**No breaking changes.** `composer update flyo/nitro-laravel` is enough, no code changes are
required in a project. The package now requires `flyo/nitro-php` 3.6 or newer, which the update
pulls in, nothing in its api or models changed.

### What's new

1. **`php artisan flyo:types` generates the typed schema classes.** It runs the
   `flyo-generate-types` generator of `flyo/nitro-php` with the token of `config/flyo.php` and
   writes a class per block, container and entity type into `app/Flyo` (`App\Flyo\Blocks`,
   `App\Flyo\Containers`, `App\Flyo\Entities`). `--dry-run` reports what would change, `--check`
   exits with code `6` when the committed classes are out of date. See the "Typed Schemas"
   section of the readme.

   A project which already runs the generator through a composer script can switch the script to
   the command, the token then comes from the `.env` file instead of the shell:

   ```diff
    "scripts": {
   -    "flyo:types": "vendor/bin/flyo-generate-types https://api.flyo.cloud/nitro/v1/openapi/schemas App/Flyo app/Flyo"
   +    "flyo:types": "@php artisan flyo:types"
    }
   ```

   Both write the same files, so classes generated before keep passing `--check`. A project which
   defines a `flyo:types` artisan command of its own can remove it.

## 3.3 → 3.4

**No breaking changes.** `composer update flyo/nitro-laravel` is enough, no code changes are
required in a project. Both new options are enabled by default, which is the behavior of 3.3.

### What's new

1. **The sitemap can be turned off.** The package still registers `/sitemap.xml` by default, set
   the new `flyo.sitemap` to `false` if the application should not serve that sitemap:

   ```diff
    // config/flyo.php
   +'sitemap' => env('FLYO_SITEMAP', true),
   ```

2. **The cache headers can be turned off.** Set the new `flyo.cache_headers` to `false` and the
   `CachingHeaders` middleware writes neither `Cache-Control` nor `CDN-Cache-Control` nor
   `Vercel-CDN-Cache-Control`, a response keeps the `Cache-Control` header of the application
   (`no-cache, private` in Laravel unless something else writes one). A response rendered from a
   draft link is still sent with its no store headers.

   ```diff
    // config/flyo.php
   +'cache_headers' => env('FLYO_CACHE_HEADERS', true),
   ```

A project which published `config/flyo.php` before does not have the two keys, both fall back to
`true`, so republishing the config file is not required.

## 3.2 → 3.3

**No breaking changes.** `composer update flyo/nitro-laravel` is enough, no code changes are
required in a project.

### What's new

1. **The cdn headers carry `stale-while-revalidate`.** `Vercel-CDN-Cache-Control` and
   `CDN-Cache-Control` used to be written as `max-age=<server_cache_ttl>` only, which turns the
   moment an edge entry expires into an origin request for every visitor waiting on that url at
   that moment. They now carry a `stale-while-revalidate` window as well, so the edge answers from
   the stale copy and refreshes itself with a single background request:

   ```diff
   -Vercel-CDN-Cache-Control: max-age=900
   -CDN-Cache-Control: max-age=900
   +Vercel-CDN-Cache-Control: max-age=900, stale-while-revalidate=450
   +CDN-Cache-Control: max-age=900, stale-while-revalidate=450
   ```

   The window is configured with the new `flyo.server_cache_stale_while_revalidate_ttl` (`450` by
   default, half of the default `server_cache_ttl`). Nothing has to be changed to get the new
   behavior, set it to `0` to keep the old headers:

   ```diff
    // config/flyo.php
    'server_cache_ttl' => env('FLYO_SERVER_CACHE_TTL', 900),
   +'server_cache_stale_while_revalidate_ttl' => env('FLYO_SERVER_CACHE_STALE_WHILE_REVALIDATE_TTL', 0),
   ```

   A project which published `config/flyo.php` before does not have the key, the middleware falls
   back to `450` seconds, so republishing the config file is not required either. Note that a
   visitor can now be served a page which is up to
   `server_cache_ttl + server_cache_stale_while_revalidate_ttl` seconds old, but only until the
   background refresh of the first request after the expiry has finished.

   A `server_cache_ttl` of `0` and a draft response are unaffected, both still send `no-store` to
   the edge, never a stale window. `Flyo\Laravel\Middleware\CachingHeaders::cdnCacheControl()`
   builds the value, in case an application writes the cdn headers somewhere else too.

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
