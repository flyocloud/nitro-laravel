# Flyo Nitro CMS integration advisory for an existing Laravel project

You are a coding agent working inside an existing Laravel project. Your goal is to integrate Flyo Nitro CMS using `flyo/nitro-laravel`.

Repository and documentation:

- Package repository: `https://github.com/flyocloud/nitro-laravel`
- Developer README: `https://github.com/flyocloud/nitro-laravel/blob/main/README.md`
- Upgrade guide: `https://github.com/flyocloud/nitro-laravel/blob/main/UPGRADE.md`

## Important constraints

The package requires **PHP 8.3+** and **Laravel 11 or 12**, and it renders with **Blade**.

The integration is **server rendered on every request**. The service provider asks the Flyo API for the site configuration during `boot()` and registers one route per CMS page from that response. There is no build step, no static export and no generated route file. Two things follow from that:

- The CMS routes exist only in an HTTP request context. The provider skips all of this when `runningInConsole()` is true, so `php artisan route:list` does **not** show the CMS pages. That is expected and not a broken setup, but it also means `php artisan route:cache` must not be used (see the routing step).
- Every request performs a config API call before the page is rendered. Keep the cache headers configured (see the caching step) and never ship a develop token to production.

Before changing files, check `routes/web.php`. A default `Route::get('/', ...)` welcome route, and any other route whose uri collides with a CMS page slug, has to go: the CMS route of that path wins and the hand written one silently stops answering (see the routing step for why). Ask the user before deleting a route which looks intentional.

Laravel conventions this advisory follows:

```
config/flyo.php                                     # published package config
routes/web.php                                      # entity detail routes only, no page routes
resources/views/cms.blade.php                       # entry view of every CMS page
resources/views/components/layout.blade.php         # base layout, <x-layout>
resources/views/components/layout/header.blade.php  # neutral layout components
resources/views/components/layout/footer.blade.php
resources/views/components/wysiwyg.blade.php        # project WYSIWYG wrapper
resources/views/components/flyo-image.blade.php     # optional image helper
resources/views/flyo/Hero.blade.php                 # Flyo block views (views_namespace)
resources/views/flyo/Text.blade.php
```

Block views live in the directory the `views_namespace` config points at (`resources/views/flyo` by default). **The file name is the registration.** `Flyo\Laravel\Components\Block` resolves a block by looking up `<views_namespace>.<component>`, so a Flyo component named `Hero` renders `resources/views/flyo/Hero.blade.php`. There is no component map to maintain anywhere, and the name is case sensitive.

**There is no type generation for PHP.** Unlike the Next.js and Astro integrations, there is no `flyo:types` step and no generated `flyo.ts`: a block's `content`, `config` and `items` arrive as plain `stdClass` / arrays. Field names are discovered from the Flyo interface instead (see the field discovery step), and every CMS field access must be written defensively.

Do not hardcode secrets into source files. The Flyo token belongs in `.env`.

