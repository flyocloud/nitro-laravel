<?php
/** @var \Flyo\Model\Block $block */
$slotContainerFooBarName = $block->getSlots()['slotcontainername'];
?>
<div style="background-color: red;">
    <h1>Slot Container</h1>
    <x-flyo::slot :container=$slotContainerFooBarName />
</div>
