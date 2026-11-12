<?php
/**
 * /_pype/requests: the requests the recorder kept, newest first.
 *
 * @var array<string, string>      $links
 * @var list<array<string, mixed>> $requests
 */
$title = 'Requests';
$current = 'Requests';
require __DIR__ . '/_head.php';
$tone = static fn (?int $status): string => match (true) {
    $status === null => 'muted', $status >= 500 => 'DELETE', $status >= 400 => 'PATCH', $status >= 300 => 'POST', default => 'GET',
};
?>
    <input type="search" id="q" placeholder="Filter: path, status, method…" autofocus>
    <span class="grow"><?= count($requests) ?> kept · <a href="">refresh</a></span>
</header>
<main>
<style>
    table { width: 100%; border-collapse: collapse; }
    td { padding: 7px 10px; border-bottom: 1px solid var(--line); vertical-align: baseline; white-space: nowrap; }
    td.path { white-space: normal; width: 100%; }
    td.num { text-align: right; font-variant-numeric: tabular-nums; }
    tr:last-child td { border-bottom: 0; }
    tr.row { cursor: pointer; }
    tr.row:hover { background: var(--hover); }
    td.path a { color: var(--text); font-family: var(--mono); font-size: 13px; }
</style>
<?php if ($requests === []) { ?>
    <p class="empty">Nothing recorded yet. Open a page of the app, then come back.</p>
<?php } else { ?>
    <div class="panel">
    <table>
<?php foreach ($requests as $r) {
    $href = $links['Requests'] . '/show?id=' . rawurlencode((string) $r['id']);
    $query = ($r['query'] ?? []) === [] ? '' : '?' . http_build_query($r['query']);
?>
        <tr class="row" data-href="<?= $h($href) ?>" data-search="<?= $h(strtolower($r['method'] . ' ' . $r['path'] . ' ' . ($r['status'] ?? '') . ' ' . ($r['route']['name'] ?? ''))) ?>">
            <td class="muted mono"><?= $h(date('H:i:s', strtotime((string) $r['at']) ?: 0)) ?></td>
            <td class="method <?= $h((string) $r['method']) ?>"><?= $h((string) $r['method']) ?></td>
            <td class="path"><a href="<?= $h($href) ?>"><?= $h($r['path'] . $query) ?></a>
                <?php if ($r['route'] === null) { ?><span class="tag">no route</span><?php } ?>
                <?php if ($r['problem']) { ?><span class="tag bad">a stage threw</span><?php } ?>
                <?php if (($r['unreached'] ?? []) !== []) { ?><span class="tag" title="Never ran: <?= $h(implode(', ', $r['unreached'])) ?>">stopped early</span><?php } ?>
            </td>
            <td class="method <?= $tone($r['status'] ?? null) ?>"><?= $h((string) ($r['status'] ?? '—')) ?></td>
            <td class="num"><?= $h(number_format((float) $r['ms'], 1)) ?> ms</td>
            <td class="num muted"><?= (int) $r['queries'] ?> <?= (int) $r['queries'] === 1 ? 'query' : 'queries' ?></td>
        </tr>
<?php } ?>
    </table>
    </div>
<?php } ?>
</main>
<script>
(() => {
    const q = document.getElementById('q'), rows = [...document.querySelectorAll('tr.row')];
    q.addEventListener('input', () => {
        const words = q.value.toLowerCase().split(/\s+/).filter(Boolean);
        for (const row of rows) row.hidden = !words.every((w) => row.dataset.search.includes(w));
    });
    for (const row of rows) row.addEventListener('click', (e) => { if (!e.target.closest('a')) location.href = row.dataset.href; });
})();
</script>
</body>
</html>
