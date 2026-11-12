<?php
/**
 * Shared top of the development screens: the palette, the bar with a link to each screen.
 * Plain PHP + inline CSS, no build step, no external requests.
 *
 * @var string                $title
 * @var array<string, string> $links   label => address of every screen registered in this app
 * @var string                $current label of the screen being shown
 */
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $h($title) ?> · pype</title>
<style>
    :root {
        --bg: #f7f7f8; --panel: #fff; --text: #1d1d22; --muted: #6b6b76; --line: #e3e3e8; --hover: #f0f0f4;
        --info: #2f6fdb; --notice: #0f8f7a; --warning: #b26a00; --error: #c7362f; --tint: #eef3fd; --bad: #fdeeee;
        --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }
    @media (prefers-color-scheme: dark) {
        :root { --bg: #141417; --panel: #1c1c21; --text: #e7e7ea; --muted: #9a9aa6; --line: #2c2c33; --hover: #24242a;
            --info: #6c9cff; --notice: #3cc7ad; --warning: #f0a53a; --error: #ff6b61; --tint: #1b2436; --bad: #331c1c; }
    }
    * { box-sizing: border-box; }
    [hidden] { display: none !important; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.45 system-ui, -apple-system, Segoe UI, sans-serif; }
    a { color: var(--info); text-decoration: none; }
    a:hover { text-decoration: underline; }
    header { position: sticky; top: 0; z-index: 2; background: var(--panel); border-bottom: 1px solid var(--line); padding: 10px 16px; display: flex; flex-wrap: wrap; gap: 6px 16px; align-items: center; }
    header nav { display: flex; gap: 14px; align-items: center; }
    header nav a { color: var(--muted); }
    header nav a.on, header nav a:first-child { color: var(--text); font-weight: 600; }
    header .grow { margin-left: auto; color: var(--muted); font-size: 13px; }
    input, select, button { font: inherit; color: inherit; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; padding: 5px 8px; }
    input[type=search] { min-width: 260px; }
    main { padding: 16px; max-width: 1180px; margin: 0 auto; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin: 26px 0 8px; }
    p.lead { color: var(--muted); margin: 0 0 14px; max-width: 70ch; }
    code, .mono { font-family: var(--mono); font-size: 12.5px; }
    .muted { color: var(--muted); }
    .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; }
    .method { font-family: var(--mono); font-size: 11.5px; font-weight: 700; letter-spacing: .02em; }
    .GET { color: var(--notice); } .POST { color: var(--info); } .PUT, .PATCH { color: var(--warning); } .DELETE { color: var(--error); }
    .tag { display: inline-block; font-size: 11px; padding: 1px 7px; border-radius: 999px; border: 1px solid var(--line); color: var(--muted); white-space: nowrap; }
    .tag.bad { border-color: var(--error); color: var(--error); }
    .tag.key { font-family: var(--mono); font-size: 11.5px; border-radius: 5px; }
    .tag.gives { border-color: var(--notice); color: var(--notice); }
    .tag.needs { border-color: var(--info); color: var(--info); }
    .tag.unmet { border-color: var(--error); color: var(--error); background: var(--bad); }
    .empty { color: var(--muted); text-align: center; padding: 40px 0; }
    .problem { background: var(--bad); border: 1px solid var(--error); border-radius: 8px; padding: 9px 12px; margin: 0 0 8px; }
    .hint { border: 1px solid var(--warning); border-radius: 8px; padding: 9px 12px; margin: 0 0 8px; color: var(--muted); }
</style>
</head>
<body>
<header>
    <nav>
<?php foreach ($links as $label => $href) { ?>
        <a href="<?= $h($href) ?>"<?= $label === $current ? ' class="on"' : '' ?>><?= $h($label) ?></a>
<?php } ?>
    </nav>
