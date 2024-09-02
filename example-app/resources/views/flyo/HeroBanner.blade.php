<?php
/** @var \Flyo\Model\Block $block */

use Flyo\Bridge\Image;
?>
<div @editable($block) style="border:1px solid grey; padding:20px;">
   <h1><?= $block->getContent()->title; ?></h1>
    <h2><?= $block->getContent()->teaser; ?></h2>
    <?= Image::tag($block->getContent()->image->source, $block->getContent()->title, 1200, 300); ?>
</div>
