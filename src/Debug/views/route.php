<?php
/**
 * /_pype/routes/show: one route taken apart.
 *
 * @var array<string, string> $links
 * @var array{method: string, path: string, name: ?string, params: list<string>, stages: list<array<string, mixed>>, outlets: list<array<string, mixed>>, problems: list<string>, hints: list<string>} $route
 */
$title = $route['method'] . ' ' . $route['path'];
$current = 'Routes';
require __DIR__ . '/_head.php';
$short = static fn (string $class): string => str_contains($class, '\\') ? substr((string) strrchr($class, '\\'), 1) : $class;
$written = static fn (array $s): string => match ($s['kind']) {
    'closure' => 'fn (...) => ...',
    'with' => $s['name'] . '::with(' . implode(', ', array_map(static fn ($k, $v) => (is_numeric($k) ? '' : $k . ': ') . $v, array_keys($s['args']), $s['args'])) . ')',
    'instance' => 'new ' . $s['name'] . '(...)',
    default => $s['name'] . '::class',
};
$card = static function (array $s, int $number, bool $outlet) use ($h, $short, $written): void { ?>
    <li class="stage panel<?= $s['missing'] ? ' broken' : '' ?>">
        <div class="top">
            <span class="n"><?= $outlet ? '⇒' : $number ?></span>
            <strong><?= $h($s['name']) ?></strong>
            <code class="muted"><?= $h($written($s)) ?></code>
            <span class="where">
                <?php if ($s['origin'] === 'global') { ?><span class="tag">every route</span><?php } ?>
                <?php if ($s['stack'] !== null) { ?><span class="tag">stack '<?= $h($s['stack']) ?>'</span><?php } ?>
                <?php if ($s['kind'] === 'instance' && $s['origin'] === 'route') { ?><span class="tag" title="Built while the routes load and shared by every request a worker serves">shared instance</span><?php } ?>
            </span>
        </div>
        <?php if ($s['missing']) { ?><p class="muted">[<?= $h((string) $s['class']) ?>] is neither a class nor a stack name.</p><?php } ?>
        <?php if ($s['needs'] !== [] || $s['provides'] !== []) { ?>
        <div class="io">
            <?php foreach ($s['needs'] as $need) { ?>
                <span class="tag key <?= $need['from'] === null ? 'unmet' : 'needs' ?>" title="<?= $h($need['key']) ?>">needs <?= $h($short($need['key'])) ?><?= $need['from'] === null ? ': nothing before it provides it' : ' ← ' . $h($need['from']) ?></span>
            <?php } ?>
            <?php foreach ($s['provides'] as $key) { ?>
                <span class="tag key gives" title="<?= $h($key) ?>">puts <?= $h($short($key)) ?></span>
            <?php } ?>
        </div>
        <?php } elseif ($s['kind'] !== 'closure' && !$s['missing']) { ?>
        <div class="io muted">declares nothing it needs or puts</div>
        <?php } ?>
        <?php if ($s['file'] !== null) { ?><div class="file mono muted"><?= $h($s['file']) ?><?= $s['line'] !== null ? ':' . $s['line'] : '' ?></div><?php } ?>
    </li>
<?php };
?>
    <span class="grow"><?php if (isset($links['Sandbox'])) { ?><a href="<?= $h($links['Sandbox'] . '?' . http_build_query(['m' => $route['method'], 'p' => $route['path']])) ?>">try it in the sandbox</a> · <?php } ?><a href="<?= $h($links['Routes']) ?>">← all routes</a></span>
</header>
<main>
<style>
    ol.stages { list-style: none; margin: 0; padding: 0; }
    .stage { padding: 10px 12px; margin: 0 0 8px; }
    .stage.broken { border-color: var(--error); background: var(--bad); }
    .stage .top { display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: baseline; }
    .stage .n { display: inline-grid; place-items: center; min-width: 22px; height: 22px; border-radius: 50%; background: var(--tint); color: var(--info); font-size: 12px; font-weight: 700; }
    .stage .where { margin-left: auto; display: flex; gap: 6px; }
    .stage .io { margin: 8px 0 0 32px; display: flex; flex-wrap: wrap; gap: 6px; font-size: 13px; }
    .stage .file { margin: 6px 0 0 32px; }
    .stage p { margin: 6px 0 0 32px; }
    .wrap { border-left: 3px solid var(--line); padding-left: 12px; }
</style>
    <h1><span class="method <?= $h($route['method']) ?>"><?= $h($route['method']) ?></span> <span class="mono"><?= $h($route['path']) ?></span></h1>
    <p class="lead">
        <?= $route['name'] !== null ? 'Named <code>' . $h($route['name']) . '</code>. ' : 'Not named. ' ?>
        <?= $route['params'] === [] ? '' : 'Parameters: <code>' . $h(implode('</code>, <code>', $route['params'])) . '</code>. ' ?>
        <?= count($route['stages']) ?> <?= count($route['stages']) === 1 ? 'stage' : 'stages' ?>, in the order they run.
    </p>

<?php foreach ($route['problems'] as $problem) { ?>
    <p class="problem"><?= $h($problem) ?></p>
<?php } ?>
<?php foreach ($route['hints'] as $hint) { ?>
    <p class="hint"><?= $h((string) preg_replace('/^[^:]+: /', '', $hint)) ?></p>
<?php } ?>

<?php if ($route['outlets'] !== []) { ?>
    <h2>Outlets: wrap every stage below, and write what comes back</h2>
    <ol class="stages">
<?php foreach ($route['outlets'] as $i => $outlet) { $card($outlet, $i + 1, true); } ?>
    </ol>
    <p class="muted" style="margin: -2px 0 0;"><?= count($route['outlets']) > 1 ? 'The last one listed answers: a route\'s outlet beats its group\'s, which beats a global one.' : '' ?></p>
<?php } else { ?>
    <h2>Outlet</h2>
    <p class="muted">None on this route: the app's default outlet writes the result.</p>
<?php } ?>

    <h2>Stages</h2>
<?php if ($route['stages'] === []) { ?>
    <p class="muted">No stages: nothing runs before the outlet.</p>
<?php } else { ?>
    <ol class="stages wrap">
<?php foreach ($route['stages'] as $i => $stage) { $card($stage, $i + 1, false); } ?>
    </ol>
    <p class="muted">Each stage calls the next, so the first wraps the rest. The last one returns the result (or any stage may return early: a redirect, a 404, validation errors).</p>
<?php } ?>
</main>
</body>
</html>
