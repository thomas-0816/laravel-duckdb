<?php

use DuckDb\DuckDBConnector;

it('connects to an in-memory database', function () {
    $connector = new DuckDBConnector();
    $connector->setDefaultOptions([PDO::ATTR_STRINGIFY_FETCHES => true]);

    $pdo = $connector->connect([
        'database' => ':memory:',
        'options' => [PDO::DUCKDB_ATTR_CONFIG => ['TimeZone' => 'Europe/Berlin']],
    ]);

    $result = $pdo->query("SELECT 42 AS answer, current_setting('TimeZone') as timezone")->fetch(PDO::FETCH_ASSOC);
    expect($result)->toBe(['answer' => '42', 'timezone' => 'Europe/Berlin']);

    expect($pdo->getAttribute(PDO::ATTR_ERRMODE))->toBe(PDO::ERRMODE_EXCEPTION);
    expect($connector->getOptions([]))->toBe([PDO::ATTR_STRINGIFY_FETCHES => true]);
});

it('connects to a file-based database', function () {
    $file = tempnam('/tmp', 'duckdb_connector_test.duckdb');
    unlink($file);
    $connector = new DuckDBConnector();
    $connector->connect(['database' => $file]);
    expect(file_exists($file))->toBeTrue();
    unlink($file);
});

it('connects separate connections that do not share state', function () {
    $connector = new DuckDBConnector();

    $pdo1 = $connector->connect(['database' => ':memory:']);
    $pdo1->exec("CREATE TABLE shared_test (id INTEGER)");

    $pdo2 = $connector->connect(['database' => ':memory:']);
    $tables = $pdo2->query("SELECT table_name FROM duckdb_tables()")->fetchAll(PDO::FETCH_COLUMN);
    expect($tables)->toBeEmpty();
});
