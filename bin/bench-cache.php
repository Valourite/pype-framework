<?php

/**
 * Micro-benchmark of the cache stores: get (hit), get (miss) and set, in µs per call.
 *
 *   bin/bench-cache.sh                         # in Docker: PHP 8.3 + opcache + APCu
 *   php -d opcache.enable_cli=1 -d opcache.file_update_protection=0 bin/bench-cache.php [iterations]
 *
 * file_update_protection=0: by default opcache won't cache a file changed after the current
 * request started, and a CLI run is one long request, so file hits would never come from
 * opcache. PHP-FPM serves them from opcache from the next request on (see the README).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Pype\Cache\ApcuStore;
use Pype\Cache\ArrayStore;
use Pype\Cache\Cache;
use Pype\Cache\DatabaseStore;
use Pype\Cache\FileStore;
use Pype\Database\Connection;
use Pype\Database\Schema\Schema;
use Pype\Database\Schema\Table;

$n = (int) ($argv[1] ?? 20000);
$keys = 100;   // a working set of 100 keys, read round-robin
$value = ['id' => 42, 'name' => 'Groceries', 'totals' => array_fill(0, 12, 1234), 'label' => str_repeat('x', 200)];
$tmp = sys_get_temp_dir() . '/pype-bench-' . bin2hex(random_bytes(4));

$sqlite = Connection::sqlite("{$tmp}.sqlite");
(new Schema($sqlite))->create('cache', function (Table $t) {
    $t->string('key')->primary();
    $t->longText('value');
    $t->bigInteger('expires_at')->nullable()->index();
});

$stores = [
    'array' => fn () => new ArrayStore(),
    'file' => fn () => new FileStore("{$tmp}-files"),
    'apcu' => fn () => function_exists('apcu_enabled') && apcu_enabled() ? new ApcuStore() : null,
    'sqlite (file, WAL)' => fn () => new DatabaseStore($sqlite),
];

$time = function (Closure $op) use ($n): float {
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $op($i);
    }

    return (hrtime(true) - $start) / $n / 1000;
};

printf("PHP %s, opcache %s, %d calls each, value ~%d bytes serialized\n\n", PHP_VERSION,
    function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false) ? 'on' : 'off', $n, strlen(serialize($value)));
printf("%-20s %12s %12s %12s\n", 'store', 'get hit µs', 'get miss µs', 'set µs');

foreach ($stores as $name => $make) {
    $store = $make();

    if ($store === null) {
        printf("%-20s %s\n", $name, 'skipped (no APCu)');
        continue;
    }

    $cache = new Cache($store, 'bench.');
    $set = $time(fn ($i) => $cache->set('k' . ($i % $keys), $value, 3600));

    $cache->get('k0');   // warm
    $hit = $time(fn ($i) => $cache->get('k' . ($i % $keys)));
    $miss = $time(fn ($i) => $cache->get('missing' . ($i % $keys)));

    printf("%-20s %12.2f %12.2f %12.2f\n", $name, $hit, $miss, $set);
    $cache->clear();
}

@unlink("{$tmp}.sqlite");
@rmdir("{$tmp}-files");
