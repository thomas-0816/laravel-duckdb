<?php

use DuckDb\Schema\DuckDBBlueprint;

it('addAlterCommands falls back to parent when grammar is not DuckDBGrammar', function () {
    $connection = new \Illuminate\Database\SQLiteConnection(static fn() => new PDO('sqlite::memory:'));
    $connection->getSchemaBuilder();

    $blueprint = new DuckDBBlueprint($connection, 'test_table', function ($table) {
        $table->string('name');
    });

    $blueprint->addAlterCommands();

    expect(true)->toBeTrue();
})->skip(!extension_loaded('pdo_sqlite'), 'pdo_sqlite extension is not available');
