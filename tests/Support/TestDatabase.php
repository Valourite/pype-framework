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

        $db = new Connection([
            'driver' => $driver,
            'host' => getenv('PYPE_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('PYPE_TEST_DB_PORT') ?: ($driver === 'mysql' ? 3306 : 5432)),
            'database' => getenv('PYPE_TEST_DB_DATABASE') ?: 'pype_test',
            'username' => getenv('PYPE_TEST_DB_USERNAME') ?: ($driver === 'mysql' ? 'root' : 'postgres'),
            'password' => getenv('PYPE_TEST_DB_PASSWORD') ?: 'secret',
        ]);

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
}
