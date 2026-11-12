<?php

declare(strict_types=1);

/**
 * A small app for the sandbox's tests: users and items, a form route that writes, mails and
 * queues, and the development screens. The test builds it in its own process; the sandbox builds
 * it again in `bin/pype` (a stub the test writes that requires this file), in sandbox mode.
 *
 *   $app = (require 'app.php')($basePath);
 */

namespace Pype\Tests\Fixtures;

use Pype\Application;
use Pype\Auth\Authenticate;
use Pype\Auth\AuthProvider;
use Pype\Database\Connection;
use Pype\Database\DatabaseProvider;
use Pype\Database\Seeder;
use Pype\Debug\DebugProvider;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Mail\Mailer;
use Pype\Mail\MailProvider;
use Pype\Mail\Message;
use Pype\Queue\Queue;
use Pype\Queue\QueueProvider;
use Pype\Session\SessionProvider;

if (!class_exists(SandboxUser::class, false)) {
    final class SandboxUser
    {
        public function __construct(public readonly int $id, public readonly string $name, public readonly string $email)
        {
        }
    }

    final class SandboxSeeder extends Seeder
    {
        public function run(Connection $db): void
        {
            $db->execute("INSERT INTO users (name, email, password) VALUES ('Ada', 'ada@x.test', 'x'), ('Bob', 'bob@x.test', 'x')");
            $db->execute("INSERT INTO items (name, price, secret_token) VALUES ('apple', 3, 'tok-1'), ('pear', 5, NULL), ('fig', 9, NULL)");
        }
    }

    final class SandboxJob
    {
        public function __construct(public readonly int $itemId)
        {
        }

        public function handle(): void
        {
        }
    }
}

return static function (string $base): Application {
    $app = new Application($base, [
        'app' => ['env' => 'local', 'debug' => true, 'key' => 'base64:' . base64_encode(str_repeat('k', 32))],
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $base . '/real.sqlite']], 'migrations' => 'migrations'],
        'auth' => ['user' => SandboxUser::class],
        'session' => ['driver' => 'file', 'path' => 'sessions'],
        'mail' => ['driver' => 'log', 'from' => ['address' => 'app@x.test', 'name' => 'App']],
        'queue' => ['driver' => 'sync'],
        'cache' => ['driver' => 'file'],
        'debug' => ['sandbox' => ['seeder' => SandboxSeeder::class, 'php' => PHP_BINARY]],
    ]);

    foreach ([DatabaseProvider::class, AuthProvider::class, SessionProvider::class, MailProvider::class, QueueProvider::class, DebugProvider::class] as $provider) {
        $app->register($provider);
    }

    $app->router->get('/items/{id}', fn (Connection $db, int $id) => $db->first('SELECT id, name, price FROM items WHERE id = ?', [$id]) ?? Response::error(404, 'No such item.'));
    $app->router->group('/', ['web'], function ($r) {
        $r->get('/me', [Authenticate::class, fn (SandboxUser $user) => ['name' => $user->name]]);
        $r->post('/items', function (Request $request, Connection $db, Mailer $mailer, Queue $queue) {
            $id = (int) $db->table('items')->insert(['name' => (string) $request->input('name'), 'price' => (int) $request->input('price')]);
            $mailer->send(Message::to('owner@x.test')->subject('New item: ' . $request->input('name'))->text('Priced at ' . $request->input('price')));
            $queue->push(new SandboxJob($id));
            echo 'printed by the app';

            return Response::redirect("/items/{$id}", 303);
        });
        $r->patch('/items/{id}', fn (Request $request, Connection $db, int $id) => ['changed' => $db->table('items')->where('id', $id)->update(['price' => (int) $request->input('price'), 'secret_token' => 'tok-new'])]);
        $r->delete('/items/{id}', fn (Connection $db, int $id) => ['deleted' => $db->table('items')->where('id', $id)->delete()]);
    });

    return $app;
};
