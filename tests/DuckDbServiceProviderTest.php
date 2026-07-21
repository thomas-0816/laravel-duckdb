<?php

use DuckDb\DuckDbConnection;
use DuckDb\DuckDbServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;

it('registers the duckdb resolver on boot', function () {
    $provider = new DuckDbServiceProvider(new Container());
    $provider->boot();

    expect(Connection::getResolver('duckdb'))->toBeCallable();
});

it('resolver creates a duckdb connection instance', function () {
    $provider = new DuckDbServiceProvider(new Container());
    $provider->boot();

    $resolver = Connection::getResolver('duckdb');
    $pdo = new PDO('duckdb::memory:');
    $connection = $resolver($pdo, '', '', ['driver' => 'duckdb']);

    expect($connection)->toBeInstanceOf(DuckDbConnection::class);
});

it('resolver connection is functional', function () {
    $provider = new DuckDbServiceProvider(new Container());
    $provider->boot();

    $resolver = Connection::getResolver('duckdb');
    $pdo = new PDO('duckdb::memory:');
    $connection = $resolver($pdo, '', '', ['driver' => 'duckdb']);

    $connection->getPdo()->exec('CREATE TABLE func_test (val INTEGER)');
    $connection->getPdo()->exec('INSERT INTO func_test VALUES (42)');
    $result = $connection->select('SELECT val FROM func_test');
    expect($result[0]->val)->toBe(42);
});

it('resolver connection passes database name', function () {
    $provider = new DuckDbServiceProvider(new Container());
    $provider->boot();

    $resolver = Connection::getResolver('duckdb');
    $pdo = new PDO('duckdb::memory:');
    $connection = $resolver($pdo, 'testdb', '', ['driver' => 'duckdb']);

    expect($connection->getDatabaseName())->toBe('testdb');
});

it('resolver connection passes table prefix', function () {
    $provider = new DuckDbServiceProvider(new Container());
    $provider->boot();

    $resolver = Connection::getResolver('duckdb');
    $pdo = new PDO('duckdb::memory:');
    $connection = $resolver($pdo, '', 'pref_', ['driver' => 'duckdb']);

    expect($connection->getTablePrefix())->toBe('pref_');
});

it('resolver connection passes config', function () {
    $provider = new DuckDbServiceProvider(new Container());
    $provider->boot();

    $resolver = Connection::getResolver('duckdb');
    $pdo = new PDO('duckdb::memory:');
    $connection = $resolver($pdo, '', '', ['driver' => 'duckdb', 'custom' => 'value']);

    expect($connection->getConfig('custom'))->toBe('value');
});

it('resolver connection uses duckdb query grammar', function () {
    $provider = new DuckDbServiceProvider(new Container());
    $provider->boot();

    $resolver = Connection::getResolver('duckdb');
    $pdo = new PDO('duckdb::memory:');
    $connection = $resolver($pdo, '', '', ['driver' => 'duckdb']);

    expect($connection->getQueryGrammar())
        ->toBeInstanceOf(\DuckDb\Query\Grammars\DuckDBGrammar::class);
});

it('resolver connection uses duckdb schema grammar via builder', function () {
    $provider = new DuckDbServiceProvider(new Container());
    $provider->boot();

    $resolver = Connection::getResolver('duckdb');
    $pdo = new PDO('duckdb::memory:');
    $connection = $resolver($pdo, '', '', ['driver' => 'duckdb']);

    $schema = $connection->getSchemaBuilder();
    expect($schema)->toBeInstanceOf(\DuckDb\Schema\DuckDBBuilder::class);
    expect($connection->getSchemaGrammar())
        ->toBeInstanceOf(\DuckDb\Schema\Grammars\DuckDBGrammar::class);
});

it('resolver connection uses duckdb post processor', function () {
    $provider = new DuckDbServiceProvider(new Container());
    $provider->boot();

    $resolver = Connection::getResolver('duckdb');
    $pdo = new PDO('duckdb::memory:');
    $connection = $resolver($pdo, '', '', ['driver' => 'duckdb']);

    expect($connection->getPostProcessor())
        ->toBeInstanceOf(\DuckDb\Query\Processors\DuckDbProcessor::class);
});

it('resolver connection can create tables via schema builder', function () {
    $provider = new DuckDbServiceProvider(new Container());
    $provider->boot();

    $resolver = Connection::getResolver('duckdb');
    $pdo = new PDO('duckdb::memory:');
    $connection = $resolver($pdo, '', '', ['driver' => 'duckdb']);

    $connection->getSchemaBuilder()->create('provider_test', function ($table) {
        $table->integer('id');
        $table->string('name');
    });

    $connection->table('provider_test')->insert(['id' => 1, 'name' => 'test']);
    $result = $connection->table('provider_test')->first();
    expect($result->name)->toBe('test');
});

it('resolver connection can run raw queries', function () {
    $provider = new DuckDbServiceProvider(new Container());
    $provider->boot();

    $resolver = Connection::getResolver('duckdb');
    $pdo = new PDO('duckdb::memory:');
    $connection = $resolver($pdo, '', '', ['driver' => 'duckdb']);

    $connection->getPdo()->exec('CREATE TABLE raw_test (id INTEGER)');
    $connection->getPdo()->exec('INSERT INTO raw_test VALUES (99)');

    $result = $connection->select('SELECT id FROM raw_test');
    expect($result[0]->id)->toBe(99);
});

it('register method can be called without error', function () {
    $app = new Container();
    $provider = new DuckDbServiceProvider($app);

    $provider->register();
    expect(true)->toBeTrue();
});

it('boot can be called multiple times safely', function () {
    $provider = new DuckDbServiceProvider(new Container());

    $provider->boot();
    $provider->boot();

    expect(Connection::getResolver('duckdb'))->toBeCallable();
});
