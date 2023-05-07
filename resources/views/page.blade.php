<?php
/** @var Flyo\Model\Page $page */
?>
<?php foreach ($page->getJson() as $block): ?>
<x-flyo::block :block=$block />
<?php endforeach; ?>
