<?php

declare(strict_types=1);

namespace Pype\Tests\Support;

use Pype\Database\Connection;
use Pype\Database\Schema\Schema;
use Pype\Database\Schema\Table;

/**
 * The database the suite runs against: SQLite in memory by default, or a real server with
 *   PYPE_TEST_DB=mysql|pgsql PYPE_TEST_DB_HOST=... PYPE_TEST_DB_PORT=... (see bin/test-databases.sh)
 */
final class TestDatabase
{
    public static function driver(): string
    {
        return getenv('PYPE_TEST_DB') ?: 'sqlite';
    }

    /** An empty database. */
    public static function fresh(): Connection
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            return Connection::sqlite();
        }

        $db = self::connect();

        if ($driver === 'pgsql') {
            $db->statement('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
        } else {
            $db->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($db->select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $row) {
                $db->statement('DROP TABLE ' . $db->quote($row['t']));
            }
            $db->statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $db;
    }

    /**
     * A new connection to the test server, without emptying it (e.g. one per forked process).
     * On SQLite pass a file: separate connections to ':memory:' are separate databases.
     */
    public static function connect(?string $sqliteFile = null): Connection
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            return Connection::sqlite($sqliteFile ?? ':memory:');
        }

        return new Connection([
            'driver' => $driver,
            'host' => getenv('PYPE_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('PYPE_TEST_DB_PORT') ?: ($driver === 'mysql' ? 3306 : 5432)),
            'database' => getenv('PYPE_TEST_DB_DATABASE') ?: 'pype_test',
            'username' => getenv('PYPE_TEST_DB_USERNAME') ?: ($driver === 'mysql' ? 'root' : 'postgres'),
            'password' => getenv('PYPE_TEST_DB_PASSWORD') ?: 'secret',
        ]);
    }

    /** users, api_tokens, rate_limits and sessions: the tables the auth and session tests need. */
    public static function withAuthTables(): Connection
    {
        $db = self::fresh();
        $schema = new Schema($db);

        $schema->create('users', function (Table $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->timestamp('email_verified_at')->nullable();
        });
        $schema->create('remember_tokens', function (Table $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete()->index();
            $t->string('selector', 24)->unique();
            $t->string('validator_hash', 64);
            $t->string('previous_hash', 64)->nullable();
            $t->bigInteger('rotated_at')->nullable();
            $t->bigInteger('expires_at');
            $t->bigInteger('last_used_at');
            $t->string('user_agent')->nullable();
        });
        $schema->create('password_resets', function (Table $t) {
            $t->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->bigInteger('created_at');
        });
        $schema->create('api_tokens', function (Table $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        $schema->create('rate_limits', function (Table $t) {
            $t->string('key', 64)->primary();
            $t->integer('hits');
            $t->bigInteger('reset_at');
        });
        $schema->create('sessions', function (Table $t) {
            $t->string('id', 64)->primary();
            $t->text('payload');
            $t->bigInteger('expires_at')->index();
        });

        return $db;
    }

    /** The cache table, as the skeleton's migration creates it. */
    public static function withCacheTable(): Connection
    {
        $db = self::fresh();

        (new Schema($db))->create('cache', function (Table $t) {
            $t->string('key')->primary();
            $t->longText('value');
            $t->bigInteger('expires_at')->nullable()->index();
        });

        return $db;
    }

    /** jobs and failed_jobs, as the skeleton's migrations create them. */
    public static function withQueueTables(?Connection $db = null): Connection
    {
        $db ??= self::fresh();
        self::queueTables(new Schema($db));

        return $db;
    }

    public static function queueTables(Schema $schema): void
    {
        $schema->create('jobs', function (Table $t) {
            $t->id();
            $t->string('queue', 64);
            $t->string('name');
            $t->longText('payload');
            $t->integer('attempts')->default(0);
            $t->bigInteger('available_at');
            $t->bigInteger('reserved_at')->nullable();
            $t->bigInteger('created_at');
            $t->index(['queue', 'available_at']);
        });
        $schema->create('failed_jobs', function (Table $t) {
            $t->id();
            $t->string('queue', 64);
            $t->string('name');
            $t->longText('payload');
            $t->longText('exception');
            $t->bigInteger('failed_at');
        });
    }
}
