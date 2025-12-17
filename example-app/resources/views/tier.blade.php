<?php
/** @var \Flyo\Model\Entity $entity */
?>
<x-layout>
    <h1><?= $entity->getModel()->image->source ?? ''; ?></h1>
</x-layout>
