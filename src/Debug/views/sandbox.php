<?php
/**
 * /_pype/sandbox: send a request into a throw-away database and see what it did.
 *
 * @var array<string, string>     $links
 * @var array{built: bool, at: ?string, stale: bool, seeder: ?string, tables: array<string, int>, carrying: bool} $status
 * @var array<string, mixed>      $form     method, path, params, body, headers, user, carry
 * @var list<string>              $params   the {names} in the path
 * @var array<string, array{table: string, ids: list<string>}> $suggest
 * @var list<array{id: int, label: string}> $users
 * @var list<string>              $routes   "METHOD /path" of every route
 * @var array<string, mixed>|null $result
 * @var string|null               $message
 * @var string|null               $error
 */
$title = 'Sandbox';
$current = 'Sandbox';
require __DIR__ . '/_head.php';
require __DIR__ . '/_trace_helpers.php';
$action = $links['Sandbox'];
$show = static fn (mixed $v): string => match (true) {
    $v === null => 'null', is_bool($v) => $v ? 'true' : 'false', is_scalar($v) => (string) $v,
    default => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
};
$rows = array_sum($status['tables']);
?>
    <span class="grow"><?= $status['built'] ? $h(count($status['tables']) . ' tables, ' . $rows . ' rows · built ' . date('j M H:i', strtotime((string) $status['at']) ?: 0)) : 'not built yet' ?></span>
