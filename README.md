# Flyo Nitro Laravel Framework Module

```sh
composer require flyo/nitro-laravel
```

publish the config

```sh
artisan vendor:publish
```

Adjust the token in `config/flyo.php`

## Views

Add/Adjust the `cms.blade.php` view file in `resources/views`, this is where the cms page loader starts:

```php
<?php
/** @var \Flyo\Model\Page */
?>
<x-flyo::page :page=$page />
```

Now all component block views are looked up in `ressources/views/flyo`, for example if you have a Flyo Nitro component block with name Text the view file would be `ressources/views/flyo/Text.blade.php` utilizing the following variables:

```php
<?php
/** @var \Flyo\Model\Block $block */
print_r($block->getContent());
print_r($block->getConfig());
print_r($block->getItems());
print_r($block->getSlots());
?>
```

## Layout Variable

In order to build menus, the `$config` response from the api is a global available variable, for example this could be used in layout-components:

```php
/** @var \Flyo\Model\ConfigResponse $config */
<div>
    <?php foreach($config->getNav()->getItems() as $nav): ?>
        <a href="<?= $nav->getHref(); ?>"><?= $nav->getLabel(); ?></a>
    <?php endforeach; ?>
</div>
```