<?php

use Pype\Database\Schema\Migration;
use Pype\Database\Schema\Schema;
use Pype\Database\Schema\Table;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('users', function (Table $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
        });
        $schema->create('items', function (Table $t) {
            $t->id();
            $t->string('name');
            $t->integer('price');
            $t->string('secret_token')->nullable();
        });
    }
};
