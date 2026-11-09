# Flyo Nitro Laravel Framework Module

<details>
<summary><strong>AI coding agent instructions (Laravel integration)</strong></summary>

The file [ai-instructions-laravel.md](ai-instructions-laravel.md) contains a complete advisory for integrating Flyo Nitro CMS into an **existing Laravel project** using `flyo/nitro-laravel`.

It is written to be pasted directly into a coding agent (Claude, Copilot, Cursor, etc.) as a system prompt or task description.

**Copy the raw instructions:**

- GitHub raw URL: `https://raw.githubusercontent.com/flyocloud/nitro-laravel/refs/heads/main/ai-instructions-laravel.md`
- Or open [ai-instructions-laravel.md](ai-instructions-laravel.md) and use the **Raw** button.

The advisory covers:

- Package installation, `vendor:publish` and the `config/flyo.php` settings
- Environment variables, access token handling and the routes which have to make way for the CMS pages
- Layout integration with `<x-flyo::head />` and `<x-flyo::debug-info />`, plus `Header` and `Footer` components driven by Flyo containers
- The `cms.blade.php` entry view and how block views are resolved by component name
- WYSIWYG and image helpers built on `Flyo\Bridge\Wysiwyg` and `Flyo\Bridge\Image`
- How to discover block fields from the OpenAPI schema of the project
- A reusable Claude skill (`.claude/skills/flyo-block/SKILL.md`) for building a named block from a design or an existing Blade view
- Entity detail routes, draft links, cache headers, sitemap and i18n
- A final validation checklist

</details>

## Usage

Requires PHP 8.3 or newer and Laravel 12 or 13.

```sh
composer require flyo/nitro-laravel
```

publish the config

```sh
artisan vendor:publish
```

Adjust the token in `config/flyo.php`

