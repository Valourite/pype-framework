<?php
/**
 * /_pype/routes: every route, its stages and the outlet that answers.
 *
 * @var array<string, string> $links
 * @var list<array{method: string, path: string, name: ?string, stages: list<string>, outlet: ?string, problems: int}> $routes
 * @var string $q
 */
$title = 'Routes';
$current = 'Routes';
require __DIR__ . '/_head.php';
$broken = count(array_filter($routes, static fn (array $r) => $r['problems'] > 0));
?>
    <input type="search" id="q" placeholder="Filter: path, name, stage…" value="<?= $h($q) ?>" autofocus>
    <label class="muted"><input type="checkbox" id="only-bad"<?= $broken === 0 ? ' disabled' : '' ?>> with problems (<?= $broken ?>)</label>
    <span class="grow"><span id="shown"><?= count($routes) ?></span> of <?= count($routes) ?> routes</span>
</header>
<main>
<style>
    table { width: 100%; border-collapse: collapse; }
    td { padding: 7px 10px; border-bottom: 1px solid var(--line); vertical-align: baseline; }
    tr:last-child td { border-bottom: 0; }
    tr.row { cursor: pointer; }
    tr.row:hover { background: var(--hover); }
    td.path a { color: var(--text); font-family: var(--mono); font-size: 13px; }
    td.path b { color: var(--info); font-weight: 600; }
    .chain { color: var(--muted); font-size: 13px; }
    .chain .outlet { color: var(--text); }
    @media (max-width: 760px) { td.name { display: none; } }
</style>
<?php if ($routes === []) { ?>
    <p class="empty">No routes yet. Add one in routes/web.php.</p>
<?php } else { ?>
    <div class="panel">
    <table>
<?php foreach ($routes as $route) {
    $href = $links['Routes'] . '/show?' . http_build_query(['m' => $route['method'], 'p' => $route['path']]);
    $search = strtolower(implode(' ', [$route['method'], $route['path'], (string) $route['name'], ...$route['stages'], (string) $route['outlet']]));
?>
        <tr class="row" data-search="<?= $h($search) ?>" data-bad="<?= $route['problems'] > 0 ? '1' : '0' ?>" data-href="<?= $h($href) ?>">
            <td class="method <?= $h($route['method']) ?>"><?= $h($route['method']) ?></td>
            <td class="path"><a href="<?= $h($href) ?>"><?= preg_replace('#\{(\w+)\}#', '<b>{$1}</b>', $h($route['path'])) ?></a><?php if ($route['problems'] > 0) { ?> <span class="tag bad"><?= $route['problems'] ?> <?= $route['problems'] === 1 ? 'problem' : 'problems' ?></span><?php } ?></td>
            <td class="name muted mono"><?= $h((string) $route['name']) ?></td>
            <td class="chain"><?= $h(implode(' → ', $route['stages'])) ?><?php if ($route['outlet'] !== null) { ?> <span class="outlet">⇒ <?= $h($route['outlet']) ?></span><?php } ?></td>
        </tr>
<?php } ?>
    </table>
    </div>
    <p class="empty" id="none" hidden>No route matches.</p>
<?php } ?>
</main>
<script>
(() => {
    const q = document.getElementById('q'), onlyBad = document.getElementById('only-bad'), shown = document.getElementById('shown'), none = document.getElementById('none');
    const rows = [...document.querySelectorAll('tr.row')];
    const filter = () => {
        const words = q.value.toLowerCase().split(/\s+/).filter(Boolean);
        let count = 0;
        for (const row of rows) {
            const on = words.every((w) => row.dataset.search.includes(w)) && (!onlyBad.checked || row.dataset.bad === '1');
            row.hidden = !on;
            count += on ? 1 : 0;
        }
        shown.textContent = count;
        if (none) none.hidden = count > 0;
    };
    q.addEventListener('input', filter);
    onlyBad.addEventListener('change', filter);
    for (const row of rows) row.addEventListener('click', (e) => { if (!e.target.closest('a')) location.href = row.dataset.href; });
    filter();
})();
</script>
</body>
</html>
