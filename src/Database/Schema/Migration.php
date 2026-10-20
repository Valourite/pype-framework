<?php

declare(strict_types=1);

namespace Pype\Database\Schema;

/**
 * A PHP migration: database/migrations/2026_10_15_000000_create_users_table.php
 *
 *   return new class extends Migration {
 *       public function up(Schema $schema): void
 *       {
 *           $schema->create('users', function (Table $t) {
 *               $t->id();
 *               $t->string('email')->unique();
 *               $t->timestamps();
 *           });
 *       }
 *
 *       public function down(Schema $schema): void
 *       {
 *           $schema->dropIfExists('users');
 *       }
 *   };
 *
 * The same file runs on SQLite, MySQL and Postgres. down() undoes up() for
 * `bin/pype migrate:rollback`; leave it out and the migration can't be rolled back.
 */
abstract class Migration
{
    abstract public function up(Schema $schema): void;

    /** Undo up(). Not overriding it makes the migration irreversible (Migrator checks). */
    public function down(Schema $schema): void
    {
        throw new \LogicException(static::class . ' has no down() method.');
    }
}
