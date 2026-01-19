<?php
/** @var \Flyo\Model\Block $block */
?>
<div @editable($block)>
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; padding: 20px;">
        <?php foreach ($block->getItems() as $item): ?>
            <a href="<?= $item->link->routes->detail ?? '#notfound'; ?>" style="text-decoration: none; color: inherit; border: 1px solid #e0e0e0; border-radius: 8px; padding: 16px; transition: box-shadow 0.3s; display: block;">
                <h1 style="margin: 0 0 8px 0; font-size: 20px;"><?= $item->title; ?></h1>
                <h2 style="margin: 0 0 12px 0; font-size: 14px; color: #666;"><?= $item->subtitle ?? 'Not subtitle'; ?></h2>
                <div style="width: 100%; overflow: hidden; border-radius: 4px;">
                    <?= Flyo\Bridge\Image::tag($item->image->source, $item->title, 300, 300); ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
