# Laravel Module for Flyo Nitro CMS

```
composer require flyo/nitrocms-laravel
```

publish the config

```
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

Now all component block views are looked up in `ressources/views/flyo`, for example if you have a Flyo Nitro CMS component block with name Text the view file would be `ressources/views/flyo/Text.blade.php` utilizing the following variables:

```php
<?php
/** @var \Flyo\Model\Block $block */
print_r($block->getContent());
print_r($block->getConfig());
print_r($block->getItems());
print_r($block->getSlots());
?>
```