</header>
<main>
<style>
    form.run { display: grid; gap: 12px; padding: 14px; }
    form.run .line { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    form.run label { display: grid; gap: 4px; font-size: 13px; color: var(--muted); }
    form.run input[type=text], form.run textarea, form.run select { width: 100%; }
    form.run textarea { font-family: var(--mono); font-size: 12.5px; min-height: 84px; resize: vertical; }
    form.run .path { flex: 1 1 320px; font-family: var(--mono); }
    .two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 760px) { .two { grid-template-columns: 1fr; } }
    button.primary { background: var(--info); border-color: var(--info); color: #fff; font-weight: 600; padding: 6px 16px; cursor: pointer; }
    button.quiet { cursor: pointer; }
    .ok { border: 1px solid var(--notice); border-radius: 8px; padding: 9px 12px; margin: 0 0 12px; white-space: pre-wrap; }
    table.rows { border-collapse: collapse; width: 100%; font-family: var(--mono); font-size: 12.5px; }
    table.rows td, table.rows th { padding: 4px 9px; border-top: 1px solid var(--line); text-align: left; vertical-align: top; overflow-wrap: anywhere; }
    table.rows th { color: var(--muted); font-weight: 500; border-top: 0; }
    .ins { color: var(--notice); } .del { color: var(--error); } .chg { color: var(--warning); }
    .scroll { overflow-x: auto; }
    pre.body { margin: 0; padding: 12px; max-height: 420px; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; font-family: var(--mono); font-size: 12.5px; }
    ol.stages, ol.sql { list-style: none; margin: 0; padding: 0; }
</style>
    <h1>Sandbox</h1>
    <p class="lead">A throw-away SQLite database, built from this app's migrations and its seeder. Send a request into it and see the response, what every stage did and which rows changed. Each run starts from a fresh copy; your real database is never touched.</p>

<?php if ($error !== null) { ?>
    <p class="problem" style="white-space: pre-wrap;"><?= $h($error) ?></p>
<?php } ?>
<?php if ($message !== null) { ?>
    <p class="ok"><?= $h($message) ?></p>
<?php } ?>

<?php if (!$status['built']) { ?>
    <form method="post" action="<?= $h($action) ?>" class="panel" style="padding: 14px;">
        <p style="margin: 0 0 10px;">Nothing is built yet. Building runs every migration into a new SQLite file and then <code>Database\Seeders\DatabaseSeeder</code> (or the seeder named in <code>debug.sandbox.seeder</code>), in a process of its own.</p>
        <button class="primary" name="action" value="build">Build the sandbox</button>
        <span class="muted">or <code>php bin/pype sandbox:build</code></span>
    </form>
<?php } else { ?>
<?php if ($status['stale']) { ?>
    <p class="hint">The migrations have changed since this sandbox was built. Rebuild it to have the new tables.</p>
<?php } ?>
<?php if ($status['seeder'] === null) { ?>
    <p class="hint">Built without a seeder, so the tables are empty: add <code>Database\Seeders\DatabaseSeeder</code> (<code>php bin/pype make:seeder</code>) and rebuild.</p>
<?php } ?>
    <form method="post" action="<?= $h($action) ?>" class="panel run">
        <div class="line">
            <select name="method" style="width: auto;">
<?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) { ?>
                <option<?= $form['method'] === $method ? ' selected' : '' ?>><?= $method ?></option>
<?php } ?>
            </select>
            <input type="text" name="path" class="path" list="routes" value="<?= $h((string) $form['path']) ?>" placeholder="/quotes/{id}?page=2" required>
            <datalist id="routes">
<?php foreach ($routes as $route) { [$m, $p] = explode(' ', $route, 2); ?>
                <option value="<?= $h($p) ?>"><?= $h($m) ?></option>
<?php } ?>
            </datalist>
            <button class="primary" name="action" value="run">Run</button>
        </div>
<?php if ($params !== []) { ?>
        <div class="line">
<?php foreach ($params as $param) { ?>
            <label><span>{<?= $h($param) ?>}<?= isset($suggest[$param]) ? ' from ' . $h($suggest[$param]['table']) : '' ?></span>
                <input type="text" name="params[<?= $h($param) ?>]" list="ids-<?= $h($param) ?>" value="<?= $h((string) ($form['params'][$param] ?? ($suggest[$param]['ids'][0] ?? ''))) ?>" style="width: 150px;">
                <datalist id="ids-<?= $h($param) ?>"><?php foreach ($suggest[$param]['ids'] ?? [] as $id) { ?><option value="<?= $h($id) ?>"><?php } ?></datalist>
            </label>
<?php } ?>
        </div>
<?php } ?>
        <div class="two">
            <label><span>Body: one <code>field=value</code> per line, or JSON</span>
                <textarea name="body" placeholder="name=Ada&#10;email=ada@example.test"><?= $h((string) $form['body']) ?></textarea>
            </label>
            <label><span>Headers: one <code>Name: value</code> per line</span>
                <textarea name="headers" placeholder="Accept: application/json"><?= $h((string) $form['headers']) ?></textarea>
            </label>
        </div>
        <div class="line">
            <label style="flex: 0 1 340px;">Send as
                <select name="user">
                    <option value="">nobody (signed out)</option>
<?php foreach ($users as $user) { ?>
                    <option value="<?= $user['id'] ?>"<?= (string) $form['user'] === (string) $user['id'] ? ' selected' : '' ?>><?= $h($user['label']) ?></option>
<?php } ?>
                </select>
            </label>
            <label style="display: flex; gap: 6px; align-items: center; color: var(--text);"><input type="checkbox" name="carry" value="1"<?= $form['carry'] ? ' checked' : '' ?>> carry on from the last run<?= $status['carrying'] ? '' : ' <span class="muted">(none yet)</span>' ?></label>
            <span style="margin-left: auto; display: flex; gap: 8px;">
                <button class="quiet" name="action" value="reset" formnovalidate>Reset</button>
                <button class="quiet" name="action" value="build" formnovalidate>Rebuild</button>
            </span>
        </div>
    </form>
    <p class="muted" style="margin-top: 8px;">Only what goes through pype is sandboxed: the database, mail, the queue, the cache and sessions. An HTTP call the app makes itself, a file it writes, or a second database it opens by hand are real.</p>
<?php } ?>

