<?php foreach ($page->links(1) as $link): ?><?= $link->gap ? '…' : ($link->current ? "[{$link->number}]" : "<a href=\"" . e($link->url) . "\">{$link->number}</a>") ?> <?php endforeach ?>
| <?= implode(',', array_column($data, 'n')) ?>
