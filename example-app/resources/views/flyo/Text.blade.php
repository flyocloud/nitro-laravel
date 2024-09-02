<?php
/** @var \Flyo\Model\Block $block */

use Flyo\Bridge\Wysiwyg;

?>
<div @editable($block) style="border:1px solid green; padding:20px;">
    <?= Wysiwyg::render($block->getContent()->content->json); ?>
</div>
