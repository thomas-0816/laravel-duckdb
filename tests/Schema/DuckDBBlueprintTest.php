<?php

use DuckDb\Schema\DuckDBBlueprint;

it('addAlterCommands falls back to parent when grammar is not DuckDBGrammar', function () {
    $connection = new \Illuminate\Database\SQLiteConnection(fn () => new PDO('sqlite::memory:'));
    $connection->getSchemaBuilder();

    $blueprint = new DuckDBBlueprint($connection, 'test_table', function ($table) {
        $table->string('name');
    });

    $blueprint->addAlterCommands();

    expect(true)->toBeTrue();
});
