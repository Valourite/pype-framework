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
 *   };
 *
 * The same file runs on SQLite, MySQL and Postgres. Forward-only, like .sql migrations.
 */
abstract class Migration
{
    abstract public function up(Schema $schema): void;
}
