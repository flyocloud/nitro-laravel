<?php
/** @var Flyo\Model\BlockSlots $slotContainer */
?>
<?php foreach ($slotContainer->getContent() as $block): ?>
    <x-flyo::block :block=$block />
<?php endforeach; ?>
