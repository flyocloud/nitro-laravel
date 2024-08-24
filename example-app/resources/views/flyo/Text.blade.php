<?php
/** @var \Flyo\Model\Block $block */
?>
<div @editable($block) style="border:1px solid gree; padding:20px;">
    <?= $block->getContent()->content->html; ?>
<div>
