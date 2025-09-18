<?php
/** @var \Flyo\Model\Block $block */

use Flyo\Bridge\Wysiwyg;

?>
<div @editable($block) style="padding:20px;">
    <?= Wysiwyg::render($block->getContent()->content->json); ?>
</div>
