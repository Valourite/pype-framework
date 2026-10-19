<?php
/**
 * Development error page. Plain PHP + inline CSS/JS, no external requests.
 *
 * @var list<array{class: string, message: string, file: string, line: int, frames: list<array>}> $exceptions
 * @var array $request
 * @var array|null $route
 * @var list<string> $context
 * @var string $requestId
 * @var list<array> $logs
 * @var string|null $viewerUrl
 * @var string $basePath
 */
$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$short = static fn (string $file): string => str_starts_with($file, $basePath . '/') ? substr($file, strlen($basePath) + 1) : $file;
$json = static fn (mixed $v): string => (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$main = $exceptions[0];
$firstApp = 0;
foreach ($main['frames'] as $i => $f) {
    if (!$f['vendor']) { $firstApp = $i; break; }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $h($main['class']) ?>: <?= $h(mb_strimwidth($main['message'], 0, 80, '…')) ?></title>
<style>
    :root { --bg: #f7f7f8; --panel: #fff; --text: #1d1d22; --muted: #6b6b76; --line: #e3e3e8; --accent: #c7362f; --hl: #fde8e7; --code: #f3f3f6;
        --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    @media (prefers-color-scheme: dark) {
        :root { --bg: #141417; --panel: #1c1c21; --text: #e7e7ea; --muted: #9a9aa6; --line: #2c2c33; --accent: #ff6b61; --hl: #3a2020; --code: #18181c; }
    }
    * { box-sizing: border-box; }
    [hidden] { display: none !important; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 system-ui, -apple-system, Segoe UI, sans-serif; }
    .wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 60px; }
    .hero { background: var(--panel); border: 1px solid var(--line); border-left: 4px solid var(--accent); border-radius: 8px; padding: 18px 20px; }
    .class { color: var(--accent); font-family: var(--mono); font-size: 13px; }
    h1 { font-size: 20px; margin: 6px 0 8px; overflow-wrap: anywhere; }
    .where { font-family: var(--mono); font-size: 13px; color: var(--muted); }
    .meta { margin-top: 10px; color: var(--muted); font-size: 13px; display: flex; flex-wrap: wrap; gap: 6px 18px; }
    .meta code, td code { font-family: var(--mono); }
    .tabs { display: flex; gap: 4px; margin: 20px 0 0; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
    .tabs button { font: inherit; background: none; border: 0; border-bottom: 2px solid transparent; padding: 8px 12px; color: var(--muted); cursor: pointer; }
    .tabs button.on { color: var(--text); border-color: var(--accent); }
    section { display: none; padding-top: 14px; }
    section.on { display: block; }
    .trace { display: grid; grid-template-columns: minmax(220px, 34%) 1fr; gap: 14px; }
    .frames { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; overflow: auto; max-height: 70vh; }
    .frame { padding: 8px 12px; border-bottom: 1px solid var(--line); cursor: pointer; font-size: 13px; }
    .frame.vendor { opacity: .55; }
    .frame.on { background: var(--hl); opacity: 1; }
    .frame .call { font-family: var(--mono); font-size: 12px; overflow-wrap: anywhere; }
    .frame .file { color: var(--muted); font-family: var(--mono); font-size: 11.5px; overflow-wrap: anywhere; }
    .code { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; overflow: auto; }
    .code .head { padding: 8px 12px; border-bottom: 1px solid var(--line); font-family: var(--mono); font-size: 12px; color: var(--muted); }
    .src { display: none; }
    .src.on { display: block; }
    .lines { margin: 0; padding: 8px 0; font: 12.5px/1.6 var(--mono); }
    .lines div { padding: 0 12px; white-space: pre; }
    .lines div.hl { background: var(--hl); }
    .lines span { display: inline-block; width: 44px; color: var(--muted); user-select: none; }
    table { width: 100%; border-collapse: collapse; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; overflow: hidden; margin-bottom: 14px; }
    th, td { text-align: left; padding: 7px 12px; border-bottom: 1px solid var(--line); vertical-align: top; font-size: 13px; }
    th { width: 200px; color: var(--muted); font-weight: 500; }
    td { overflow-wrap: anywhere; }
    pre.json { margin: 0; font: 12px/1.5 var(--mono); white-space: pre-wrap; }
    h2 { font-size: 14px; margin: 18px 0 8px; color: var(--muted); font-weight: 600; }
    .stages { display: flex; flex-wrap: wrap; gap: 6px; }
    .stages code { background: var(--code); border: 1px solid var(--line); border-radius: 5px; padding: 2px 7px; font-size: 12px; }
    .level { font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .lvl-error, .lvl-critical, .lvl-alert, .lvl-emergency { color: var(--accent); }
    .lvl-warning { color: #b26a00; }
    a { color: inherit; }
    .empty { color: var(--muted); }
    @media (max-width: 800px) { .trace { grid-template-columns: 1fr; } .frames { max-height: 40vh; } th { width: 120px; } }
</style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <div class="class"><?= $h($main['class']) ?></div>
        <h1><?= $h($main['message'] !== '' ? $main['message'] : '(no message)') ?></h1>
        <div class="where"><?= $h($short($main['file'])) ?>:<?= (int) $main['line'] ?></div>
        <div class="meta">
            <span><?= $h($request['method']) ?> <code><?= $h($request['path']) ?></code></span>
            <?php if ($route): ?><span>route <code><?= $h($route['name'] ?? $route['path']) ?></code></span><?php endif ?>
            <span>request <code><?= $h($requestId) ?></code></span>
            <?php if ($viewerUrl): ?><a href="<?= $h($viewerUrl) ?>">Open this request in the log viewer →</a><?php endif ?>
            <span>Shown because APP_DEBUG=true. Production shows a plain error.</span>
        </div>
    </div>

    <nav class="tabs">
        <button class="on" data-tab="trace">Trace</button>
        <button data-tab="request">Request</button>
        <button data-tab="route">Route &amp; context</button>
        <button data-tab="logs">Logs (<?= count($logs) ?>)</button>
    </nav>

    <section id="trace" class="on">
        <?php foreach ($exceptions as $x => $ex): ?>
            <?php if ($x > 0): ?><h2>Caused by <?= $h($ex['class']) ?>: <?= $h($ex['message']) ?></h2><?php endif ?>
            <div class="trace">
                <div class="frames">
                    <?php foreach ($ex['frames'] as $i => $f): ?>
                        <div class="frame<?= $f['vendor'] ? ' vendor' : '' ?><?= $x === 0 && $i === $firstApp ? ' on' : '' ?>" data-src="src-<?= $x ?>-<?= $i ?>">
                            <div class="call"><?= $h($f['call'] !== '' ? $f['call'] : '{main}') ?></div>
                            <div class="file"><?= $h($short($f['file'])) ?>:<?= (int) $f['line'] ?></div>
                        </div>
                    <?php endforeach ?>
                </div>
                <div class="code">
                    <?php foreach ($ex['frames'] as $i => $f): ?>
                        <div class="src<?= $x === 0 && $i === $firstApp ? ' on' : '' ?>" id="src-<?= $x ?>-<?= $i ?>">
                            <div class="head"><?= $h($short($f['file'])) ?>:<?= (int) $f['line'] ?></div>
                            <?php if ($f['source'] === []): ?>
                                <pre class="lines"><div>Source not available.</div></pre>
                            <?php else: ?>
                                <pre class="lines"><?php foreach ($f['source'] as $n => $code): ?><div<?= $n === $f['line'] ? ' class="hl"' : '' ?>><span><?= $n ?></span><?= $h($code) ?></div><?php endforeach ?></pre>
                            <?php endif ?>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
        <?php endforeach ?>
    </section>

    <section id="request">
        <table>
            <tr><th>Method</th><td><?= $h($request['method']) ?></td></tr>
            <tr><th>Path</th><td><code><?= $h($request['path']) ?></code></td></tr>
            <tr><th>IP</th><td><?= $h($request['ip']) ?></td></tr>
            <tr><th>Query</th><td><?php if ($request['query']): ?><pre class="json"><?= $h($json($request['query'])) ?></pre><?php else: ?><span class="empty">none</span><?php endif ?></td></tr>
            <tr><th>Body</th><td><?php if ($request['input']): ?><pre class="json"><?= $h($json($request['input'])) ?></pre><?php else: ?><span class="empty">none</span><?php endif ?></td></tr>
            <tr><th>Cookies</th><td><?= $request['cookies'] ? $h(implode(', ', $request['cookies'])) . ' <span class="empty">(values hidden)</span>' : '<span class="empty">none</span>' ?></td></tr>
        </table>
        <h2>Headers</h2>
        <table>
            <?php foreach ($request['headers'] as $name => $value): ?>
                <tr><th><?= $h($name) ?></th><td><?= $h($value) ?></td></tr>
            <?php endforeach ?>
        </table>
    </section>

    <section id="route">
        <?php if ($route): ?>
            <table>
                <tr><th>Route</th><td><?= $h($route['method']) ?> <code><?= $h($route['path']) ?></code></td></tr>
                <tr><th>Name</th><td><?= $route['name'] !== null ? '<code>' . $h($route['name']) . '</code>' : '<span class="empty">unnamed</span>' ?></td></tr>
                <tr><th>Params</th><td><?php if ($route['params']): ?><pre class="json"><?= $h($json($route['params'])) ?></pre><?php else: ?><span class="empty">none</span><?php endif ?></td></tr>
                <tr><th>Stages (in order)</th><td><div class="stages"><?php foreach ($route['stages'] as $stage): ?><code><?= $h($stage) ?></code><?php endforeach ?></div></td></tr>
            </table>
        <?php else: ?>
            <p class="empty">The error happened before a route was matched (in a global stage, or no route matched).</p>
        <?php endif ?>
        <h2>Context (what stages put on the request)</h2>
        <div class="stages"><?php foreach ($context as $class): ?><code><?= $h($class) ?></code><?php endforeach ?></div>
    </section>

    <section id="logs">
        <?php if ($logs === []): ?>
            <p class="empty">No log lines for this request<?= $viewerUrl === null ? ' (add Pype\Log\LogProvider to config/app.php to log)' : '' ?>.</p>
        <?php else: ?>
            <table>
                <?php foreach ($logs as $entry): ?>
                    <tr>
                        <th><code><?= $h(substr((string) ($entry['time'] ?? ''), 11, 12)) ?></code> <span class="level lvl-<?= $h($entry['level'] ?? '') ?>"><?= $h($entry['level'] ?? '') ?></span></th>
                        <td><?= $h($entry['message'] ?? '') ?><?php if (!empty($entry['context'])): ?><pre class="json"><?= $h($json($entry['context'])) ?></pre><?php endif ?></td>
                    </tr>
                <?php endforeach ?>
            </table>
        <?php endif ?>
    </section>
</div>
<script>
document.querySelectorAll('.tabs button').forEach((b) => b.addEventListener('click', () => {
    document.querySelectorAll('.tabs button, section').forEach((el) => el.classList.remove('on'));
    b.classList.add('on');
    document.getElementById(b.dataset.tab).classList.add('on');
}));
document.querySelectorAll('.frame').forEach((f) => f.addEventListener('click', () => {
    const trace = f.closest('.trace');
    trace.querySelectorAll('.frame, .code > .src').forEach((el) => el.classList.remove('on'));
    f.classList.add('on');
    document.getElementById(f.dataset.src).classList.add('on');
}));
</script>
</body>
</html>
