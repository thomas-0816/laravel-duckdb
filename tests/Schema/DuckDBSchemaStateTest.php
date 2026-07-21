<?php

use DuckDb\DuckDbConnection;
use DuckDb\Schema\DuckDBSchemaState;
use Illuminate\Filesystem\Filesystem;

it('loads a schema file and executes the sql', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $state = new DuckDBSchemaState($connection, new Filesystem());
    $path = tempnam(sys_get_temp_dir(), 'duckdb_schema_');

    file_put_contents($path, '
        CREATE TABLE users (id INTEGER, name TEXT);
        INSERT INTO users VALUES (1, \'Alice\');
        INSERT INTO users VALUES (2, \'Bob\');
    ');

    $state->load($path);

    $users = $connection->select('SELECT * FROM users ORDER BY id');
    expect($users)->toHaveCount(2);
    expect($users[0]->name)->toBe('Alice');
    expect($users[1]->name)->toBe('Bob');

    unlink($path);
});

it('loads a schema file with multiple statements', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $state = new DuckDBSchemaState($connection, new Filesystem());
    $path = tempnam(sys_get_temp_dir(), 'duckdb_schema_');

    file_put_contents($path, '
        CREATE TABLE posts (id INTEGER, title TEXT);
        CREATE TABLE comments (id INTEGER, post_id INTEGER, body TEXT);
        INSERT INTO posts VALUES (1, \'Hello\');
        INSERT INTO comments VALUES (1, 1, \'Nice post\');
    ');

    $state->load($path);

    expect($connection->select('SELECT * FROM posts'))->toHaveCount(1);
    expect($connection->select('SELECT * FROM comments'))->toHaveCount(1);

    unlink($path);
});

it('loads a schema file with only a comment', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $state = new DuckDBSchemaState($connection, new Filesystem());
    $path = tempnam(sys_get_temp_dir(), 'duckdb_schema_');

    file_put_contents($path, '-- this is a comment');

    $state->load($path);

    $tables = getTableNames($connection, 'main');
    expect($tables)->toHaveCount(0);

    unlink($path);
});

it('throws when loading a nonexistent file', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $state = new DuckDBSchemaState($connection, new Filesystem());

    $state->load('/nonexistent/path.sql');
})->throws(Exception::class);

it('dumps schema to a file including migration data and cleans up temp directory', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE tools (id INTEGER, name TEXT)');
    $connection->getPdo()->exec("INSERT INTO tools VALUES (1, 'hammer')");
    $connection->getPdo()->exec('CREATE TABLE migrations (id INTEGER, migration TEXT, batch INTEGER)');
    $connection->getPdo()->exec("INSERT INTO migrations VALUES (1, '2024_01_01_000001_create_users_table', 1)");

    $state = new DuckDBSchemaState($connection, new Filesystem());
    $path = tempnam(sys_get_temp_dir(), 'duckdb_dump_');

    $state->dump($connection, $path);

    $contents = file_get_contents($path);
    expect($contents)->toContain('CREATE TABLE tools');
    expect($contents)->toContain('INSERT INTO "migrations"');
    expect($contents)->not->toContain('INSERT INTO tools');

    $tempDirs = glob(sys_get_temp_dir() . '/duckdb_export_*');
    expect($tempDirs)->toBeEmpty();

    unlink($path);
});

it('can set migration table and dump includes its data', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE custom_migrations (id INTEGER, migration TEXT, batch INTEGER)');
    $connection->getPdo()->exec("INSERT INTO custom_migrations VALUES (1, 'create_users_table', 1)");
    $state = new DuckDBSchemaState($connection);
    $state->withMigrationTable('custom_migrations');

    $path = tempnam(sys_get_temp_dir(), 'duckdb_dump_');
    $state->dump($connection, $path);

    $contents = file_get_contents($path);
    expect($contents)->toContain('INSERT INTO "custom_migrations"');

    unlink($path);
});

it('detects whether migration table exists', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    $state = new DuckDBSchemaState($connection);
    expect($state->hasMigrationTable())->toBeFalse();

    $connection->getPdo()->exec('CREATE TABLE migrations (id INTEGER, migration TEXT, batch INTEGER)');
    expect($state->hasMigrationTable())->toBeTrue();
});

it('handles output callback without breaking load', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $called = false;
    $state = new DuckDBSchemaState($connection);
    $state->handleOutputUsing(function ($type, $buffer) use (&$called) {
        $called = true;
    });

    $path = tempnam(sys_get_temp_dir(), 'duckdb_schema_');
    file_put_contents($path, 'CREATE TABLE callback_test (id INTEGER);');
    $state->load($path);

    expect($called)->toBeFalse();
    $tableNames = array_column(getTableNames($connection, 'main'), 'table_name');
    expect($tableNames)->toContain('callback_test');

    unlink($path);
});
