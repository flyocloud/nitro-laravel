# Flyo Nitro Laravel Framework Module

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

To make the block editable (which means clicking in the block, will correctly add the block to the cms editor) you can use the following blade directive `@editable($block)`:

```blade
<?php
/** @var \Flyo\Model\Block $block */
?>
<div @editable($block) style="border:1px solid blue; padding:20px;">
    <?php print_r($block->getContent()); ?>
<div>
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


## Misc

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

## Documentation

[Read More in the Docs](https://dev.flyo.cloud/nitro/php)

## Package Development

1. Check the `example-app/.env` file to have a correct flyo token. 
2. Go to example-app and run `php artisan serve` to get the example app running.
