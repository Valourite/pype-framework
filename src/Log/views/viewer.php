<?php
/**
 * The log viewer page. Plain PHP + inline CSS/JS, no build step, no external requests.
 *
 * @var string       $entriesUrl
 * @var list<string> $files
 * @var string       $today
 * @var string       $request  request id to filter on, from ?request=
 */
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
if (!in_array($today, $files, true)) {
    array_unshift($files, $today);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Logs · pype</title>
<style>
    :root {
        --bg: #f7f7f8; --panel: #fff; --text: #1d1d22; --muted: #6b6b76; --line: #e3e3e8; --hover: #f0f0f4;
        --debug: #8a8a96; --info: #2f6fdb; --notice: #0f8f7a; --warning: #b26a00; --error: #c7362f; --critical: #a1124f;
        --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }
    @media (prefers-color-scheme: dark) {
        :root { --bg: #141417; --panel: #1c1c21; --text: #e7e7ea; --muted: #9a9aa6; --line: #2c2c33; --hover: #24242a;
            --debug: #8d8d99; --info: #6c9cff; --notice: #3cc7ad; --warning: #f0a53a; --error: #ff6b61; --critical: #ff5c9a; }
    }
    * { box-sizing: border-box; }
    [hidden] { display: none !important; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.45 system-ui, -apple-system, Segoe UI, sans-serif; }
    header { position: sticky; top: 0; z-index: 2; background: var(--panel); border-bottom: 1px solid var(--line); padding: 10px 16px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    header h1 { font-size: 15px; margin: 0 12px 0 0; }
    select, input, button { font: inherit; color: inherit; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; padding: 5px 8px; }
    input[type=search] { min-width: 220px; }
    button { cursor: pointer; }
    button.on { border-color: var(--info); color: var(--info); }
    .status { margin-left: auto; color: var(--muted); font-size: 13px; display: flex; align-items: center; gap: 6px; }
    .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--muted); }
    .dot.live { background: var(--notice); }
    main { padding: 8px 16px 40px; }
    .older { display: block; margin: 8px auto; }
    .entry { border-bottom: 1px solid var(--line); padding: 5px 4px; display: grid; grid-template-columns: 96px 76px 1fr auto; gap: 10px; align-items: baseline; cursor: pointer; }
    .entry:hover { background: var(--hover); }
    .time, .rid, pre { font-family: var(--mono); font-size: 12.5px; }
    .time { color: var(--muted); }
    .level { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
    .message { overflow-wrap: anywhere; }
    .rid { color: var(--muted); text-decoration: none; }
    .rid:hover { color: var(--info); text-decoration: underline; }
    pre { grid-column: 1 / -1; margin: 6px 0 4px; padding: 10px; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; overflow: auto; white-space: pre-wrap; cursor: text; }
    .empty { color: var(--muted); text-align: center; padding: 40px 0; }
    @media (max-width: 640px) { .entry { grid-template-columns: 70px 1fr; } .level { grid-column: 2; } .message { grid-column: 1 / -1; } .rid { display: none; } }
</style>
</head>
<body>
<header>
    <h1>Logs</h1>
    <select id="file" title="Day">
        <?php foreach ($files as $file): ?>
            <option value="<?= $h($file) ?>"<?= $file === $today ? ' selected' : '' ?>><?= $h(substr($file, 5, 10)) ?><?= $file === $today ? ' (today)' : '' ?></option>
        <?php endforeach ?>
    </select>
    <select id="level" title="Minimum level">
        <option value="">All levels</option>
        <?php foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level): ?>
            <option value="<?= $level ?>"><?= ucfirst($level) ?>+</option>
        <?php endforeach ?>
    </select>
    <input id="request" type="search" placeholder="Request id" value="<?= $h($request) ?>" size="18">
    <input id="q" type="search" placeholder="Search text">
    <button id="live" class="on" title="Show new entries as they're written">Live</button>
    <span class="status"><span class="dot" id="dot"></span><span id="status">Loading…</span></span>
</header>
<main>
    <button class="older" id="older" hidden>Load older</button>
    <div id="list"></div>
    <p class="empty" id="empty" hidden>No log entries.</p>
</main>
<script>
(() => {
    const url = <?= json_encode($entriesUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const $ = (id) => document.getElementById(id);
    const list = $('list'), older = $('older'), empty = $('empty'), statusText = $('status'), dot = $('dot');
    const MAX_ROWS = 2000, FAST = 1000, SLOW = 5000, IDLE_AFTER = 60000;
    let state = { file: $('file').value, next: 0, before: 0, today: $('file').value };
    let live = true, timer = null, lastNew = Date.now(), loading = false;

    const filters = () => {
        const p = new URLSearchParams({ file: state.file });
        for (const id of ['level', 'request', 'q']) { if ($(id).value.trim()) p.set(id, $(id).value.trim()); }
        return p;
    };
    const fetchJson = async (params) => {
        const res = await fetch(url + '?' + params, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return (await res.json()).data;
    };
    const atBottom = () => window.innerHeight + window.scrollY >= document.body.scrollHeight - 40;

    function row(e) {
        const div = document.createElement('div');
        div.className = 'entry';
        const time = document.createElement('span');
        time.className = 'time';
        time.textContent = (e.time || '').slice(11, 23);
        time.title = e.time || '';
        const level = document.createElement('span');
        level.className = 'level';
        level.textContent = e.level || '';
        level.style.color = 'var(--' + (['emergency', 'alert'].includes(e.level) ? 'critical' : e.level) + ')';
        const message = document.createElement('span');
        message.className = 'message';
        message.textContent = e.message || '';
        const rid = document.createElement('a');
        rid.className = 'rid';
        rid.href = '#';
        rid.textContent = e.request_id ? e.request_id.slice(0, 8) : '';
        rid.title = e.request_id ? 'Show only request ' + e.request_id : '';
        rid.addEventListener('click', (ev) => { ev.preventDefault(); ev.stopPropagation(); $('request').value = e.request_id || ''; reload(); });
        div.append(time, level, message, rid);
        div.addEventListener('click', (ev) => {
            if (ev.target.closest('pre')) return;
            const open = div.querySelector('pre');
            if (open) { open.remove(); return; }
            const pre = document.createElement('pre');
            pre.textContent = JSON.stringify(e, null, 2);
            div.append(pre);
        });
        return div;
    }

    function append(entries) {
        const stick = atBottom();
        for (const e of entries) list.append(row(e));
        while (list.children.length > MAX_ROWS) list.firstChild.remove();
        empty.hidden = list.children.length > 0;
        if (stick && entries.length) window.scrollTo(0, document.body.scrollHeight);
    }

    function setStatus() {
        const polling = live && state.file === state.today && !document.hidden;
        dot.className = 'dot' + (polling ? ' live' : '');
        statusText.textContent = polling ? 'Live' : (live ? 'Paused' : 'Not live');
    }

    async function reload() {
        clearTimeout(timer);
        list.replaceChildren();
        try {
            const data = await fetchJson(filters());
            state = { ...state, next: data.next, before: data.before, today: data.today };
            append(data.entries);
            older.hidden = data.before === 0;
            window.scrollTo(0, document.body.scrollHeight);
        } catch (err) { statusText.textContent = 'Error: ' + err.message; }
        lastNew = Date.now();
        schedule();
    }

    async function poll() {
        if (loading || !live || document.hidden) return schedule();
        loading = true;
        try {
            const p = filters();
            p.set('after', state.next);
            const data = await fetchJson(p);
            if (data.reset) list.replaceChildren();
            // A new day: if we were following today, follow the new file from its start.
            if (data.today !== state.today && state.file === state.today) {
                state = { ...state, file: data.today, next: 0, today: data.today };
                const opt = new Option(data.today.slice(5, 15) + ' (today)', data.today);
                $('file').prepend(opt);
                $('file').value = data.today;
            } else {
                state.next = data.next;
            }
            if (data.entries.length) { append(data.entries); lastNew = Date.now(); }
        } catch (err) { statusText.textContent = 'Error: ' + err.message; }
        loading = false;
        schedule();
    }

    function schedule() {
        clearTimeout(timer);
        setStatus();
        if (!live || state.file !== state.today) return;
        timer = setTimeout(poll, Date.now() - lastNew > IDLE_AFTER ? SLOW : FAST);
    }

    older.addEventListener('click', async () => {
        const p = filters();
        p.set('before', state.before);
        try {
            const data = await fetchJson(p);
            const top = document.body.scrollHeight;
            const frag = document.createDocumentFragment();
            for (const e of data.entries) frag.append(row(e));
            list.prepend(frag);
            state.before = data.before;
            older.hidden = data.before === 0;
            window.scrollBy(0, document.body.scrollHeight - top);
        } catch (err) { statusText.textContent = 'Error: ' + err.message; }
    });

    $('live').addEventListener('click', () => { live = !live; $('live').classList.toggle('on', live); lastNew = Date.now(); schedule(); });
    $('file').addEventListener('change', () => { state.file = $('file').value; reload(); });
    $('level').addEventListener('change', reload);
    let typing;
    for (const id of ['request', 'q']) $(id).addEventListener('input', () => { clearTimeout(typing); typing = setTimeout(reload, 300); });
    document.addEventListener('visibilitychange', () => { lastNew = Date.now(); document.hidden ? setStatus() : poll(); });

    reload();
})();
</script>
</body>
</html>
