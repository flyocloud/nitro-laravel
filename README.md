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
- How to discover block fields without type generation (PHP has no generated types)
- A reusable Claude skill (`.claude/skills/flyo-block/SKILL.md`) for building a named block from a design or an existing Blade view
- Entity detail routes, draft links, cache headers, sitemap and i18n
- A final validation checklist

</details>

## Usage

```sh
composer require flyo/nitro-laravel
```

publish the config

```sh
artisan vendor:publish
```

Adjust the token in `config/flyo.php`

> Ensure to remove the default routes in `routes/web.php` which could conflict with the cms routes.

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

In order to build menus, the `$config` response from the api is a global available variable, for example this could be used in layout-components:

```php
/** @var \Flyo\Model\ConfigResponse $config */
<div>
    <?php foreach($config->getContainers()['mainnav']->getItems() as $nav): ?>
        <a href="<?= $nav->getHref(); ?>"><?= $nav->getLabel(); ?></a>
    <?php endforeach; ?>
</div>
```

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

In order to resolve the Configuration object somewhere in your application, you can use the following code:

```php
// use DI to resolve the Configuration object
public function __construct(public Flyo\Model\ConfigResponse $config)
{
}

// or facade
/** @var Flyo\Model\ConfigResponse $cfg */
$configResponse = app(Flyo\Model\ConfigResponse::class);
```

Same for the page response

```php
// use DI to resolve the Configuration object
public function __construct(public Flyo\Model\Page $page)
{
}

// or facade
/** @var Flyo\Model\Page $cfg */
$page = app(Flyo\Model\Page::class);
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

- CMS page routes are registered per request by the package service provider from the Flyo config response, so they do not show up in `php artisan route:list`. Keep `routes/web.php` free of routes which collide with CMS page slugs.
- Flyo block views live in `resources/views/flyo` and are resolved by file name (the Flyo component name), there is no component map.
- Every block view puts `@editable($block)` on its outermost element, and the layout includes `<x-flyo::head />`, otherwise live edit does not work.
- CMS fields are untyped `stdClass`, there is no type generation for PHP. Guard every field access and confirm field names against the Flyo interface or the OpenAPI schema instead of guessing.
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
