<?php

use Pype\Database\Schema\Migration;
use Pype\Database\Schema\Schema;
use Pype\Database\Schema\Table;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('items', function (Table $t) {
            $t->id();
            $t->string('name');
            $t->integer('price');
        });
        $schema->raw("INSERT INTO items (name, price) VALUES ('apple', 3), ('pear', 5), ('fig', 9)");
    }
};
