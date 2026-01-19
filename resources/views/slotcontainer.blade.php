<?php
/** @var Flyo\Model\BlockSlotValue $slotContainer */
?>
<?php foreach ($slotContainer->getContent() as $block): ?>
    <x-flyo::block :block=$block />
<?php endforeach; ?>
