<?php
/** @var \Flyo\Model\Block $block */
?>
<div>
    <?php foreach ($block->getItems() as $item): ?>
        <a href="<?= $item->link->routes->detail; ?>">
            <h1><?= $item->title; ?></h1>
            <h2><?= $item->subtitle; ?></h2>
            <img width="300" src="<?= $item->image->source; ?>" alt="<?= $item->title; ?>">
        </a>
    <?php endforeach; ?>
</div>
