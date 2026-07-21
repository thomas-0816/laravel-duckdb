<?php

use DuckDb\DuckDbConnection;
use DuckDb\Query\Processors\DuckDbProcessor;
use DuckDb\Schema\Grammars\DuckDBGrammar as SchemaGrammar;

it('processes columns with various types', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE col_types (id INTEGER, label VARCHAR(255), active BOOLEAN, salary DOUBLE, bio TEXT, created DATE)');

    $grammar = new SchemaGrammar($connection);
    $sql = $grammar->compileColumns('main', 'col_types');
    $results = $connection->select($sql);

    $processor = new DuckDbProcessor();
    $columns = $processor->processColumns($results);

    expect($columns)->toHaveCount(6);
    expect($columns[0]['name'])->toBe('id');
    expect($columns[0]['type_name'])->toBe('integer');
    expect($columns[0]['nullable'])->toBeTrue();
    expect($columns[0]['auto_increment'])->toBeFalse();
    expect($columns[0]['collation'])->toBeNull();
    expect($columns[0]['comment'])->toBeNull();
    expect($columns[0]['generation'])->toBeNull();

    expect($columns[1]['name'])->toBe('label');
    expect($columns[1]['type_name'])->toBe('varchar');

    expect($columns[2]['name'])->toBe('active');
    expect($columns[2]['type_name'])->toBe('boolean');
});

it('processes columns with nullable and defaults', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec("CREATE TABLE col_defaults (id INTEGER NOT NULL, name TEXT DEFAULT 'hello', count INTEGER DEFAULT 0, email TEXT)");

    $grammar = new SchemaGrammar($connection);
    $sql = $grammar->compileColumns('main', 'col_defaults');
    $results = $connection->select($sql);

    $processor = new DuckDbProcessor();
    $columns = $processor->processColumns($results);

    expect($columns[0]['name'])->toBe('id');
    expect($columns[0]['nullable'])->toBeFalse();
    expect($columns[0]['default'])->toBeNull();

    expect($columns[1]['name'])->toBe('name');
    expect($columns[1]['nullable'])->toBeTrue();
    expect($columns[1]['default'])->toBe("'hello'");

    expect($columns[2]['name'])->toBe('count');
    expect($columns[2]['nullable'])->toBeTrue();
    expect($columns[2]['default'])->toBe('0');

    expect($columns[3]['name'])->toBe('email');
    expect($columns[3]['nullable'])->toBeTrue();
    expect($columns[3]['default'])->toBeNull();
});

it('processes columns with cid ordering', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ordered (z INTEGER, a TEXT, m DOUBLE)');

    $grammar = new SchemaGrammar($connection);
    $sql = $grammar->compileColumns('main', 'ordered');
    $results = $connection->select($sql);

    $processor = new DuckDbProcessor();
    $columns = $processor->processColumns($results);

    expect($columns[0]['name'])->toBe('z');
    expect($columns[1]['name'])->toBe('a');
    expect($columns[2]['name'])->toBe('m');
});

it('processes indexes with primary key', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE indexed (id INTEGER PRIMARY KEY, name TEXT)');

    $results = getTableConstraints($connection, 'indexed', 'main', ['PRIMARY KEY', 'UNIQUE']);

    $processor = new DuckDbProcessor();
    $indexes = $processor->processIndexes($results);

    expect($indexes)->toHaveCount(1);
    expect($indexes[0]['name'])->toBe('primary');
    expect($indexes[0]['primary'])->toBeTrue();
    expect($indexes[0]['unique'])->toBeTrue();
    expect($indexes[0]['columns'])->toBe(['id']);
    expect($indexes[0]['type'])->toBeNull();
});

it('processes indexes with unique constraint', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE unique_idx (id INTEGER, slug TEXT UNIQUE)');

    $results = getTableConstraints($connection, 'unique_idx', 'main', ['PRIMARY KEY', 'UNIQUE']);

    $processor = new DuckDbProcessor();
    $indexes = $processor->processIndexes($results);

    $nonPrimary = array_values(array_filter($indexes, fn($i) => !$i['primary']));

    expect($nonPrimary)->toHaveCount(1);
    expect($nonPrimary[0]['unique'])->toBeTrue();
    expect($nonPrimary[0]['primary'])->toBeFalse();
    expect($nonPrimary[0]['columns'])->toBe(['slug']);
});

it('processes composite primary key', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE composite (a INTEGER, b INTEGER, PRIMARY KEY (a, b))');

    $results = getTableConstraints($connection, 'composite', 'main', ['PRIMARY KEY', 'UNIQUE']);

    $processor = new DuckDbProcessor();
    $indexes = $processor->processIndexes($results);

    expect($indexes)->toHaveCount(1);
    expect($indexes[0]['name'])->toBe('primary');
    expect($indexes[0]['columns'])->toBe(['a', 'b']);
});