Prefer small, clean, reusable Blade components, and keep the existing code style of the project (run the project's Pint config if it has one).

## First interaction with the user

Before implementing, ask the user for the following required information.

### 1. Flyo Nitro access token

Ask for the Flyo access token that should be used for this project.

Store it in `.env` as:

```
FLYO_TOKEN=<develop-token>
FLYO_LIVE_EDIT=true
```

For production, the environment should contain:

```
FLYO_TOKEN=<production-token>
FLYO_LIVE_EDIT=false
```

A develop token starts with `d-`, a production token with `p-`; `<x-flyo::debug-info />` prints which kind is in use. Add the same two keys with placeholder values to `.env.example` so the next developer knows they exist, and never commit a real token.

`APP_URL` should point at the real domain in production. The canonical link and the sitemap are built from the incoming request root, so a site behind a proxy or load balancer also needs Laravel's trusted proxy handling configured (`->withMiddleware(fn ($m) => $m->trustProxies(at: '*'))` in `bootstrap/app.php`, adjusted to the real infrastructure), otherwise those urls come out as `http://` or with an internal host.

### 2. Available Flyo container identifiers

Ask which Flyo config containers exist and should be used in the layout.

Common examples:

```
nav
navbar
navigation
main_navigation
footer
```

Ask the user specifically:

```
Which Flyo container identifier should be used for the main navigation?
Which Flyo container identifier should be used for the footer?
```

Use those identifiers in the `Header` and `Footer` components.

The components should not be named `FlyoHeader` or `FlyoFooter`, because they are regular layout components. Use neutral layout names:

```
resources/views/components/layout/header.blade.php   =>  <x-layout.header />
resources/views/components/layout/footer.blade.php   =>  <x-layout.footer />
```

### 3. Homepage ownership

Ask whether the homepage should come from Flyo. If yes, remove the default welcome route (see implementation step 3), it never answers anyway. If the project has to keep a hand built homepage, tell the user that a Flyo page with the same slug takes precedence over it, so that page has to be renamed or taken offline in the CMS.

### 4. Entities and languages

Ask both up front, they change the routing:

```
Does the site have entity detail pages (blog posts, products, locations, ...)?
If so, which route prefixes and entity type ids do they use?
Is the site multilingual? If so, what is the primary language and which locales are used (e.g. de, en)?
```

## Implementation steps

### 1. Install the package

```sh
composer require flyo/nitro-laravel
```

The package registers its service provider through Composer's `extra.laravel.providers`, so there is nothing to add to `bootstrap/providers.php`.

### 2. Publish the config and the entry view

```sh
php artisan vendor:publish --provider="Flyo\Laravel\ServiceProvider"
```

This writes two files:

```
config/flyo.php                 # the package configuration
resources/views/cms.blade.php   # the entry view of every CMS page
```

The published file documents every key inline. Set the token and the live edit flag through the environment and leave the rest at its defaults for now. These are the keys that matter, this is an excerpt for orientation and not a replacement for the published file:

```php
// config/flyo.php (excerpt)
return [
    'token' => env('FLYO_TOKEN', 'ADD_PRODUCTION_TOKEN_HERE'),
    'live_edit' => env('FLYO_LIVE_EDIT', false),
    'views_namespace' => 'flyo',
    'server_cache_ttl' => env('FLYO_SERVER_CACHE_TTL', 900),
    'client_cache_ttl' => env('FLYO_CLIENT_CACHE_TTL', 1200),
    'default_route' => env('FLYO_DEFAULT_ROUTE', 'detail'),
    'locales' => [],
];
```

Notes to respect:

- The token is **enforced**: a missing `flyo.token` throws a `RuntimeException` during boot of every web request. That is intentional.
- `live_edit` is what makes blocks clickable in the Flyo editor preview. It must be `false` in production, where it would also disable all response caching.
- Do not rename `views_namespace` unless the user asks for it. It decides where block views are looked up.
- If the project caches its config (`php artisan config:cache`), run `php artisan config:clear` after editing `config/flyo.php` or the `.env`.

### 3. Remove conflicting routes

Open `routes/web.php` and remove the routes which collide with CMS pages, in a fresh Laravel install that is the welcome route:

```php
// remove this, the Flyo homepage takes over /
Route::get('/', function () {
    return view('welcome');
});
```

Keep everything the application really needs (auth routes, api endpoints, entity detail routes). Only page paths which Flyo serves have to be free.

**Which one wins matters and it is not the obvious one.** The package registers the CMS routes while service providers boot, and Laravel loads `routes/web.php` afterwards, in a `booted` callback. The router returns the first registered match, so a **CMS page route wins over a route of the same uri in `routes/web.php`**. A leftover welcome route is therefore dead code, and a hand written route whose uri collides with a CMS page slug silently stops answering. Check for collisions before blaming the route.

**Do not run `php artisan route:cache` on a project using this package.** The CMS routes only exist inside an HTTP request, so a console run never sees them, and loading a cached route file replaces the whole route collection at runtime, which drops the CMS routes that were just registered. Remove the command from deploy scripts and CI (`config:cache`, `view:cache` and `event:cache` are fine), and if the project has cached routes already, clear them:

```sh
php artisan route:clear
```

### 4. Create neutral layout `Header` and `Footer` components

The service provider shares the Flyo config response with every view as `$config`, so the components need no props. Use the container identifiers the user gave you.

Create `resources/views/components/layout/header.blade.php`:

```blade
<?php
/** @var \Flyo\Model\ConfigResponse $config */
$container = $config->getContainers()['nav'] ?? null;
$items = $container ? $container->getItems() : [];
$currentPath = '/'.ltrim(request()->path(), '/');
?>
@if (! empty($items))
    <header>
        <nav aria-label="Main navigation">
            <ul>
                @foreach ($items as $item)
                    <li>
                        <a
                            href="{{ $item->getHref() }}"
                            @if ($item->getTarget()) target="{{ $item->getTarget() }}" @endif
                            @if ($item->getHref() === $currentPath) aria-current="page" @endif
                        >{{ $item->getLabel() }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </header>
@endif
```

Create `resources/views/components/layout/footer.blade.php`:

```blade
<?php
/** @var \Flyo\Model\ConfigResponse $config */
$container = $config->getContainers()['footer'] ?? null;
$items = $container ? $container->getItems() : [];
?>
@if (! empty($items))
    <footer>
        <nav aria-label="Footer navigation">
            <ul>
                @foreach ($items as $item)
                    <li>
                        <a href="{{ $item->getHref() }}" @if ($item->getTarget()) target="{{ $item->getTarget() }}" @endif>
                            {{ $item->getLabel() }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </footer>
@endif
```

Replace `'nav'` and `'footer'` with the actual identifiers provided by the user.

A container item is a `Flyo\Model\ContainerPage` with `getLabel()`, `getHref()`, `getSlug()`, `getType()`, `getTarget()`, `getProperties()` and `getChildren()`. Render `getChildren()` recursively for a multi level menu. If the returned structure differs from this example, inspect the config response (`dd($config->getContainers())`) and adapt the rendering safely.

The whole config response is also resolvable outside of views:

```php
/** @var \Flyo\Model\ConfigResponse $config */
$config = app(\Flyo\Model\ConfigResponse::class);
```

### 5. Update the base layout

Update (or create) the base layout, `resources/views/components/layout.blade.php` for a `<x-layout>` component.

The layout must contain three Flyo specific things:

1. `<x-flyo::head />` inside `<head>`
2. `Header` and `Footer`
3. `<x-flyo::debug-info />` at the end of `<body>`

```blade
<?php
/** @var \Flyo\Model\ConfigResponse $config */
?>
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <x-flyo::head />
        {{-- keep the project's own @vite entry points, drop this line if it has none --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <x-layout.header />
        <main>
            {{ $slot }}
        </main>
        <x-layout.footer />

        {{-- Emits cms version, environment and token type as an html comment, useful in production. --}}
        <x-flyo::debug-info />
    </body>
</html>
```

Preserve everything the existing layout already has: fonts, `@vite` entry points, global styles, analytics, body classes and existing slots. Do not overwrite the layout, merge the Flyo parts into it.

**Do not keep a hardcoded `<title>` in the layout.** `<x-flyo::head />` renders the title of the current page plus its description, Open Graph and Twitter tags, the canonical link, the `og:site_name` from `config('app.name')`, a `robots` noindex tag when the API flags the document as not indexable, and the schema.org JSON-LD of the page as `application/ld+json`. A second `<title>` in the layout would duplicate it.

`<x-flyo::head />` is also what loads the live edit javascript, so a layout without it leaves `@editable($block)` markers inert. Every route the site renders should use this layout, including hand written ones.

A hand written route which is not a Flyo page has no meta data assigned, so it renders no title at all. Assign it in the controller before returning the view:

```php
use Flyo\Laravel\Components\Head;

Head::metaTitle('Contact');
Head::metaDescription('How to reach us.');
```

### 6. Wire the CMS entry view

`resources/views/cms.blade.php` is the view every CMS page route renders. The published version has the layout commented out, wrap it in the project layout:

```blade
<?php
/** @var \Flyo\Model\Page $page */
?>
<x-layout>
    <x-flyo::page :page=$page />
</x-layout>
```

`<x-flyo::page />` iterates `$page->getJson()` and renders one `<x-flyo::block />` per block, which resolves the block view by component name. Nothing else belongs in this file: page specific markup belongs into blocks.

The page response itself is available as `$page` here and resolvable anywhere in the request:

```php
/** @var \Flyo\Model\Page $page */
$page = app(\Flyo\Model\Page::class);
```

It carries `getTitle()`, `getHref()`, `getSlug()`, `getJson()` (the blocks), `getIsHome()`, `getProperties()`, `getBreadcrumb()`, `getTranslation()` and `getMetaJson()`. The binding only exists on a CMS page route, so guard it elsewhere with `app()->bound(\Flyo\Model\Page::class)`.

### 7. Prepare a project WYSIWYG wrapper

Most Flyo Nitro projects use WYSIWYG fields. `Flyo\Bridge\Wysiwyg` renders the ProseMirror JSON into html. Wrap it once in the project so custom nodes, marks and classes live in a single place, even if there are none yet.

Create `resources/views/components/wysiwyg.blade.php`:

```blade
@props(['json' => null])
<?php
/**
 * Custom nodes and marks are added through the callback below, the classes to import there are
 * Nadar\ProseMirror\Node, NodeType, Mark and MarkType.
 */
$html = $json === null ? '' : \Flyo\Bridge\Wysiwyg::render($json, function (\Flyo\Bridge\Wysiwyg $parser) {
    // $parser->replaceNode(NodeType::image, fn (Node $node) => '<img class="w-full" src="'.$node->getAttr('src').'" alt="'.$node->getAttr('alt').'">');
    // $parser->addNode('box', fn (Node $node) => '<div class="box">'.nl2br($node->getAttr('text')).'</div>');
    // $parser->replaceMark(MarkType::bold, fn (Mark $mark, string $text) => '<span class="font-bold">'.$text.'</span>');
});
?>
@if ($html !== '')
    <div {{ $attributes->merge(['class' => 'wysiwyg']) }}>{!! $html !!}</div>
@endif
```

Use it in block views wherever a block carries WYSIWYG JSON:

```blade
<x-wysiwyg :json="$block->getContent()->text->json ?? null" />
```

The parser already renders an image node out of the box and understands a `src` delivered as a Flyo image object. The rendered html is trusted CMS output, hence `{!! !!}`; never pass user submitted content through this component.

### 8. Images

Flyo images are delivered through the Flyo Storage CDN and transformed with query parameters. `Flyo\Bridge\Image` builds those urls and the `<img>` tag:

```php
use Flyo\Bridge\Image;

Image::tag($src, $alt, 800, 600);                    // <img src="...?w=800&h=600&format=webp" ... />
Image::attributes($src, $alt, 800, 600);             // the attributes only
Image::source($src, 1200, 630, 'jpg');               // the url only
Image::fromObject($image, 800, 600)->toTag(['class' => 'w-full']);
```

`fromObject()` takes a Flyo image field object and uses its `caption` as the alt text, but it throws an `InvalidArgumentException` when the object has no `source`, so only call it on a field which is known to be filled.

Rules to follow:

- **Always pass width and height.** They drive the `?w=&h=` transformation and they are rendered as `width` / `height` attributes, which is what makes the built in `loading="lazy"` work without layout shift. A side left out stays dynamic and keeps the aspect ratio of the original.
- **Never build a CDN url by hand.** The current format is `{file}?w=300&h=300&format=webp`. The legacy `{file}/thumb/300x300` path is deprecated, and `{file}/filter/300x300` was removed on 06.08.2026 and answers with HTTP 404. `Image` migrates such a path when it finds one in a source, hand written urls do not get that.
- The output format defaults to `webp`, pass `'jpg'`, `'png'` or `'gif'` per image where a specific one is needed. The format is ignored by the storage service unless a width or height is given.
- An absolute url on another host, and a path starting with `/`, is passed through untouched, so local project assets keep working.
- CMS image fields can be empty, so guard the usage.

For readable block views, add a thin component. Create `resources/views/components/flyo-image.blade.php`:

```blade
@props(['image' => null, 'width' => null, 'height' => null, 'alt' => null, 'format' => 'webp', 'loading' => 'lazy'])
<?php
$source = $image->source ?? null;
$caption = $alt ?? ($image->caption ?? '');
?>
@if ($source)
    {!! \Flyo\Bridge\Image::tag($source, $caption, $width, $height, $format, $loading, 'async', $attributes->getAttributes()) !!}
@endif
```

Used as:

```blade
<x-flyo-image :image="$block->getContent()->image ?? null" :width="1600" :height="900" class="w-full rounded-lg" />
```

For responsive art direction use `Flyo\Bridge\Responsive`, which produces `srcset` and `sizes`:

```php
use Flyo\Bridge\Image;
use Flyo\Bridge\Responsive;

echo Image::tag(
    (new Responsive($src))
        ->add(500, Responsive::PX_OR_LESS, 500, 500)
        ->add(1000, Responsive::PX_OR_MORE, 1000, 1000),
    $alt,
    1000,
    1000
);
```

### 9. Discover the block fields (no code generation for PHP)

There is no generated type file for PHP, so before writing a block view you have to know its real field names. Use one of these, in this order:

1. **The Flyo interface** the user has in the CMS. Ask them for the block's field identifiers if they are at hand.
2. **The OpenAPI schema of the project**, which is the authoritative list. Fetch it once and read the block definitions:

   ```sh
   curl -s 'https://api.flyo.cloud/nitro/v1/openapi/schemas?token=<the-flyo-token>' -o storage/app/flyo-schema.json
   ```

   Look for the schema of the block (a `Block<Name>` shaped definition) and read its `content`, `config` and `items` properties. Nothing writes this file into the repository: `storage/app` is git ignored in a standard Laravel project, and the token must not end up in a committed file or in a script.
3. **Dump the block** while the page renders, which is the fastest loop during development:

   ```blade
   <pre>{{ print_r($block->getContent(), true) }}</pre>
   <pre>{{ print_r($block->getItems(), true) }}</pre>
   <pre>{{ print_r($block->getSlots(), true) }}</pre>
   ```

What a block gives you:

```php
$block->getComponent();   // the Flyo component name, which is the view file name
$block->getIdentifier();  // the block identifier
$block->getUid();         // the uid the live edit marker uses
$block->getContent();     // stdClass, the block fields
$block->getConfig();      // stdClass, the block configuration
$block->getItems();       // array of stdClass, repeatable content
$block->getSlots();       // array<string, \Flyo\Model\BlockSlotValue>, nested blocks
```

`content`, `config` and `items` are untyped, so **every field access is written defensively**: `$content->title ?? ''`, `! empty($content->image->source)`, `$content->text->json ?? null`. `??` on a chained property access is null safe, so `$content->image->source ?? null` does not warn when `image` is missing or null. Record the fields you found in a docblock at the top of the block view, that is the closest thing this stack has to a generated type.

### 10. Create a reusable Claude skill for building a named Flyo block

Do not manually add a full block convention section to the advisory only. Instead, create a reusable Claude skill that future agents can use to build or update **one named Flyo block at a time**, driven by a design brief or by an existing Blade view that should be converted into a block.

This skill is invoked with a block **name** and a **design intent**, for example:

```
Use the flyo-block skill. Block: Hero. Create a decent-looking, responsive hero block based on the hero design.
```

```
Use the flyo-block skill. Block: Hero. Convert the existing hero-banner partial into a Flyo hero block and keep its look and feel.
```

```
Use the flyo-block skill. Block: Teaser. Update the existing Teaser block to match the new card design.
```

The skill is therefore not a bulk generator. It focuses on translating a design (a brief, a screenshot, or an existing Blade view) into a single, well crafted Flyo block view.

Create:

```
.claude/skills/flyo-block/SKILL.md
```

Use this content:

````
---
name: flyo-block
description: Create or update a single named Flyo Nitro CMS block view for this Laravel Blade project, driven by a design brief or by converting an existing Blade view into a block. Use when the user names a block (e.g. "Hero") and describes how it should look or points to an existing view to base it on.
---

# Flyo block builder skill

Use this skill to create or update **one named Flyo Nitro CMS block** at a time.

This skill is design driven. It is invoked with:

- a block **name** (for example `Hero`, `Teaser`, `Gallery`), and
- a **design intent**, which is one of:
  - a written design brief ("a decent-looking, responsive hero with headline, lead text and a CTA"),
  - a reference to an existing Blade view or partial to convert into a block,
  - a visual reference (screenshot / mockup) the user provides.

The goal is to translate that design intent into a single, polished Flyo block view.

## Project context

This project uses:

```txt
Laravel with Blade
flyo/nitro-laravel
```

Block views live in the directory the `views_namespace` config points at:

```txt
resources/views/flyo
```

**The file name is the registration.** A Flyo component named `Hero` renders `resources/views/flyo/Hero.blade.php`, case sensitive. There is no component map to update anywhere. When the view is missing, the block renders a red hint box while `APP_DEBUG` is on and nothing at all in production.

Shared helpers available in this project:

```txt
resources/views/components/wysiwyg.blade.php      <x-wysiwyg :json="..." />
resources/views/components/flyo-image.blade.php   <x-flyo-image :image="..." :width="..." :height="..." />
Flyo\Bridge\Image                                 image urls, tags and srcset
Flyo\Bridge\Wysiwyg                               ProseMirror JSON to html
```

## Inputs to resolve first

Before writing code, make sure you know:

1. The **block name** the user wants, which is the view file name and the Flyo component name.
2. Whether this is a **create** (no view yet) or an **update** (`resources/views/flyo/<Name>.blade.php` exists).
3. The **design source**: a brief, an existing view to convert or match, or a visual reference.
4. The block's **real field names**.

There is no generated type file in a PHP project, so resolve the fields before inventing any:

- ask the user for the field identifiers of the block in the Flyo interface, or
- read the block definition in the OpenAPI schema:
  `curl -s 'https://api.flyo.cloud/nitro/v1/openapi/schemas?token=<the-flyo-token>' -o storage/app/flyo-schema.json`, or
- dump the block while the page renders: `<pre>{{ print_r($block->getContent(), true) }}</pre>`.

If you cannot confirm the fields, ask the user instead of guessing. Do not invent field names.

## Main task

When asked to build a named block:

1. Resolve the block's real fields (see above) and note them in a docblock at the top of the view.
2. If converting an existing view, read it fully and note its markup, styling approach and layout.
3. Map the design's visual pieces (heading, text, image, buttons, background, layout) onto the block's real CMS fields.
4. Create or update `resources/views/flyo/<Name>.blade.php`.
5. Implement the design faithfully: responsive layout, sensible spacing, and the project's existing design system where one exists.
6. Put `@editable($block)` on the outermost element of the block.
7. Use `<x-wysiwyg />` for WYSIWYG JSON fields.
8. Use `<x-flyo-image />` (or `Flyo\Bridge\Image`) for image fields, always with width and height.
9. Use `<x-flyo::slot :container=$slot />` for nested slot rendering.
10. Keep the implementation focused on the single named block, do not generate unrelated blocks.

## Converting an existing view into a block

When the user points to an existing view or partial:

1. Read it and preserve its look and feel (class names, layout, spacing, variants).
2. Replace its hardcoded or prop driven values with the block's CMS fields.
3. Keep the original styling and structure, only swap the data source and add the Flyo wiring (`@editable`, WYSIWYG, images, slots).
4. If the original should stay a presentational component, keep it and have the block pass CMS values into it, whichever keeps the design intact with the least duplication.

## Design guidance

- Match the requested design intent, not a generic template. If the user asks for a "decent-looking" layout, produce a genuinely polished, responsive result.
- Reuse the project's existing components, typography helpers, buttons and layout primitives if they already exist.
- Only introduce new styling when the project has no clear design system to follow, and keep it consistent with what already exists.
- Keep the block responsive and accessible (semantic elements, alt text, focusable controls).

## Important rules

Every CMS field can be empty. Guard every access with `??` or `! empty()`, never assume a field is set.

Do not fetch CMS data inside a block view. A block renders the `$block` it receives. Data which belongs to the whole page belongs into the layout or a controller.

`@editable($block)` must sit on the outermost element of the block, otherwise the editor cannot place the edit icon next to it. Without it the block is not clickable in the Flyo editor. It renders nothing when live edit is disabled, so it is safe in production.

Escape CMS strings with `{{ }}`. Only the WYSIWYG html and the image tag helper are printed raw.

Keep the block view readable and scoped to the one named block.

## Basic block pattern

```blade
<?php
/**
 * @var \Flyo\Model\Block $block
 *
 * content: title (string), teaser (string), image (object: source, caption)
 */
$content = $block->getContent();
?>
<section @editable($block) class="hero">
    @if (! empty($content->title))
        <h1>{{ $content->title }}</h1>
    @endif

    @if (! empty($content->teaser))
        <p>{{ $content->teaser }}</p>
    @endif

    <x-flyo-image :image="$content->image ?? null" :width="1600" :height="900" class="w-full" />
</section>
```

## WYSIWYG usage

```blade
<x-wysiwyg :json="$block->getContent()->text->json ?? null" class="prose" />
```

## Items (repeatable content)

```blade
<?php
/** @var \Flyo\Model\Block $block */
?>
<div @editable($block) class="grid gap-6 md:grid-cols-3">
    @foreach ($block->getItems() as $item)
        <article>
            <h3>{{ $item->title ?? '' }}</h3>

            @if (! empty($item->link->routes->detail))
                <a href="{{ $item->link->routes->detail }}">{{ __('Details') }}</a>
            @endif

            <x-flyo-image :image="$item->image ?? null" :width="600" :height="400" />
        </article>
    @endforeach
</div>
```

An item's `link->routes` map holds the routes the API resolved for the linked entity, keyed by route name (`detail` by default, see the `default_route` config). It carries `_empty` instead when no route could be resolved, so always check before linking.

## Block pattern with slots

```blade
<?php
/** @var \Flyo\Model\Block $block */
$slotContent = $block->getSlots()['content'] ?? null;
?>
<section @editable($block) class="container">
    @if ($slotContent)
        <x-flyo::slot :container=$slotContent />
    @endif
</section>
```

The slot key (`content` above) must match the slot identifier defined in the Flyo interface. `<x-flyo::slot />` renders every nested block through the same component lookup, so a nested block needs its own view in `resources/views/flyo` as well.

## Final checklist after building the block

- The view exists at `resources/views/flyo/<Name>.blade.php` and the name matches the Flyo component exactly.
- The fields used are the real ones, and they are documented in the docblock.
- The design intent is faithfully implemented and responsive.
- If converting an existing view, its look and feel is preserved.
- `@editable($block)` sits on the outermost element.
- WYSIWYG fields use `<x-wysiwyg />`, images use `<x-flyo-image />` with width and height.
- Slot rendering uses `<x-flyo::slot />`.
- Every CMS field access is guarded.
- The page renders without errors and the block is clickable in the editor preview.
- `./vendor/bin/pint --dirty` passes, if the project uses Pint.
````

This replaces a manual "block view convention" section in the main setup flow. Building a named block from a design (or from an existing view) is now handled by the reusable Claude skill.

### 11. Entity detail routes and draft links

If the site has entity detail pages, register one route per entity type. The generic `EntityController` of the package resolves the entity, assigns its meta data and hands it to a view:

```php
// routes/web.php
use Flyo\Api\EntitiesApi;
use Flyo\Laravel\Controllers\EntityController;
use Illuminate\Support\Facades\Route;

Route::get('/tier/{slug}', function (string $slug) {
    return app(EntityController::class)
        ->resolve(fn (EntitiesApi $api, $param) => $api->entityBySlug($param))
        ->render($slug, 'tier');
});
```

Variants:

```php
// by slug, restricted to one entity type id
->resolve(fn (EntitiesApi $api, $param) => $api->entityBySlug($param, 116))

// by unique id
->resolve(fn (EntitiesApi $api, $param) => $api->entityByUniqueid($param))

// with custom error handling instead of the default 404
->onException(fn ($exception, $param, $view) => response()->view('errors.entity', ['error' => $exception->getMessage()], 500))
```

The view receives `$entity` (the `EntityInterface` with `getEntityTitle()`, `getEntityTeaser()`, `getEntityImage()`, `getRoutes()`, ...), `$model` (the entity's own fields as `stdClass`), `$translation`, `$breadcrumb`, `$isDraft` and `$draftExpiresAt`:

```blade
<?php
/** @var \Flyo\Model\EntityInterface $entity */
/** @var object $model */
/** @var \Flyo\Model\Translation[] $translation */
/** @var \Flyo\Model\Breadcrumb[] $breadcrumb */
/** @var bool $isDraft */
/** @var int|null $draftExpiresAt */
?>
<x-layout>
    @if ($isDraft)
        <p role="status">
            Draft preview, this page is not online.
            @if ($draftExpiresAt)
                The link expires {{ \Carbon\Carbon::createFromTimestamp($draftExpiresAt)->diffForHumans() }}.
            @endif
        </p>
    @endif

    <h1>{{ $entity->getEntityTitle() }}</h1>
    <x-flyo-image :image="$model->image ?? null" :width="1200" :height="675" />
</x-layout>
```

A **custom controller** which resolves an entity itself has to assign the meta data, which also flags a draft:

```php
use Flyo\Laravel\Components\Head;

$entity = (new EntitiesApi(null, $config))->entityBySlug($slug);

Head::metaEntity($entity);   // meta data, canonical, json-ld, noindex and draft detection
```

If the controller does not use the head component, flag the draft explicitly, otherwise a draft response of that route can end up in a cache:

```php
Flyo\Laravel\DraftMode::detect($entity);
```

**Draft links.** A draft link is a shareable, expiring snapshot of an entity which is still offline in Flyo. It arrives as an opaque token in place of the slug or the unique id, so it lands on the entity route the project already has, and the response carries `is_draft` plus a `draft_expires_at` timestamp. Two rules keep it working, and both are easy to break:

1. **Do not send an entity type id** on a route which has to serve draft links. The type filter does not apply to a token, so resolve without it.
2. **Do not validate the route parameter** against a slug or id pattern. A `->where('slug', '[a-z0-9-]+')` gate rejects the token before the API is ever asked.

Caching is handled by the package. `Flyo\Laravel\Middleware\PreventDraftCaching` is registered as the outermost global middleware, so a draft response is uncacheable no matter which route rendered it:

```
Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private
CDN-Cache-Control: no-store
Vercel-CDN-Cache-Control: no-store
Surrogate-Control: no-store
X-Robots-Tag: noindex, nofollow
```

`ETag` and `Last-Modified` are dropped as well and the ttl config is ignored for such a response. Read the state anywhere with `Flyo\Laravel\DraftMode::isDraft()` and `Flyo\Laravel\DraftMode::expiresAt()`.

### 12. Sitemap

**Nothing to create.** The service provider registers `/sitemap.xml` and the package controller renders every page and entity the API resolved a url for, each with a `<lastmod>` from its `updated_at` timestamp.

The locations are absolute urls built from the incoming request root, so there is no `site` setting to configure, but a site behind a proxy needs Laravel's trusted proxy handling (see the token step) to get the right scheme and host.

Do **not** add a sitemap package for Flyo content, it does not see the CMS routes. If the project already serves a sitemap of its own at `/sitemap.xml`, only one of the two can win: tell the user and agree on which, or move the other one to a different path.

### 13. Cache headers

`Flyo\Laravel\Middleware\CachingHeaders` is applied to the CMS page routes and the sitemap. It writes:

```
Cache-Control: max-age={client_cache_ttl}                 # the browser
CDN-Cache-Control: max-age={server_cache_ttl}             # the cdn
Vercel-CDN-Cache-Control: max-age={server_cache_ttl}
```

Set `server_cache_ttl` or `client_cache_ttl` to `0` to opt out of that layer. Either of two conditions switches the caching off entirely, which is worth knowing before debugging a "cache header is missing" report:

- `flyo.live_edit` is enabled (the editor preview must never be cached), or
- `APP_DEBUG` is on.

A non successful response is not cached either.

With `APP_DEBUG` on, the middleware sends `Flyo-Live-Edit` and `Flyo-Draft` debug headers instead, which is a quick way to verify the setup with `curl -I`.

Add the middleware to a hand written route which serves CMS content and should be cached the same way:

```php
Route::get('/tier/{slug}', $handler)->middleware(\Flyo\Laravel\Middleware\CachingHeaders::class);
```

### 14. Optional: Multilanguage (i18n)

If the Flyo project is single language, skip this step. For a multilingual project:

1. Declare the locales in `config/flyo.php`, exactly as they are defined in the Flyo interface:

```php
'locales' => [
    'de',
    'en',
],
```

2. Nothing to change in the page routing. The service provider reads the first url segment and, when it is one of the configured locales, sets it as the Laravel locale before the config and page requests go out. The API then answers in that language, and because Flyo page slugs are locale prefixed and globally unique, every localized page is registered as its own route.

   Two details to respect: the segment detection only kicks in when **more than one** locale is configured, and `APP_LOCALE` is what is used for a request without a locale prefix.

3. Nothing to change in the layout or the container components either. `$config` is already resolved in the active locale, so the navigation comes back translated. The resolved language is also readable from the response:

```blade
<html lang="{{ $config->getNitro()?->getLanguage() ?? str_replace('_', '-', app()->getLocale()) }}">
```

4. Entity detail routes must pass the language explicitly, because an entity slug is shared across languages:

```php
use Illuminate\Support\Facades\App;

Route::get('{locale}/ort/{slug}', function (string $locale, string $slug) {
    App::setLocale($locale);

    return app(EntityController::class)
        ->resolve(fn (EntitiesApi $api, $param) => $api->entityBySlug($param, 245, $locale))
        ->render($slug, 'poi');
})->where('locale', '[a-z]{2}')->name('poi');
```

Note that the pattern constrains the **locale** segment, never the slug segment, so draft links keep working.

5. Language switcher. There is no built in switcher component, and none is needed: Laravel renders everything on the server, so plain markup from the `translation[]` array of the current document is enough. A `Flyo\Model\Translation` carries `getLanguage()` (with `getShortcode()` and `getName()`), `getSlug()`, `getTitle()` and a fully resolved `getHref()`.

   A CMS page route binds the page response into the container, so the layout can read the translations without being handed anything, and the `bound()` check keeps it working on a hand written route. Declare the prop in the layout component so an entity page can pass its own translations in, and fall back to the bound page:

```blade
{{-- resources/views/components/layout.blade.php --}}
@props(['translations' => null])
<?php
/** @var \Flyo\Model\Translation[] $translations */
$translations = $translations
    ?: (app()->bound(\Flyo\Model\Page::class) ? app(\Flyo\Model\Page::class)->getTranslation() : []);
?>
@if (! empty($translations))
    <nav aria-label="Language">
        <ul>
            @foreach ($translations as $translation)
                <li>
                    <a
                        href="{{ $translation->getHref() }}"
                        hreflang="{{ $translation->getLanguage()->getShortcode() }}"
                        @if ($translation->getLanguage()->getShortcode() === app()->getLocale()) aria-current="true" @endif
                    >{{ $translation->getLanguage()->getName() }}</a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
```

   An entity page feeds the same switcher from the `$translation` variable the `EntityController` passes to the view, so hand it into the layout: `<x-layout :translations="$translation">`. A hand written route with no translations should fall back to a static list of the language home pages.

### 15. Long running workers (Octane)

The head and draft state is static per request. `PreventDraftCaching` resets the draft state at the start of every request, but the head state (`Flyo\Laravel\Components\Head::$metas`, `$jsonLd`, `$scripts`) is not reset by the package. Under php-fpm that is a non issue, one process serves one request. If the project runs **Laravel Octane** or another long running worker, verify that meta data and head scripts do not leak from one request into the next, and flush them in an Octane `RequestReceived` listener if they do. Do not introduce Octane as part of this integration.

### 16. Create or update `AGENTS.md` so future agents have Flyo context

So that any AI coding agent that works on this project later (Claude Code, Copilot, Cursor, etc.) automatically knows it is built on Flyo Nitro CMS and where to read the full library documentation, create (or update, if one already exists) an `AGENTS.md` file at the **project root**.

`AGENTS.md` is the vendor neutral convention that most coding agents read on startup. If the project already uses a tool specific memory file such as `CLAUDE.md`, add the same Flyo section there as well (or have that file point at `AGENTS.md`). This mirrors the example `AGENTS.md` in the Flyo Nitro README: <https://github.com/flyocloud/nitro-laravel/blob/main/README.md#example-agentsmd>.

Add a Flyo section that **self references this library's documentation**, so the agent can pull in the full integration context (usage, API reference and this advisory) on demand while coding against the Flyo Nitro CMS library:

```markdown
# Flyo Nitro CMS

This project uses the **Flyo Nitro** headless CMS via `flyo/nitro-laravel` to manage its content. Pages are composed of CMS-driven blocks, plus entities and containers, rendered with Laravel Blade.

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

If an `AGENTS.md` already exists, **merge** this Flyo section into it rather than overwriting the file, preserve any existing project instructions.

### 17. Validation checklist

After implementation, run:

```sh
php artisan config:clear
php artisan view:clear
./vendor/bin/pint --dirty     # if the project uses Pint
php artisan serve
```

Then open the site once and confirm that a CMS page renders, that `/sitemap.xml` returns XML, and that the head contains the title and the JSON-LD of the page.

Verify:

```
.env contains FLYO_TOKEN and FLYO_LIVE_EDIT, and .env is git-ignored
.env.example lists both keys with placeholder values
config/flyo.php is published and the token resolves from the environment
routes/web.php has no route colliding with a CMS page slug (the default welcome route is gone)
No deploy script or CI step runs php artisan route:cache, and no cached route file is present
resources/views/cms.blade.php wraps <x-flyo::page /> in the project layout
The layout renders <x-flyo::head /> in the head and <x-flyo::debug-info /> at the end of the body
The layout has no hardcoded <title>
resources/views/components/layout/header.blade.php exists
resources/views/components/layout/footer.blade.php exists
Header and Footer use the user-provided Flyo container identifiers
resources/views/components/wysiwyg.blade.php exists
resources/views/components/flyo-image.blade.php exists (or the project uses Flyo\Bridge\Image directly)
Every block used by the CMS has a view in resources/views/flyo with the exact component name
Every block view carries @editable($block) on its outermost element
Entity detail routes resolve without an entity type id and do not gate the route param behind a pattern (draft links)
/sitemap.xml returns the Flyo pages and entities
.claude/skills/flyo-block/SKILL.md exists
AGENTS.md exists at the project root and references the Flyo Nitro docs (github.com/flyocloud/nitro-laravel#usage and the raw ai-instructions-laravel.md)
The site renders without errors
```

Quick checks with curl, with `APP_DEBUG` on:

```sh
curl -sI http://localhost:8000/ | grep -i 'flyo-\|cache-control'
curl -s http://localhost:8000/sitemap.xml | head -5
```

If live edit does not connect, the preview stays a white screen or blocks are not clickable, check in this order:

1. `FLYO_LIVE_EDIT=true` and the config cache is cleared.
2. The layout renders `<x-flyo::head />`. It is what loads the bridge.
3. The block has `@editable($block)` on its outermost element (view the source and look for `data-flyo-uid`).
4. The bridge loaded at all. The boot script warns in the browser console when the CDN url is unreachable, and `flyo.live_edit_bridge_url` can pin an exact version or point at a self hosted build. The editor handshake needs `@flyo/nitro-js-bridge` >= 1.4.0, and >= 1.5.0 is recommended.

## Follow-up workflow after setup

After the base integration is complete, the next step is to build real Flyo block views from your designs, one named block at a time.

Use the created Claude skill:

```
.claude/skills/flyo-block/SKILL.md
```

Then ask the agent per block, providing a name and a design intent. Examples:

```
Use the flyo-block skill. Block: Hero. Create a decent-looking, responsive hero block based on the hero design.
```

```
Use the flyo-block skill. Block: Hero. Convert the existing hero-banner partial into a Flyo hero block and keep its look and feel.
```

```
Use the flyo-block skill. Block: Teaser. Update the existing Teaser block to match the new card design.
```
