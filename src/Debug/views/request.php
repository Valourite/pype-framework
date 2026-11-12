<?php
/**
 * /_pype/requests/show: one recorded request, stage by stage.
 *
 * @var array<string, string> $links
 * @var array<string, mixed>  $record
 */
$title = $record['method'] . ' ' . $record['path'];
$current = 'Requests';
require __DIR__ . '/_head.php';
require __DIR__ . '/_trace_helpers.php';
$routeHref = $record['route'] === null || !isset($links['Routes']) ? null
    : $links['Routes'] . '/show?' . http_build_query(['m' => $record['route']['method'], 'p' => $record['route']['path']]);
?>
    <span class="grow"><a href="<?= $h($links['Requests']) ?>">← all requests</a></span>
</header>
<main>
    <h1><span class="method <?= $h((string) $record['method']) ?>"><?= $h((string) $record['method']) ?></span> <span class="mono"><?= $h((string) $record['path']) ?></span>
        <span class="method <?= $tone($record['status'] ?? null) ?>" style="margin-left: 8px;"><?= $h((string) ($record['status'] ?? '')) ?></span></h1>
    <p class="facts muted">
        <span><?= $h((string) $record['at']) ?></span>
        <span><?= $ms($record['ms']) ?> in all</span>
        <span><?= (int) $record['queries'] ?> <?= (int) $record['queries'] === 1 ? 'query' : 'queries' ?>, <?= $ms($record['queryMs']) ?></span>
        <?php if ($record['location'] ?? null) { ?><span>redirects to <code><?= $h((string) $record['location']) ?></code></span><?php } ?>
        <?php if ($record['route'] !== null) { ?><span>route <?php if ($routeHref !== null) { ?><a href="<?= $h($routeHref) ?>"><code><?= $h($record['route']['method'] . ' ' . $record['route']['path']) ?></code></a><?php } else { ?><code><?= $h($record['route']['method'] . ' ' . $record['route']['path']) ?></code><?php } ?></span><?php } else { ?><span>no route matched</span><?php } ?>
        <?php if (($record['requestId'] ?? null) && isset($links['Logs'])) { ?><span><a href="<?= $h($links['Logs'] . '?request=' . rawurlencode((string) $record['requestId'])) ?>">its log lines</a></span><?php } ?>
    </p>

<?php require __DIR__ . '/_trace.php'; ?>

    <h2>In the context at the end</h2>
    <p><?php foreach ($record['context'] as $class) { ?><span class="tag key" title="<?= $h((string) $class) ?>"><?= $h($short((string) $class)) ?></span> <?php } ?><?= $record['context'] === [] ? '<span class="muted">Nothing.</span>' : '' ?></p>

<?php foreach (['Route parameters' => $record['route']['params'] ?? [], 'Query' => $record['query'], 'Input' => $record['input'], 'Headers' => $record['headers']] as $label => $rows) { ?>
<?php if ($rows !== []) { ?>
    <h2><?= $h($label) ?></h2>
    <div class="panel"><?php $table($rows); ?></div>
<?php } ?>
<?php } ?>
</main>
</body>
</html>
