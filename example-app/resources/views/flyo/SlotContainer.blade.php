<?php
/** @var \Flyo\Model\Block $block */
$slotContainerContent = $block->getSlots()['content'] ?? null;
?>
<div style="border:1px solid #000; margin:20px; padding:20px; border-radius:15px;">
    <div style="font-weight:bold; margin-bottom:10px;">Slot Container:</div>
    @if($slotContainerContent)
    <x-flyo::slot :container=$slotContainerContent />
    @endif
</div>
