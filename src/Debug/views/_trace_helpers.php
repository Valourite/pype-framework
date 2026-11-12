<?php
/**
 * Small formatters shared by the recorded request's page and the sandbox's result.
 *
 * @var \Closure $h
 */
$short = static fn (string $class): string => str_contains($class, '\\') ? substr((string) strrchr($class, '\\'), 1) : $class;
$ms = static fn (float|int $v): string => number_format((float) $v, $v < 10 ? 2 : 1) . ' ms';
$tone = static fn (?int $status): string => match (true) {
    $status === null => 'muted', $status >= 500 => 'DELETE', $status >= 400 => 'PATCH', $status >= 300 => 'POST', default => 'GET',
};
$table = static function (array $rows) use ($h): void { ?>
    <table class="kv">
<?php foreach ($rows as $key => $value) { ?>
        <tr><td class="mono muted"><?= $h((string) $key) ?></td><td class="mono"><?= $h(is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></td></tr>
<?php } ?>
    </table>
<?php };
$queries = static function (array $list) use ($h, $ms): void { ?>
        <ol class="sql">
<?php foreach ($list as $q) { ?>
            <li><span class="t"><?= $ms($q['ms']) ?></span><code><?= $h((string) $q['sql']) ?></code><?php if (($q['bindings'] ?? []) !== []) { ?><div class="muted mono b">[<?= $h(implode(', ', $q['bindings'])) ?>]</div><?php } ?></li>
<?php } ?>
        </ol>
<?php };