<?php if ($result !== null) {
    $response = $result['response'];
    $record = $result['trace'];
?>
    <h2>Response</h2>
    <p class="facts">
        <span class="method <?= $tone((int) $response['status']) ?>"><?= (int) $response['status'] ?></span>
        <span class="muted"><?= $ms($response['ms']) ?></span>
        <?php if ($response['location']) { ?><span>redirects to <code><?= $h((string) $response['location']) ?></code></span><?php } ?>
        <span class="muted"><?= $h((string) $response['type']) ?> · <?= number_format((int) $response['bytes']) ?> bytes</span>
        <span class="muted"><?= $h($result['request']['method'] . ' ' . $result['request']['uri']) ?></span>
    </p>
<?php foreach ($result['notes'] as $note) { ?>
    <p class="muted" style="margin: 0 0 4px;"><?= $h((string) $note) ?></p>
<?php } ?>
    <details class="panel" style="margin-top: 8px;"<?= (int) $response['bytes'] < 1500 ? ' open' : '' ?>>
        <summary style="padding: 8px 12px;">Body</summary>
        <pre class="body"><?= $h((string) $response['body']) ?></pre>
    </details>
<?php if (($result['printed'] ?? '') !== '') { ?>
    <p class="hint" style="white-space: pre-wrap; margin-top: 8px;">The app printed: <?= $h((string) $result['printed']) ?></p>
<?php } ?>

    <h2>Rows that changed</h2>
<?php if ($result['changes'] === []) { ?>
    <p class="muted">None: the request wrote nothing to the database.</p>
<?php } ?>
<?php foreach ($result['changes'] as $table => $change) { ?>
    <div class="panel" style="margin: 0 0 8px;">
        <p style="margin: 0; padding: 8px 12px;"><strong class="mono"><?= $h((string) $table) ?></strong>
            <span class="muted"><?= (int) $change['before'] ?> → <?= (int) $change['after'] ?> rows</span>
            <?php if ($change['inserted'] !== []) { ?><span class="tag key gives">+<?= count($change['inserted']) ?> new</span><?php } ?>
            <?php if ($change['changed'] !== []) { ?><span class="tag key">~<?= count($change['changed']) ?> changed</span><?php } ?>
            <?php if ($change['deleted'] !== []) { ?><span class="tag key unmet">−<?= count($change['deleted']) ?> deleted</span><?php } ?>
            <?php if ($change['counted']) { ?><span class="muted">too many rows to compare one by one</span><?php } ?>
            <?php if ($change['more'] > 0) { ?><span class="muted">and <?= (int) $change['more'] ?> more not shown</span><?php } ?>
        </p>
        <?php foreach (['inserted' => ['+', 'ins'], 'deleted' => ['−', 'del']] as $kind => [$sign, $class]) { ?>
        <?php if ($change[$kind] !== []) { ?>
        <div class="scroll"><table class="rows">
            <tr><th></th><?php foreach (array_keys($change[$kind][0]) as $column) { ?><th><?= $h((string) $column) ?></th><?php } ?></tr>
            <?php foreach ($change[$kind] as $row) { ?>
            <tr><td class="<?= $class ?>"><?= $sign ?></td><?php foreach ($row as $value) { ?><td<?= $value === null ? ' class="muted"' : '' ?>><?= $h($show($value)) ?></td><?php } ?></tr>
            <?php } ?>
        </table></div>
        <?php } ?>
        <?php } ?>
        <?php if ($change['changed'] !== []) { ?>
        <div class="scroll"><table class="rows">
            <tr><th></th><th>row</th><th>column</th><th>was</th><th>is now</th></tr>
            <?php foreach ($change['changed'] as $row) { foreach ($row['columns'] as $column => [$was, $now]) { ?>
            <tr><td class="chg">~</td><td><?= $h((string) $row['key']) ?></td><td><?= $h((string) $column) ?></td><td class="del"><?= $h($show($was)) ?></td><td class="ins"><?= $h($show($now)) ?></td></tr>
            <?php } } ?>
        </table></div>
        <?php } ?>
    </div>
<?php } ?>

<?php if ($result['mail'] !== []) { ?>
    <h2>Mail it sent (kept here: nothing was delivered)</h2>
<?php foreach ($result['mail'] as $mail) { ?>
    <details class="panel" style="margin: 0 0 8px;">
        <summary style="padding: 8px 12px;"><strong><?= $h((string) $mail['subject']) ?></strong> <span class="muted">to <?= $h(implode(', ', $mail['to'])) ?></span></summary>
        <pre class="body"><?= $h((string) $mail['text']) ?></pre>
    </details>
<?php } ?>
<?php } ?>

<?php if ($result['jobs'] !== []) { ?>
    <h2>Jobs it queued (kept here: none ran)</h2>
    <div class="panel"><table class="rows">
<?php foreach ($result['jobs'] as $job) { ?>
        <tr><td><?= $h((string) $job['class']) ?></td><td class="muted"><?= $h((string) $job['data']) ?></td></tr>
<?php } ?>
    </table></div>
<?php } ?>

<?php if ($record !== null) { ?>
<?php require __DIR__ . '/_trace.php'; ?>
<?php } else { ?>
    <h2>Stages</h2>
    <p class="muted">Not recorded: the request recorder is switched off (<code>debug.requests.enabled</code>).</p>
<?php } ?>
<?php } ?>
</main>
</body>
</html>
