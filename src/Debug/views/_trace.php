<?php
/**
 * What a request did, stage by stage: shared by the recorded request's page and the sandbox's
 * result. Needs _trace_helpers.php loaded first.
 *
 * @var array<string, mixed> $record  a record as Trace::finish() makes it
 */
$total = max(0.001, (float) $record['ms']);
?>
<style>
    ol.stages, ol.sql { list-style: none; margin: 0; padding: 0; }
    .stage { padding: 9px 12px; margin: 0 0 6px; }
    .stage.threw { border-color: var(--error); background: var(--bad); }
    .stage .top { display: flex; flex-wrap: wrap; gap: 4px 10px; align-items: baseline; }
    .stage .time { margin-left: auto; font-variant-numeric: tabular-nums; }
    .stage .bar { height: 4px; border-radius: 2px; background: var(--line); margin-top: 7px; position: relative; overflow: hidden; }
    .stage .bar i { position: absolute; top: 0; bottom: 0; background: var(--info); opacity: .35; }
    .stage .bar i.own { opacity: 1; }
    .stage .notes { margin-top: 7px; display: flex; flex-wrap: wrap; gap: 6px; }
    .stage p { margin: 7px 0 0; }
    details { margin-top: 7px; }
    summary { cursor: pointer; color: var(--muted); font-size: 13px; }
    ol.sql li { padding: 6px 0; border-top: 1px solid var(--line); display: grid; grid-template-columns: 70px 1fr; gap: 0 10px; }
    ol.sql li:first-child { border-top: 0; }
    ol.sql .t { color: var(--muted); font-size: 12.5px; font-variant-numeric: tabular-nums; }
    ol.sql code { white-space: pre-wrap; overflow-wrap: anywhere; }
    ol.sql .b { grid-column: 2; overflow-wrap: anywhere; }
    table.kv { border-collapse: collapse; width: 100%; }
    table.kv td { padding: 4px 10px; border-top: 1px solid var(--line); vertical-align: top; overflow-wrap: anywhere; }
    table.kv tr:first-child td { border-top: 0; }
    table.kv td:first-child { width: 220px; }
    .facts { display: flex; flex-wrap: wrap; gap: 6px 18px; margin: 0 0 6px; }
</style>
    <h2>Stages, in the order they were entered</h2>
<?php if ($record['stages'] === []) { ?>
    <p class="muted">No stage ran.</p>
<?php } ?>
    <ol class="stages">
<?php foreach ($record['stages'] as $i => $s) {
    $declared = $s['class'] !== null && class_exists($s['class']) ? \Pype\Routing\RouteCheck::declarations($s['class'])[1] : null;
    $last = !isset($record['stages'][$i + 1]);
?>
        <li class="stage panel<?= $s['exception'] !== null && !($s['exception']['http'] ?? false) ? ' threw' : '' ?>" style="margin-left: <?= min(10, (int) $s['depth']) * 14 ?>px;">
            <div class="top">
                <strong><?= $h((string) $s['name']) ?></strong>
                <span class="tag"><?= $s['kind'] === 'outlet' ? 'outlet' : ($s['kind'] === 'global' ? 'every route' : 'route') ?></span>
                <?php if ($s['status'] !== null) { ?><span class="muted">returned <span class="method <?= $tone($s['status']) ?>"><?= (int) $s['status'] ?></span><?= $s['location'] ? ' → <code>' . $h((string) $s['location']) . '</code>' : '' ?></span><?php } ?>
                <span class="time"><strong><?= $ms($s['own']) ?></strong> <span class="muted">own<?= $s['calledNext'] ? ' · ' . $ms($s['ms']) . ' with what it called' : '' ?></span></span>
            </div>
            <div class="bar"><i style="left: <?= round($s['at'] / $total * 100, 2) ?>%; width: <?= max(0.4, round($s['ms'] / $total * 100, 2)) ?>%;"></i><i class="own" style="left: <?= round($s['at'] / $total * 100, 2) ?>%; width: <?= max(0.4, round($s['own'] / $total * 100, 2)) ?>%;"></i></div>
            <?php if ($s['exception'] !== null && ($s['exception']['http'] ?? false)) { ?>
            <p class="muted">Stopped here: <?= $h((string) $s['exception']['message']) ?> <span class="mono">(<?= $h($short((string) $s['exception']['class'])) ?>)</span></p>
            <?php } elseif ($s['exception'] !== null) { ?>
            <p><strong><?= $h((string) $s['exception']['class']) ?></strong>: <?= $h((string) $s['exception']['message']) ?><br><span class="muted mono"><?= $h($s['exception']['file'] . ':' . $s['exception']['line']) ?></span></p>
            <?php } ?>
            <?php if ($s['put'] !== [] || $s['replacedRequest'] || (!$s['calledNext'] && !$last)) { ?>
            <div class="notes">
                <?php foreach ($s['put'] as $class) { $undeclared = $declared !== null && !in_array($class, $declared, true) && $s['kind'] !== 'outlet'; ?>
                    <span class="tag key gives" title="<?= $h((string) $class) ?><?= $undeclared ? ' — not in its #[Provides], so routes:check does not know about it' : '' ?>">put <?= $h($short((string) $class)) ?><?= $undeclared ? ' (not declared)' : '' ?></span>
                <?php } ?>
                <?php if ($s['replacedRequest']) { ?><span class="tag key needs" title="It handed a copy of the request to the next stage. Stages before it, the outlets among them, still hold the original: without the route parameters and with the input as it arrived.">passed on a copy of the request</span><?php } ?>
                <?php if (!$s['calledNext'] && !$last) { ?><span class="tag">did not call the next stage</span><?php } ?>
            </div>
            <?php } ?>
            <?php if ($s['queries'] !== []) { ?>
            <details<?= count($s['queries']) <= 3 ? ' open' : '' ?>>
                <summary><?= count($s['queries']) ?> <?= count($s['queries']) === 1 ? 'query' : 'queries' ?>, <?= $ms(array_sum(array_column($s['queries'], 'ms'))) ?></summary>
                <?php $queries($s['queries']); ?>
            </details>
            <?php } ?>
        </li>
<?php } ?>
    </ol>
<?php if (($record['unreached'] ?? []) !== []) { ?>
    <p class="hint">Never ran: <strong><?= $h(implode(', ', $record['unreached'])) ?></strong>. A stage before <?= count($record['unreached']) === 1 ? 'it' : 'them' ?> answered or threw.</p>
<?php } ?>
<?php if (($record['loose'] ?? []) !== []) { ?>
    <h2>Queries outside any stage</h2>
    <div class="panel" style="padding: 4px 12px;"><?php $queries($record['loose']); ?></div>
<?php } ?>

