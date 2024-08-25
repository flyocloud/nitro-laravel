<?php
/** @var \Flyo\Model\Block $block */
?>
<div>
    <?php foreach ($block->getItems() as $item): ?>
        <a href="<?= $item->link->routes->detail; ?>">
            <h1><?= $item->title; ?></h1>
            <h2><?= $item->subtitle; ?></h2>
            <?= Flyo\Bridge\Image::tag($item->image->source, $item->title, 300, 300); ?>
        </a>
    <?php endforeach; ?>
</div>
