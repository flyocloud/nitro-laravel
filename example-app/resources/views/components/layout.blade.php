<?php
/** @var \Flyo\Model\ConfigResponse $config */
?>
<html>
    <head>
        <title>{{ $title ?? 'Flyo Nitro' }}</title>
    </head>
    <body>
        <ul>
            <?php foreach ($config->getContainers() as $container): ?>
                <li><?= $container->getLabel(); ?></li>
                <ul>
                    <?php foreach ($container->getItems() as $page): ?>
                        <li><a href="<?= $page->getHref(); ?>"><?= $page->getLabel(); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </ul>
        <hr/>
        {{ $slot }}
    </body>
</html>
