<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Database\Connection;
use Pype\Debug\Sandbox;
use Pype\Debug\TableDiff;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;

/**
 * The development sandbox (/_pype/sandbox): a database built from the app's migrations and seeder,
 * requests run against a copy of it in a process of their own, and what each one changed.
 */
final class DebugSandboxTest extends TestCase
{
    private string $base;
    private Application $app;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('The sandbox needs proc_open().');
        }

        $this->base = sys_get_temp_dir() . '/pype-sandbox-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/bin', 0775, true);
        mkdir($this->base . '/migrations');
        $fixture = dirname(__DIR__) . '/fixtures/sandbox/app.php';
        copy(dirname($fixture) . '/migrations/2026_01_01_000000_create_users_and_items.php', $this->base . '/migrations/2026_01_01_000000_create_users_and_items.php');
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        // The app's console, as an app has it: the sandbox starts it for building and for each run.
        file_put_contents($this->base . '/bin/pype', "<?php\nrequire " . var_export($autoload, true) . ";\n\$app = (require " . var_export($fixture, true) . ')(' . var_export($this->base, true) . ");\nexit((new Pype\\Console\\Console(\$app))->run(\$argv));\n");

        putenv('PYPE_SANDBOX');
        unset($_ENV['PYPE_SANDBOX']);
        $this->app = (require $fixture)($this->base);
    }

    protected function tearDown(): void
    {
        if (!isset($this->base)) {
            return;
        }

        putenv('PYPE_SANDBOX');
        unset($_ENV['PYPE_SANDBOX']);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->base);
    }

    private function sandbox(): Sandbox
    {
        return $this->app->container->get(Sandbox::class);
    }

    public function test_changes_are_noted_row_by_row_whatever_the_size_of_the_table(): void
    {
        $db = TestDatabase::fresh();
        if ($db->driver() !== 'sqlite') {
            $this->markTestSkipped('The sandbox is always SQLite.');
        }

        $db->statement('CREATE TABLE things (id INTEGER PRIMARY KEY, name VARCHAR(50), password VARCHAR(50), photo BLOB)');
        $db->statement('CREATE TABLE tags (thing_id INTEGER, tag VARCHAR(20))');
        $db->execute("INSERT INTO things (name, password) VALUES ('kept', 'p'), ('edited', 'p'), ('dropped', 'p')");

        $watch = TableDiff::watch($db);
        $this->assertSame(['tags' => 0, 'things' => 3], $watch['counts']);

        $db->execute("UPDATE things SET name = 'edited twice', password = 'q' WHERE id = 2");
        $db->execute("UPDATE things SET name = 'edited again' WHERE id = 2");
        $db->execute('DELETE FROM things WHERE id = 3');
        $db->execute("INSERT INTO things (name, password, photo) VALUES ('made', 's3cret', x'00ff10')");
        $db->execute("INSERT INTO things (name) VALUES ('made and dropped')");
        $db->execute("DELETE FROM things WHERE name = 'made and dropped'");
        $db->execute("INSERT INTO tags (thing_id, tag) VALUES (1, 'red')");
        $db->execute("UPDATE things SET name = 'kept' WHERE id = 1");   // written, but the same

        $changes = TableDiff::changes($db, $watch);
        $this->assertSame(['tags', 'things'], array_keys($changes));

        $things = $changes['things'];
        $this->assertSame([3, 3], [$things['before'], $things['after']]);
        $this->assertSame([['id' => 3, 'name' => 'made', 'password' => '•••• hidden', 'photo' => '(3 bytes)']], $things['inserted'], 'a secret column is hidden, a blob is its size; SQLite gave it the deleted row\'s id, and it is still a new row');
        $this->assertSame(['dropped'], array_column($things['deleted'], 'name'));
        $this->assertSame([['key' => 'id 2', 'columns' => ['name' => ['edited', 'edited again'], 'password' => ['•••• hidden', '•••• hidden']]]], $things['changed'], 'two updates of a row are one change, from how it was to how it is');
        $this->assertSame([['thing_id' => 1, 'tag' => 'red']], $changes['tags']['inserted'], 'a table with no primary key is followed by rowid');

        // Nothing is left in the file: the triggers and the notes are this connection's own.
        $this->assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'pype_s%'"));
        $this->assertSame([], TableDiff::changes($db, TableDiff::watch($db)), 'watching again starts clean');
    }

    public function test_it_is_built_from_the_apps_migrations_and_seeder_and_leaves_the_real_database_alone(): void
    {
        $this->assertSame(['built' => false, 'at' => null, 'stale' => false, 'seeder' => null, 'tables' => [], 'carrying' => false], $this->sandbox()->status());

        $out = $this->sandbox()->build();
        $status = $this->sandbox()->status();

        $this->assertStringContainsString('migrated and seeded', $out);
        $this->assertTrue($status['built']);
        $this->assertFalse($status['stale']);
        $this->assertSame(['items' => 3, 'users' => 2], $status['tables']);
        $this->assertSame(['1', '2', '3'], $this->sandbox()->ids('items'));
        $this->assertSame([], $this->sandbox()->ids('nowhere'));
        $this->assertSame([['id' => 1, 'label' => '#1 Ada · ada@x.test'], ['id' => 2, 'label' => '#2 Bob · bob@x.test']], $this->sandbox()->users());
        $this->assertFileDoesNotExist($this->base . '/real.sqlite', 'the app\'s own database was never opened');
        $this->assertFileDoesNotExist($this->base . '/storage/debug/sandbox/base.sqlite-wal', 'one file, so a copy is a plain copy');
    }

    public function test_a_request_runs_against_a_copy_and_says_what_it_did(): void
    {
        $this->sandbox()->build();

        $result = $this->sandbox()->run(['method' => 'POST', 'uri' => '/items', 'body' => ['name' => 'plum', 'price' => '7'], 'user' => 2]);

        $this->assertSame([303, '/items/4'], [$result['response']['status'], $result['response']['location']]);
        $this->assertSame('printed by the app', $result['printed']);
        $this->assertCount(2, $result['notes'], 'signed in, and the CSRF token added');
        $this->assertSame('•••• hidden', $result['request']['body']['_token']);

        // The row it wrote, the mail it sent and the job it queued: none of it left the process.
        $this->assertSame(['items'], array_keys($result['changes']));
        $this->assertSame([3, 4], [$result['changes']['items']['before'], $result['changes']['items']['after']]);
        $this->assertSame([['id' => 4, 'name' => 'plum', 'price' => 7, 'secret_token' => '•••• hidden']], $result['changes']['items']['inserted']);
        $this->assertSame([['subject' => 'New item: plum', 'to' => ['owner@x.test'], 'text' => 'Priced at 7']], $result['mail']);
        $this->assertSame([['class' => 'Pype\Tests\Fixtures\SandboxJob', 'data' => '{"itemId":4}']], $result['jobs']);

        // Stage by stage, with the query in the stage that ran it.
        $stages = array_column($result['trace']['stages'], 'name');
        $this->assertSame(['StartSession', 'VerifyCsrfToken', 'FlashValidationErrors', 'Closure'], $stages);
        $this->assertStringContainsString('items', end($result['trace']['stages'])['queries'][0]['sql']);

        // The base is as it was built, and the app's own database still doesn't exist.
        $base = new Connection(['driver' => 'sqlite', 'database' => $this->sandbox()->base()]);
        $this->assertSame(3, (int) $base->scalar('SELECT COUNT(*) FROM items'));
        $this->assertFileDoesNotExist($this->base . '/real.sqlite');
        $this->assertDirectoryDoesNotExist($this->base . '/sessions', 'sessions were kept in memory');
    }

    public function test_who_it_is_sent_as_and_carrying_on_from_the_last_run(): void
    {
        $this->sandbox()->build();

        $this->assertSame('{"data":{"name":"Bob"}}', $this->sandbox()->run(['uri' => '/me', 'user' => 2])['response']['body']);
        $this->assertSame(401, $this->sandbox()->run(['uri' => '/me'])['response']['status']);

        // Each run starts from the base again...
        $this->sandbox()->run(['method' => 'DELETE', 'uri' => '/items/1']);
        $this->assertSame(200, $this->sandbox()->run(['uri' => '/items/1'])['response']['status']);

        // ...unless it carries on: delete, then look for what was deleted.
        $deleted = $this->sandbox()->run(['method' => 'DELETE', 'uri' => '/items/1']);
        $this->assertSame(['apple'], array_column($deleted['changes']['items']['deleted'], 'name'));
        $this->assertTrue($this->sandbox()->status()['carrying']);
        $this->assertSame(404, $this->sandbox()->run(['uri' => '/items/1'], carryOn: true)['response']['status']);
        $this->assertSame(['2', '3'], $this->sandbox()->ids('items'), 'the form offers what is there now');

        $changed = $this->sandbox()->run(['method' => 'PATCH', 'uri' => '/items/2', 'body' => ['price' => 50]], carryOn: true);
        $this->assertSame([['key' => 'id 2', 'columns' => ['price' => [5, 50], 'secret_token' => ['•••• hidden', '•••• hidden']]]], $changed['changes']['items']['changed']);
        $this->assertSame([], $this->sandbox()->run(['uri' => '/items/2'], carryOn: true)['changes'], 'a request that only reads changes nothing');

        $this->sandbox()->reset();
        $this->assertFalse($this->sandbox()->status()['carrying']);
        $this->assertSame(200, $this->sandbox()->run(['uri' => '/items/1'], carryOn: true)['response']['status']);
    }

    public function test_the_screen(): void
    {
        $client = new TestClient($this->app);

        $empty = $client->get('/_pype/sandbox');
        $this->assertSame(200, $empty->status());
        $this->assertStringContainsString('Build the sandbox', $empty->body());
        $this->assertStringContainsString('build it first', $client->post('/_pype/sandbox', ['action' => 'run', 'method' => 'GET', 'path' => '/items/1'])->body());

        $this->assertStringContainsString('migrated and seeded', $client->post('/_pype/sandbox', ['action' => 'build'])->body());

        // Opened from a route's page: the path with its {id}, ids offered from the items table.
        $form = $client->get('/_pype/sandbox?m=PATCH&p=' . rawurlencode('/items/{id}'))->body();
        $this->assertStringContainsString('name="params[id]"', $form);
        $this->assertStringContainsString('from items', $form);
        $this->assertStringContainsString('#2 Bob · bob@x.test', $form);
        $this->assertStringContainsString('try it in the sandbox', $client->get('/_pype/routes/show?m=PATCH&p=' . rawurlencode('/items/{id}'))->body());

        $ran = $client->post('/_pype/sandbox', ['action' => 'run', 'method' => 'PATCH', 'path' => '/items/{id}', 'params' => ['id' => '3'], 'body' => "price=12\n", 'user' => '1'])->body();
        $this->assertStringContainsString('PATCH /items/3', $ran);
        $this->assertStringContainsString('~1 changed', $ran);
        $this->assertStringContainsString('<td class="del">9</td><td class="ins">12</td>', $ran);
        $this->assertStringContainsString('VerifyCsrfToken', $ran, 'the stages, as on a recorded request');

        // JSON in the body box is sent as JSON; what can't be sent says why.
        $json = $client->post('/_pype/sandbox', ['action' => 'run', 'method' => 'POST', 'path' => '/items', 'body' => '{"name": "kiwi", "price": 4}', 'headers' => 'X-Test: yes', 'user' => '1'])->body();
        $this->assertStringContainsString('+1 new', $json);
        $this->assertStringContainsString('New item: kiwi', $json);
        $this->assertStringContainsString('SandboxJob', $json);
        $this->assertStringContainsString('Give a value for {id}', $client->post('/_pype/sandbox', ['action' => 'run', 'method' => 'GET', 'path' => '/items/{id}'])->body());
        $this->assertStringContainsString('looks like JSON', $client->post('/_pype/sandbox', ['action' => 'run', 'method' => 'POST', 'path' => '/items', 'body' => '{oops'])->body());

        $this->assertStringContainsString('Back to how it was built', $client->post('/_pype/sandbox', ['action' => 'reset'])->body());

        // Another site open in the same browser can't press the buttons.
        $this->assertSame(403, $client->post('/_pype/sandbox', ['action' => 'build'], ['Sec-Fetch-Site' => 'cross-site'])->status());
        $this->assertSame(403, $client->post('/_pype/sandbox', ['action' => 'build'], ['Origin' => 'https://evil.example', 'Host' => 'localhost:8000'])->status());
        $this->assertSame(200, $client->post('/_pype/sandbox', ['action' => 'reset'], ['Sec-Fetch-Site' => 'same-origin'])->status());
    }

    public function test_a_changed_migration_makes_it_stale_and_no_seeder_is_said(): void
    {
        $migrations = 'migrations';

        $app = new Application($this->base, ['app' => ['env' => 'local', 'debug' => true], 'database' => ['migrations' => $migrations], 'debug' => ['sandbox' => ['seeder' => 'NoSuchSeeder', 'php' => PHP_BINARY]]]);
        $app->register(\Pype\Debug\DebugProvider::class);
        file_put_contents($this->base . '/bin/pype', str_replace("\$app = ", "\$app = new Pype\\Application(" . var_export($this->base, true) . ", ['app' => ['env' => 'local', 'debug' => true], 'database' => ['migrations' => " . var_export($migrations, true) . "], 'debug' => ['sandbox' => ['seeder' => 'NoSuchSeeder']]]); \$app->register(Pype\\Database\\DatabaseProvider::class)->register(Pype\\Debug\\DebugProvider::class); \$unused = ", (string) file_get_contents($this->base . '/bin/pype')));

        $sandbox = $app->container->get(Sandbox::class);
        $this->assertStringContainsString('not seeded (no NoSuchSeeder)', $sandbox->build());
        $this->assertSame([null, ['items' => 0, 'users' => 0], false], [$sandbox->status()['seeder'], $sandbox->status()['tables'], $sandbox->status()['stale']]);

        file_put_contents($this->base . '/migrations/2026_01_02_000000_more.sql', 'CREATE TABLE more (id INTEGER PRIMARY KEY);');
        $this->assertTrue($sandbox->status()['stale']);
        $sandbox->build();
        $this->assertSame([false, ['items', 'more', 'users']], [$sandbox->status()['stale'], array_keys($sandbox->status()['tables'])]);
    }

    public function test_sandbox_mode_is_for_debug_only(): void
    {
        $file = $this->base . '/never.sqlite';
        putenv('PYPE_SANDBOX=' . $file);

        $config = ['database' => ['default' => 'mysql', 'connections' => ['mysql' => ['driver' => 'mysql']]], 'mail' => ['driver' => 'smtp'], 'queue' => ['driver' => 'database'], 'session' => ['driver' => 'database'], 'cache' => ['driver' => 'file']];

        foreach ([['env' => 'production', 'debug' => true], ['env' => 'local', 'debug' => false]] as $app) {
            $real = new Application($this->base, ['app' => $app] + $config);
            $this->assertSame(['mysql', 'smtp', 'database', 'database', 'file', null], [$real->config('database.default'), $real->config('mail.driver'), $real->config('queue.driver'), $real->config('session.driver'), $real->config('cache.driver'), $real->config('app.sandbox')], 'the variable does nothing outside debug');
        }

        $sandboxed = new Application($this->base, ['app' => ['env' => 'local', 'debug' => true]] + $config);
        $this->assertSame(['sandbox', 'array', 'array', 'array', 'array', 'array', $file], [$sandboxed->config('database.default'), $sandboxed->config('mail.driver'), $sandboxed->config('queue.driver'), $sandboxed->config('session.driver'), $sandboxed->config('cache.driver'), $sandboxed->config('database.rate_limits.driver'), $sandboxed->config('app.sandbox')]);
        $this->assertSame(['sandbox' => ['driver' => 'sqlite', 'database' => $file]], $sandboxed->config('database.connections'), 'the app\'s own connections are not even kept');

        // The runner refuses to run anywhere else.
        putenv('PYPE_SANDBOX');
        $this->expectException(\LogicException::class);
        (new \Pype\Debug\SandboxRun($this->app))->run(['uri' => '/items/1']);
    }
}