it('filters out named primary when multiple primary keys exist in input', function () {
    $results = [
        (object) ['name' => 'primary', 'columns' => 'a', 'unique' => 1, 'primary' => 1],
        (object) ['name' => 'primary', 'columns' => 'b', 'unique' => 1, 'primary' => 1],
    ];

    $processor = new DuckDbProcessor();
    $indexes = $processor->processIndexes($results);

    expect($indexes)->toBeEmpty();
});

it('leaves non-primary indexes untouched when multiple primaries filtered', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE multi_pk (id INTEGER PRIMARY KEY, uid INTEGER UNIQUE, slug TEXT)');

    $results = getTableConstraints($connection, 'multi_pk', 'main', ['PRIMARY KEY', 'UNIQUE']);

    $processor = new DuckDbProcessor();
    $indexes = $processor->processIndexes($results);

    $nonPrimary = array_values(array_filter($indexes, fn($i) => !$i['primary']));
    expect($nonPrimary)->toHaveCount(1);
    expect($nonPrimary[0]['unique'])->toBeTrue();
    expect($nonPrimary[0]['primary'])->toBeFalse();
    expect($nonPrimary[0]['columns'])->toBe(['uid']);
});

it('processes foreign keys', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->getPdo()->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id), title TEXT)');

    $fks = getTableConstraints($connection, 'posts', 'main', ['FOREIGN KEY']);
    $fkCols = getKeyColumnUsage($connection, 'posts', 'main', true);
    $refs = $connection->select("SELECT constraint_name, unique_constraint_name, update_rule, delete_rule FROM information_schema.referential_constraints");
    $uniqueCols = getKeyColumnUsage($connection, null, null, false);

    $refLookup = [];
    foreach ($refs as $r) {
        $refLookup[$r->constraint_name] = $r;
    }
    $uniqueLookup = [];
    foreach ($uniqueCols as $uc) {
        $uniqueLookup[$uc->constraint_name][] = $uc;
    }

    $lookup = [];
    foreach ($fkCols as $c) {
        $lookup[$c->constraint_name][] = $c->column_name;
    }

    $results = [];
    foreach ($fks as $fk) {
        $ref = $refLookup[$fk->name] ?? null;
        $uniqueName = $ref->unique_constraint_name ?? '';
        $uniqueInfo = $uniqueLookup[$uniqueName] ?? [];
        $foreignTable = $uniqueInfo[0]->table_name ?? 'users';
        $foreignCols = array_map(fn($u) => $u->column_name, $uniqueInfo);

        $results[] = (object) [
            'name' => $fk->name,
            'columns' => implode(',', $lookup[$fk->name] ?? []),
            'foreign_schema' => 'main',
            'foreign_table' => $foreignTable,
            'foreign_columns' => implode(',', $foreignCols),
            'on_update' => strtolower($ref->update_rule ?? 'NO ACTION'),
            'on_delete' => strtolower($ref->delete_rule ?? 'NO ACTION'),
        ];
    }

    $processor = new DuckDbProcessor();
    $processed = $processor->processForeignKeys($results);

    expect($processed)->toHaveCount(1);
    expect($processed[0]['columns'])->toBe(['user_id']);
    expect($processed[0]['foreign_table'])->toBe('users');
    expect($processed[0]['foreign_columns'])->toBe(['id']);
    expect($processed[0]['foreign_schema'])->toBe('main');
    expect($processed[0]['on_update'])->toBe('no action');
    expect($processed[0]['on_delete'])->toBe('no action');
    expect($processed[0]['name'])->toBeString();
});

it('normalizes type names by stripping parameterised suffix', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE typed (points DECIMAL(10,2), label VARCHAR)');

    $grammar = new SchemaGrammar($connection);
    $sql = $grammar->compileColumns('main', 'typed');
    $results = $connection->select($sql);

    $processor = new DuckDbProcessor();
    $columns = $processor->processColumns($results);

    expect($columns[0]['name'])->toBe('points');
    expect($columns[0]['type_name'])->toBe('decimal');
    expect($columns[0]['type'])->toBe('decimal(10,2)');

    expect($columns[1]['name'])->toBe('label');
    expect($columns[1]['type_name'])->toBe('varchar');
    expect($columns[1]['type'])->toBe('varchar');
});

it('marks nullable as false only when explicitly set not null', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE nullable_test (req INTEGER NOT NULL, opt TEXT)');

    $grammar = new SchemaGrammar($connection);
    $sql = $grammar->compileColumns('main', 'nullable_test');
    $results = $connection->select($sql);

    $processor = new DuckDbProcessor();
    $columns = $processor->processColumns($results);

    expect($columns[0]['name'])->toBe('req');
    expect($columns[0]['nullable'])->toBeFalse();

    expect($columns[1]['name'])->toBe('opt');
    expect($columns[1]['nullable'])->toBeTrue();
});