> Remove the welcome route `Route::get('/', ...)` from `routes/web.php` if the Flyo home page should be shown at `/`. Every route of your application wins over a CMS page, see [Routing](#routing).

> **Upgrading from 3.x?** CMS pages are routed differently since 4.0, read the [upgrade guide](UPGRADE.md#36--40) first.

## Routing

The package serves every page of the Flyo config through **one fallback route**. Laravel tries a fallback route after all other routes, so the rule is simple:

**Every route you register wins over a CMS page.** A CMS page is shown for an url which no other route of your application, or of a package, matches.

What this means for `routes/web.php`:

- A route with the path of a CMS page hides that page. The welcome route at `/` for example hides the Flyo home page, remove it if Flyo should serve `/`.
- A route with a parameter in the first segment, like `/{slug}`, matches the CMS pages as well. Give it a prefix (`/news/{slug}`), or restrict it with `where()` so it only matches what it really serves:

  ```php
  Route::get('/{slug}', NewsController::class)->where('slug', 'news-[0-9]+');
  ```

An url which is neither a route nor a CMS page answers with a 404. Laravel renders `resources/views/errors/404.blade.php` for it, which can use your layout and `$config` like any other view.

`php artisan route:list` shows a single route for all CMS pages, `{fallbackPlaceholder}` named `flyo.page`. To tell whether the current request is a CMS page:

```php
request()->routeIs('flyo.page');           // true on a CMS page
request()->attributes->get('flyo.page');   // the path of the page as the config delivers it, null elsewhere
app(\Flyo\Model\Page::class);              // the loaded page, once the page is rendered
```

> An unknown url answers a `POST`, `PUT`, `PATCH` or `DELETE` request with `405 Method Not Allowed`, and an `OPTIONS` request with `200`, not with `404`. Laravel does this for every application with a route which matches every path.

### Route caching

`php artisan route:cache` and `php artisan optimize` are supported: the route of the CMS pages and `/sitemap.xml` are part of the cache, and caching needs neither the token nor the api. Like for every cached route, the middleware are frozen in the cache, so run `php artisan route:cache` again after changing `page_middleware`, `sitemap` or `page_fallback` in `config/flyo.php`. A deploy which caches the routes does that anyway.

### Own fallback route

Laravel has room for one `Route::fallback()`. If your application registers one, it replaces the route of the CMS pages and no CMS page is reachable anymore. The package notices this: outside of production the request fails with an explanation, in production it is logged as an error.

- If your fallback only shows a custom 404 page, remove it and put the page into `resources/views/errors/404.blade.php`.
- If it does more, like redirecting old urls, turn the fallback of the package off and hand the CMS pages over from yours:

```php
// config/flyo.php
'page_fallback' => false,
```

```php
// routes/web.php
use Flyo\Laravel\PageRoutes;
use Illuminate\Http\Request;

Route::fallback(function (Request $request) {
    if ($target = LegacyRedirects::for($request->path())) {
        return redirect($target, 301);
    }

    return PageRoutes::dispatch($request);
});
```

`PageRoutes::dispatch()` does what the fallback route of the package does: a 404 for an url which is not a CMS page, otherwise the `page_middleware`, the cache headers and the page itself. `PageRoutes::find($request)` only checks, it returns the path of the CMS page or `null`.

## Views

Add/Adjust the `cms.blade.php` view file in `resources/views`, this is where the cms page loader starts:

```php
<?php
/** @var \Flyo\Model\Page */
?>
<x-flyo::page :page=$page />
```

Now all component block views are looked up in `ressources/views/flyo`, for example if you have a Flyo Nitro component block with name Text the view file would be `ressources/views/flyo/Text.blade.php` utilizing the following variables:

> You can adjust the views namespace in the config file using `views_namespace` key.

```php
<?php
/** @var \Flyo\Model\Block $block */
print_r($block->getContent());
print_r($block->getConfig());
print_r($block->getItems());
print_r($block->getSlots());
?>
```

To make the block editable you must place the Blade directive `@editable($block)` on the block's root HTML element. This ensures the Flyo editor can correctly detect the block and display the edit icon next to that element when the page is opened in the editor. In short: put `@editable($block)` on the outermost element of the block so clicking the icon opens this block for editing.

```blade
<?php
/** @var \Flyo\Model\Block $block */
?>
<div @editable($block) style="border:1px solid blue; padding:20px;">
    <?php print_r($block->getContent()); ?>
</div>
```

In raw php templates, or anywhere else the blade directive is not available (a controller, a string you build yourself), use `Flyo\Laravel\Editable` instead:

```php
<section <?= Flyo\Laravel\Editable::attr($block); ?>>
    <?php print_r($block->getContent()); ?>
</section>
```

`Editable::attr($block)` returns the escaped `data-flyo-uid="..."` attribute, or an empty string when live edit is disabled. `Editable::uid($block)` gives you the raw uid, `Editable::isEnabled()` the live edit state. The marker alone is not enough though: the javascript which makes it interactive is loaded by the `<x-flyo::head />` component, so your layout has to include it.

## Typed Schemas

`php artisan flyo:types` generates a class per block, navigation container and entity type of your integration into `app/Flyo`, so the IDE and PHPStan know the fields instead of a bare `stdClass`:

```
app/Flyo/
├── Blocks/        BlockHero.php, BlockText.php, ...   namespace App\Flyo\Blocks
├── Containers/    ContainerMain.php, ...              namespace App\Flyo\Containers
└── Entities/      EntityArticle.php, ...              namespace App\Flyo\Entities
```

```sh
php artisan flyo:types             # regenerate the classes
php artisan flyo:types --dry-run   # report what would change, write nothing
php artisan flyo:types --check     # write nothing, exit with code 6 when the classes are out of date
```

The command runs the `flyo-generate-types` generator of `flyo/nitro-php` against the authenticated `https://api.flyo.cloud/nitro/v1/openapi/schemas` endpoint, the public OpenAPI document has no typed schemas. The token is taken from `config/flyo.php`, so the `FLYO_TOKEN` of the `.env` file is used and the token is never passed as a command line argument. The classes go into the `Flyo` sub-namespace of the application namespace, `App\Flyo` for a standard Laravel project.

Annotate a block view with its generated class instead of `\Flyo\Model\Block`:

```blade
<?php
/** @var \App\Flyo\Blocks\BlockHero $block */
?>
<section @editable($block)>
    <h1>{{ $block->getContent()?->title }}</h1>
</section>
```

The classes are documentation only: nothing instantiates them, at runtime a block is still a `\Flyo\Model\Block` and its fields are still plain objects which can be empty, so keep guarding the field access. See the [Typed Schemas section of the SDK](https://github.com/flyocloud/nitro-php-sdk#-typed-schemas) for what the container and entity classes narrow.

Commit the generated files and run `php artisan flyo:types --check` in CI, with `FLYO_TOKEN` provided as a secret. To have the same entry point as the Next.js and Astro integrations (`npm run flyo:types`), add a composer script, anything after `--` is appended to the command:

```json
{
    "scripts": {
        "flyo:types": "@php artisan flyo:types"
    }
}
```

```sh
composer flyo:types
composer flyo:types -- --check
```

## Live Edit

With `live_edit` enabled in `config/flyo.php`, the `<x-flyo::head />` component loads the [nitro js bridge](https://github.com/flyocloud/nitro-js-bridge) from the CDN and wires everything the Flyo editor needs when the site is displayed inside the editor preview iframe:

- **Page refresh**: the editor can reload the preview after a change.
- **Editor handshake**: the preview announces itself, so the editor can show troubleshooting hints instead of a silent white screen when the preview is blocked or points at a build without live edit.
- **Scroll to block**: selecting a block in the editor scrolls the preview to it.
- **Click to edit**: hovering a block rendered with `@editable($block)` fades in a highlight ring plus a pencil button which opens that block in the editor.

The hover affordance appears after roughly half a second of hovering, so it does not flicker while the mouse crosses the page. It is drawn in a single overlay element outside of your markup: no styles, classes, attributes or listeners are added to your elements and layout and scrolling are untouched. On the live site nothing of it is loaded at all, since `live_edit` is disabled there.

The bridge url is pinned to the major version, so bridge releases are picked up automatically. To self host it or to pin an exact version, set the url in `config/flyo.php`:

```php
'live_edit_bridge_url' => env('FLYO_LIVE_EDIT_BRIDGE_URL', 'https://unpkg.com/@flyo/nitro-js-bridge@1.5.0/dist/nitro-js-bridge.umd.cjs'),
```

## Layout Variable

Every view gets the config response of the api as `$config`, for example to build menus in layout components:

```php
/** @var \Flyo\Model\ConfigResponse $config */
<div>
    <?php foreach($config->getContainers()['mainnav']->getItems() as $nav): ?>
        <a href="<?= $nav->getHref(); ?>"><?= $nav->getLabel(); ?></a>
    <?php endforeach; ?>
</div>
```

The config is loaded from the api the first time it is needed in a request, and at most once per request: by the page check of a CMS page, by the first view which renders, or by a class which takes `Flyo\Model\ConfigResponse` in its constructor. A request which renders no view, like a json endpoint, a redirect, a form post, a file download or an image proxy, does not load it at all.

`$config` is given to every view by default. To load the config only for your layouts, and not for partials, mails or Livewire updates, narrow `config_views` to the views which really use it:

```php
// config/flyo.php
'config_views' => ['layouts.*', 'components.layout'],
```

A view which is handed a variable `config` of its own keeps it.

If the Flyo api can not be reached, only the requests which need the config fail. If your error page uses the layout as well, Laravel falls back to its plain error page. Monitor a CMS page if you want to know when Flyo is not reachable, an uptime check of `/up` does not load the config.

Make sure to include the `<x-flyo::head>` component in the head of your layout file, for example

```blade
<head>
    <title>My Super Website</title>
    <x-flyo::head />
</head>
```

This will add needed javascript for reloading and editin blocks in local environments and also assign all available meta informations.

The meta informations are taken from the api response of the current page (or entity when using the `EntityController`), which includes the title, description, image and the schema.org json-ld object rendered as an `application/ld+json` script.

A full layout example which could be placed in `resources/views/layouts/app.blade.php`:

```blade
<?php
/** @var \Flyo\Model\ConfigResponse $config */
?>
<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <x-flyo::head />
    </head>
    <body>
        <ul>
            <?php foreach ($config->getContainers() as $container): ?>
                <li><?= $container->getLabel(); ?></li>
                <ul>
                    <?php foreach ($container->getItems() as $page): ?>
                        <li><a href="<?= $page->getHref(); ?>"><?= $page->getLabel(); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </ul>
        <hr/>
        {{ $slot }}
        <!--
            This provides useful debugging information such as CMS version, application environment, and more.
            It is especially helpful in production deployments to quickly identify configuration and environment details.
        -->
        <x-flyo::debug-info />
    </body>
</html>
```

## Entity Detail

To display an entity detail page, you have to register a route, create a controller and a view file:

Routing File example

```php
<?php

use App\Http\Controllers\TierController;
use Illuminate\Support\Facades\Route;

Route::get('/tier/{slug}', [TierController::class, 'show']);
```

The Controller:

```php
<?php

namespace App\Http\Controllers;

use Flyo\Api\EntitiesApi;
use Flyo\Configuration;
use Illuminate\Contracts\View\Factory;

class TierController extends Controller
{
    public function __construct(public Factory $viewFactory, public Configuration $config) {}

    public function show(string $slug)
    {
        $api = new EntitiesApi(null, $this->config);

        $entity = $api->entityBySlug($slug);

        return $this->viewFactory->make('tier', [
            'entity' => $entity,
        ]);
    }
}
```

And the example `tier.blade.php` in the `resources/views` folder:

```blade
<?php
/** @var \Flyo\Model\Entity $entity */
/** @var \Flyo\Model\EntityInterface $model */
/** @var \Flyo\Model\Translation[] $translation */
/** @var \Flyo\Model\Breadcrumb[] $breadcrumb */
?>
<x-layout>
    <h1><?= $entity->getModel()->image->source; ?></h1>
</x-layout>
```

There is also a more generic controller available which can be used to display any entity detail page:

```php
Route::get('/poi/{slug}', function ($slug) {
    return app(Flyo\Laravel\Controllers\EntityController::class)->resolve(fn (Flyo\Api\EntitiesApi $api, $param) => $api->entityBySlug($param, 116))->render($slug, 'poi');
});
```

where the `poi.blade.php` file in the `resources/views` folder could look like this:

```blade
<?php
/** @var Flyo\Model\EntityInterface $entity */
/** @var object $model */
?>
<x-layout>
    <?php print_r($model); ?>
    <?php print_r($entity); ?>
</x-layout>
```

## Search Engine Indexing

Pages and entities carry an `is_indexable` flag (flyo/nitro-php 3.2). When the api marks a document
as not indexable, `Head::metaPage()` and `Head::metaEntity()` render a robots meta tag in the head:

```html
<meta name="robots" content="noindex">
```

This is not access control, the page or the entity still resolves like any other, it is only kept
out of the search engines (and out of the sitemap and the search endpoint on the api side). A draft
entity is always flagged as not indexable, see [Draft Links](#draft-links).

The flag can also be set by hand, for a page which the application itself wants to hide:

```php
Flyo\Laravel\Components\Head::noIndex();
```

Call it after `metaPage()` / `metaEntity()`, those assign the flag from the api response and would
otherwise reset it.

## Sitemap

The package registers `/sitemap.xml`, which lists every page and entity the api resolved a url for,
each with a `<lastmod>` from the timestamp of its last content change. The locations are absolute
urls built from the incoming request root.

The route is enabled by default. Turn it off with `sitemap` if the application should not serve that
sitemap, for example because it renders a sitemap of its own at that path or none at all:

```php
// config/flyo.php
'sitemap' => env('FLYO_SITEMAP', true),
```

## Page Middleware

`page_middleware` adds middleware to the CMS pages, and to nothing else. The listed middleware run
after the `web` group and `CachingHeaders`, and before the page is loaded from the api. For an url
which is not a CMS page they do not run at all: `auth` does not send a guest to the login for a 404,
and `throttle` does not count it. Hand written routes, like the entity detail pages, are not
affected, so the middleware does not have to tell a CMS page apart from the rest of the application.

```php
// config/flyo.php
'page_middleware' => [
    \App\Http\Middleware\RedirectToFirstChild::class,
    'can:view-intranet',
],
```

A middleware which returns early (a redirect, an `abort()`) saves the api request of the page. The
`Flyo\Model\ConfigResponse` with the navigation containers is already loaded at that point, the page
check needed it. For example, a page with children but no content of its own can send the visitor to
its first child:

```php
class RedirectToFirstChild
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');
        $items = app(ConfigResponse::class)->getContainers()['navigation']?->getItems() ?? [];

        foreach ($items as $item) {
            $first = $item->getChildren()[0] ?? null;
            if ($first && trim($item->getSlug(), '/') === $path) {
                // 302, not 301: editors can reorder the children at any time
                return redirect($first->getHref());
            }
        }

        return $next($request);
    }
}
```

Use a 302 for anything derived from the content, a permanent redirect stays in the browsers' cache
after the editors changed the page tree.

## Cache Headers

`Flyo\Laravel\Middleware\CachingHeaders` is applied to the CMS pages and the sitemap. It
writes one header for the browser and the two cdn specific ones which take precedence on the edge:

```
Cache-Control: max-age=1200                                          # client_cache_ttl
CDN-Cache-Control: max-age=900, stale-while-revalidate=450           # server_cache_ttl
Vercel-CDN-Cache-Control: max-age=900, stale-while-revalidate=450
```

The `stale-while-revalidate` window is what keeps the origin quiet around an expiry: without it the
moment an edge entry expires turns into an origin request for every visitor waiting on that url at
that moment, with it the edge keeps answering from the stale copy and refreshes itself with a single
background request. It is configured with `server_cache_stale_while_revalidate_ttl`, which defaults
to `450` seconds (half of the default `server_cache_ttl`). Nothing has to be changed to get that
behavior, set it to `0` to send `max-age` alone:

```php
// config/flyo.php
'server_cache_ttl' => env('FLYO_SERVER_CACHE_TTL', 900),
'server_cache_stale_while_revalidate_ttl' => env('FLYO_SERVER_CACHE_STALE_WHILE_REVALIDATE_TTL', 0),
```

A visitor can therefore be served a page which is up to
`server_cache_ttl + server_cache_stale_while_revalidate_ttl` seconds old, but only until the
background refresh of the first request after the expiry has finished.

Set `server_cache_ttl` or `client_cache_ttl` to `0` to opt out of that layer, a `server_cache_ttl`
of `0` sends `no-store` to the edge and never carries a stale window. Caching is switched off
entirely when `flyo.live_edit` is enabled or `APP_DEBUG` is on, and a non successful response is
not cached either. `Flyo\Laravel\Middleware\CachingHeaders::cdnCacheControl($ttl, $staleTtl)`
builds the value of the cdn headers, in case an application writes them somewhere else too.

The cache headers are enabled by default. Turn them off with `cache_headers` if the responses should
not be cached by the client or a cdn at all, the middleware then writes none of the three headers
and a response keeps the `Cache-Control` header of the application, which is `no-cache, private` in
Laravel unless something else writes one:

```php
// config/flyo.php
'cache_headers' => env('FLYO_CACHE_HEADERS', true),
```

A response rendered from a draft link is still sent with its no store headers, see
[Draft Links](#draft-links).

Add the middleware to a hand written route which serves CMS content and should be cached the same way:

```php
Route::get('/tier/{slug}', $handler)->middleware(\Flyo\Laravel\Middleware\CachingHeaders::class);
```

## Draft Links

A draft link is a shareable, expiring snapshot of an entity which is still offline in Flyo. It is
requested through the regular entity endpoints, with a **draft token** in place of the slug or the
unique id, and the api answers with `is_draft` set and a `draft_expires_at` timestamp:

```php
$entity = $api->entityByUniqueid($uniqueidOrDraftToken);

if ($entity->getIsDraft()) {
    // not the live page, the link stops working at $entity->getDraftExpiresAt()
}
```

Two things a route serving draft links has to respect:

1. **The token does not look like a slug or a unique id**, so a parameter pattern (`->where(...)`)
   has to let it through.
2. **The entity type id does not apply to a token**, so resolve without it:

```php
Route::get('/tier/{slug}', function ($slug) {
    return app(EntityController::class)
        ->resolve(fn (EntitiesApi $api, $param) => $api->entityBySlug($param)) // no type id
        ->render($slug, 'tier');
});
```

### A draft response is never cached

Once an entity was delivered through a draft link, the package makes the whole response
uncacheable, for the client and for a cdn or another server side cache alike:

```
Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private
CDN-Cache-Control: no-store
Vercel-CDN-Cache-Control: no-store
Surrogate-Control: no-store
X-Robots-Tag: noindex, nofollow
```

`ETag` and `Last-Modified` are dropped as well, and the `server_cache_ttl` / `client_cache_ttl`
config is ignored for such a response. The draft snapshot is rewritten with every save of the
editor and the link answers with a 404 once it expired, so a stored copy would keep serving content
which is outdated or gone. The expiration timestamp is deliberately not used as a cache ttl.

The headers are written by `Flyo\Laravel\Middleware\PreventDraftCaching`, which the package
registers as the outermost global middleware, so it also covers routes which do not use the
`CachingHeaders` middleware.

### Rendering a hint

`EntityController` hands the draft state to the view, so a template can tell the visitor that this
is not the live page:

```blade
@if ($isDraft)
    <p>Draft preview, this page is not online.
        @if ($draftExpiresAt)
            The link expires {{ \Carbon\Carbon::createFromTimestamp($draftExpiresAt)->diffForHumans() }}.
        @endif
    </p>
@endif
```

Everywhere else the state is readable from `Flyo\Laravel\DraftMode`:

```php
Flyo\Laravel\DraftMode::isDraft();    // bool
Flyo\Laravel\DraftMode::expiresAt();  // unix timestamp or null
```

A **custom controller** resolving an entity itself flags the draft by calling
`Flyo\Laravel\Components\Head::metaEntity($entity)` (which every entity page does anyway to
assign its meta data) or explicitly:

```php
$entity = $api->entityBySlug($slugOrDraftToken);

Flyo\Laravel\DraftMode::detect($entity);
```

## Multilanguage

The requests will pass the configured APP_LOCALE (which is used in laravel for localization) to the flyo api. 

Defined the available locales in the `config/flyo.php` file:

```php
'locales' => [
    'de',
    'en',
],
```

The ServiceProvider will check for segments /de, /en in the url and set the locale in the request object if the locale is available in the config file.

Pass the language for entity Detail Requests:

```php
Route::get('{locale}/ort/{slug}', function ($locale, $slug) {
    App::setLocale($locale); // set the locale in laravel
    return app(EntityController::class)
        ->resolve(fn (EntitiesApi $api, $param) => $api->entityBySlug($param, 245, $locale)) // <!-- pass the locale here
        ->render($slug, 'poi');
})->where('lang', '[a-z]{2}')->name('poi');
```

## Misc

In order to resolve the config response somewhere in your application, you can use the following code. Resolving it loads the config from the api, unless something did so before in the same request:

```php
// use DI to resolve the config response
public function __construct(public Flyo\Model\ConfigResponse $config)
{
}

// or facade
/** @var Flyo\Model\ConfigResponse $cfg */
$configResponse = app(Flyo\Model\ConfigResponse::class);
```

Same for the page response, which is bound once a CMS page was loaded

```php
// use DI to resolve the page response
public function __construct(public Flyo\Model\Page $page)
{
}

// or facade
/** @var Flyo\Model\Page $cfg */
$page = app(Flyo\Model\Page::class);
```

## Testing

Tests run on the command line, where the package does not talk to the api. The route of the CMS pages exists, but without a config there are no pages: an url which is not a route of your application answers with a 404, without a request to Flyo.

To render a CMS page in a test of your application, bind the config and fake the page:

```php
use Flyo\Api\PagesApi;
use Flyo\Model\ConfigResponse;
use Flyo\Model\Page;
use Illuminate\Support\Facades\View;

$config = new ConfigResponse(['pages' => ['about']]);
$this->app->instance(ConfigResponse::class, $config);
View::share('config', $config); // on the command line the views do not get $config by themselves

$pages = Mockery::mock(PagesApi::class);
$pages->shouldReceive('page')->with('about', 'en')->andReturn(new Page(['title' => 'About']));
$this->app->instance(PagesApi::class, $pages);

$this->get('/about')->assertOk()->assertSee('About');
```

## Example `AGENTS.md`

If you build your project with an AI coding assistant (Claude Code, Copilot, Cursor, etc.), drop an `AGENTS.md` file in your project root so the assistant understands your stack and knows where to find the Flyo/Nitro documentation. `AGENTS.md` is the vendor-neutral convention most coding agents read on startup, and if your tool uses a specific memory file such as `CLAUDE.md`, use that name too (or have it reference `AGENTS.md`).

Here is a minimal starting point you can copy and adapt. Note that it **self-references this library's docs**, the usage guide and the AI integration advisory, so the assistant can pull in the full Flyo Nitro setup and context on demand:

```markdown
# Flyo Nitro CMS

This is the new XYZ website of XYZ.

It uses the **Flyo Nitro** headless CMS via `flyo/nitro-laravel` to manage the content of the website. Pages are composed of CMS-driven blocks, plus entities and containers, rendered with Laravel Blade.

When working on any Flyo/Nitro code (block views, entities, `config/flyo.php`, the layout, routes), consult these sources for the full context of the library:

- Usage guide & API reference: https://github.com/flyocloud/nitro-laravel#usage
- AI integration advisory (raw): https://raw.githubusercontent.com/flyocloud/nitro-laravel/refs/heads/main/ai-instructions-laravel.md
- Full Nitro CMS documentation: https://docs.flyo.cloud/doc/integrations-nitro-cms

Project conventions:

- CMS pages are served by the fallback route `flyo.page` of the package, after every other route. A route in `routes/web.php` with the path of a CMS page, or a wildcard like `/{slug}` without a prefix, hides the page. Do not add a `Route::fallback()`, use `resources/views/errors/404.blade.php` for a custom 404 page.
- Flyo block views live in `resources/views/flyo` and are resolved by file name (the Flyo component name), there is no component map.
- Every block view puts `@editable($block)` on its outermost element, and the layout includes `<x-flyo::head />`, otherwise live edit does not work.
- Block, container and entity fields are typed by the classes `php artisan flyo:types` generates into `app/Flyo`. Annotate block views with them, regenerate them after a schema change, and still guard every field access: at runtime the fields are plain objects which can be empty. Never guess a field name.
- WYSIWYG fields render through `<x-wysiwyg />`, images through `<x-flyo-image />` / `Flyo\Bridge\Image` with explicit width and height.
- Build one named block at a time with the `.claude/skills/flyo-block` skill.
```

## Documentation

[Read More in the Docs](https://dev.flyo.cloud/nitro/php)

## Upgrading

See [UPGRADE.md](UPGRADE.md) for what changed between versions.

## Package Development

1. Check the `example-app/.env` file to have a correct flyo token. 
2. Go to example-app and run `php artisan serve` to get the example app running.

Run the checks the CI runs:

```sh
composer pint      # code style
composer phpunit   # tests
composer test      # both
vendor/bin/phpstan analyse
```
