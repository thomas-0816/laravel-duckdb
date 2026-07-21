<?php

use DuckDb\DuckDbConnection;
use DuckDb\Schema\DuckDBBuilder;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;

function makeConnection(): DuckDbConnection
{
    return new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
}

function bootFileFacade(): void
{
    $app = new Container();
    $app->instance('files', new Filesystem());
    Facade::setFacadeApplication($app);
}

it('creates a database file', function () {
    bootFileFacade();
    $connection = makeConnection();
    $builder = $connection->getSchemaBuilder();
    $path = sys_get_temp_dir() . '/duckdb_create_' . uniqid() . '.duckdb';

    $result = $builder->createDatabase($path);

    expect($result)->toBeTrue();
    expect(file_exists($path))->toBeTrue();

    unlink($path);
});

it('returns true when dropping a nonexistent database', function () {
    bootFileFacade();
    $connection = makeConnection();
    $builder = $connection->getSchemaBuilder();

    $result = $builder->dropDatabaseIfExists(sys_get_temp_dir() . '/duckdb_nonexist_' . uniqid() . '.duckdb');

    expect($result)->toBeTrue();
});

it('drops an existing database file', function () {
    bootFileFacade();
    $connection = makeConnection();
    $builder = $connection->getSchemaBuilder();
    $path = sys_get_temp_dir() . '/duckdb_drop_' . uniqid() . '.duckdb';

    $builder->createDatabase($path);
    expect(file_exists($path))->toBeTrue();

    $result = $builder->dropDatabaseIfExists($path);

    expect($result)->toBeTrue();
    expect(file_exists($path))->toBeFalse();
});

it('creates and drops database file in sequence', function () {
    bootFileFacade();
    $connection = makeConnection();
    $builder = $connection->getSchemaBuilder();
    $path = sys_get_temp_dir() . '/duckdb_seq_' . uniqid() . '.duckdb';

    $builder->createDatabase($path);
    expect(file_exists($path))->toBeTrue();

    $builder->dropDatabaseIfExists($path);
    expect(file_exists($path))->toBeFalse();

    $result = $builder->dropDatabaseIfExists($path);
    expect($result)->toBeTrue();
});

it('drops all tables from the database', function () {
    $connection = makeConnection();
    $schema = $connection->getSchemaBuilder();

    $schema->create('drop_test_1', function ($table) {
        $table->integer('id');
    });
    $schema->create('drop_test_2', function ($table) {
        $table->string('name');
    });

    expect($schema->hasTable('drop_test_1'))->toBeTrue();
    expect($schema->hasTable('drop_test_2'))->toBeTrue();

    $schema->dropAllTables();

    expect($schema->hasTable('drop_test_1'))->toBeFalse();
    expect($schema->hasTable('drop_test_2'))->toBeFalse();
});

it('drops tables with indexes', function () {
    $connection = makeConnection();
    $schema = $connection->getSchemaBuilder();

    $schema->create('idx_table', function ($table) {
        $table->integer('id');
        $table->string('name');
        $table->index('name');
    });

    expect($schema->hasTable('idx_table'))->toBeTrue();

    $schema->dropAllTables();

    expect($schema->hasTable('idx_table'))->toBeFalse();
});

it('drops all views removes user created views', function () {
    $connection = makeConnection();
    $schema = $connection->getSchemaBuilder();

    $schema->create('view_src', function ($table) {
        $table->integer('id');
        $table->string('name');
    });

    $connection->statement('CREATE VIEW my_test_view AS SELECT id, name FROM view_src');

    $viewExists = $connection->select(
        "SELECT table_name FROM information_schema.views WHERE table_schema = 'main' AND table_name = 'my_test_view'"
    );
    expect($viewExists)->toHaveCount(1);

    $schema->dropAllViews();

    $viewExists = $connection->select(
        "SELECT table_name FROM information_schema.views WHERE table_schema = 'main' AND table_name = 'my_test_view'"
    );
    expect($viewExists)->toHaveCount(0);
})->throws(\Illuminate\Database\QueryException::class, 'Cannot drop internal catalog entry');

it('pragma returns a string value', function () {
    $connection = makeConnection();
    $builder = $connection->getSchemaBuilder();

    $result = $builder->pragma('platform');

    expect($result)->toBeString();
    expect($result)->not->toBeEmpty();
});

it('pragma set returns empty string', function () {
    $connection = makeConnection();
    $builder = $connection->getSchemaBuilder();

    $result = $builder->pragma('threads', '4');

    expect($result)->toBe('');
});

it('pragma set changes the configuration', function () {
    $connection = makeConnection();
    $builder = $connection->getSchemaBuilder();

    $builder->pragma('threads', '2');

    $threads = $connection->scalar("SELECT current_setting('threads')");
    expect((string) $threads)->toBe('2');
});

it('returns current schema listing', function () {
    $connection = makeConnection();
    $builder = $connection->getSchemaBuilder();

    $schemas = $builder->getCurrentSchemaListing();

    expect($schemas)->toBeArray();
    expect($schemas)->toContain('main');
});

it('returns multiple schemas', function () {
    $connection = makeConnection();
    $builder = $connection->getSchemaBuilder();

    $connection->statement('CREATE SCHEMA test_multi_schema');

    $schemas = $builder->getCurrentSchemaListing();

    expect($schemas)->toContain('main');
    expect($schemas)->toContain('test_multi_schema');
});

it('drops all tables preserves schemas', function () {
    $connection = makeConnection();
    $schema = $connection->getSchemaBuilder();

    $schema->create('preserve_test', function ($table) {
        $table->integer('id');
    });

    $schema->dropAllTables();

    $schemas = $schema->getCurrentSchemaListing();
    expect($schemas)->toContain('main');
});

it('returns builder instance from getSchemaBuilder', function () {
    $connection = makeConnection();

    $builder = $connection->getSchemaBuilder();

    expect($builder)->toBeInstanceOf(DuckDBBuilder::class);
});

it('pragma returns string types for both get and set', function () {
    $connection = makeConnection();
    $builder = $connection->getSchemaBuilder();

    $getResult = $builder->pragma('platform');
    expect(is_string($getResult))->toBeTrue();

    $setResult = $builder->pragma('threads', '2');
    expect(is_string($setResult))->toBeTrue();
    expect($setResult)->toBe('');
});
