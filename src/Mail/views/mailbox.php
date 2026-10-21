<?php
/**
 * The dev mailbox. Plain PHP + inline CSS, no external requests. Message HTML is shown in a
 * sandboxed iframe (no scripts, no same-origin access).
 *
 * @var list<array<string, mixed>>  $messages
 * @var array<string, mixed>|null   $message
 * @var bool                        $missing
 * @var string                      $driver
 * @var string                      $listUrl
 */
$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$people = static fn (array $list): string => implode(', ', array_map(static fn (array $p) => $p[1] !== '' ? "{$p[1]} <{$p[0]}>" : $p[0], $list));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $message ? $h($message['subject']) . ' · ' : '' ?>Mail · pype</title>
<style>
    :root { --bg: #f7f7f8; --panel: #fff; --text: #1d1d22; --muted: #6b6b76; --line: #e3e3e8; --hover: #f0f0f4; --accent: #2f6fdb; --warn: #b26a00; }
    @media (prefers-color-scheme: dark) {
        :root { --bg: #141417; --panel: #1c1c21; --text: #e7e7ea; --muted: #9a9aa6; --line: #2c2c33; --hover: #24242a; --accent: #6c9cff; --warn: #f0a53a; }
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.45 system-ui, -apple-system, Segoe UI, sans-serif; }
    header { background: var(--panel); border-bottom: 1px solid var(--line); padding: 10px 16px; display: flex; gap: 12px; align-items: baseline; }
    header h1 { font-size: 15px; margin: 0; }
    a { color: var(--accent); }
    main { padding: 16px; max-width: 1100px; margin: 0 auto; }
    .warn { border: 1px solid var(--warn); color: var(--warn); border-radius: 8px; padding: 8px 12px; margin-bottom: 16px; }
    .muted { color: var(--muted); }
    table { width: 100%; border-collapse: collapse; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
    td { padding: 8px 12px; border-top: 1px solid var(--line); vertical-align: top; }
    tr:first-child td { border-top: 0; }
    tr:hover td { background: var(--hover); }
    td.when { white-space: nowrap; color: var(--muted); width: 1%; }
    dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 12px 16px; margin: 0 0 16px; }
    dt { color: var(--muted); } dd { margin: 0; overflow-wrap: anywhere; }
    iframe { width: 100%; height: 70vh; border: 1px solid var(--line); border-radius: 8px; background: #fff; }
    pre { white-space: pre-wrap; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 12px 16px; overflow-wrap: anywhere; }
    ul.links { margin: 0; padding-left: 18px; }
</style>
</head>
<body>
<header>
    <h1><a href="<?= $h($listUrl) ?>">Mail</a></h1>
    <span class="muted">copies of what this app sent, newest first (MAIL_DRIVER=<?= $h($driver) ?>)</span>
</header>
<main>
<?php if ($driver === 'log'): ?>
    <p class="warn">MAIL_DRIVER is <strong>log</strong>: nothing here was delivered to anyone. Set MAIL_DRIVER=smtp (Mailpit locally) to really send.</p>
<?php endif ?>

<?php if ($missing): ?>
    <p>That message is gone (only the newest are kept). <a href="<?= $h($listUrl) ?>">Back to the list</a>.</p>
<?php elseif ($message !== null): ?>
    <dl>
        <dt>Subject</dt><dd><strong><?= $h($message['subject']) ?></strong></dd>
        <dt>From</dt><dd><?= $h($message['from'] ? $people([$message['from']]) : '') ?></dd>
        <dt>To</dt><dd><?= $h($people($message['to'])) ?></dd>
        <?php if ($message['cc']): ?><dt>Cc</dt><dd><?= $h($people($message['cc'])) ?></dd><?php endif ?>
        <?php if ($message['bcc']): ?><dt>Bcc</dt><dd><?= $h($people($message['bcc'])) ?></dd><?php endif ?>
        <dt>Sent</dt><dd><?= $h($message['at']) ?> via <?= $h($message['driver']) ?></dd>
        <?php if ($message['attachments']): ?><dt>Attached</dt><dd><?= $h(implode(', ', $message['attachments'])) ?></dd><?php endif ?>
        <?php if ($message['links']): ?>
            <dt>Links</dt>
            <dd><ul class="links"><?php foreach ($message['links'] as $link): ?><li><a href="<?= $h($link) ?>" target="_blank" rel="noopener"><?= $h($link) ?></a></li><?php endforeach ?></ul></dd>
        <?php endif ?>
    </dl>
    <?php if ($message['html']): ?>
        <iframe sandbox title="Message" srcdoc="<?= $h($message['html']) ?>"></iframe>
    <?php endif ?>
    <?php if ($message['text']): ?>
        <pre><?= $h($message['text']) ?></pre>
    <?php endif ?>
<?php elseif ($messages === []): ?>
    <p class="muted">No mail yet. Anything the app sends shows up here.</p>
<?php else: ?>
    <table>
        <?php foreach ($messages as $m): ?>
            <tr>
                <td class="when"><?= $h(substr((string) $m['at'], 0, 19)) ?></td>
                <td><a href="?id=<?= $h($m['id']) ?>"><?= $h($m['subject'] !== '' ? $m['subject'] : '(no subject)') ?></a></td>
                <td class="muted"><?= $h($people($m['to'])) ?></td>
            </tr>
        <?php endforeach ?>
    </table>
<?php endif ?>
</main>
</body>
</html>
