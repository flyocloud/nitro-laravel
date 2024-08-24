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
<div @editable($block) style="border:1px solid gree; padding:20px;">
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
    </body>
</html>
```

## Documentation

[Read More in the Docs](https://dev.flyo.cloud/nitro/php)

## Package Development

1. Check the `example-app/.env` file to have a correct flyo token. 
2. Go to example-app and run `php artisan serve` to get the example app running.