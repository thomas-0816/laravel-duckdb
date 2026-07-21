<?php

use DuckDb\DuckDbConnection;
use DuckDb\Query\Grammars\DuckDBGrammar;

class TestableDuckDBGrammarJson extends DuckDBGrammar
{
    public function wrapJsonSelector($value)
    {
        return parent::wrapJsonSelector($value);
    }

    public function wrapJsonBooleanSelector($value)
    {
        return parent::wrapJsonBooleanSelector($value);
    }

    public function wrapJsonBooleanValue($value)
    {
        return parent::wrapJsonBooleanValue($value);
    }
}

it('whereJsonLength works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE jt2 (data JSON)');
    $connection->getPdo()->exec("INSERT INTO jt2 VALUES ('{\"a\": 1, \"b\": [1,2,3]}')");
    $connection->getPdo()->exec("INSERT INTO jt2 VALUES ('{\"a\": 2, \"b\": []}')");

    expect($connection->table('jt2')->whereJsonLength('data->b', '=', 3)->count())->toBe(1);
    expect($connection->table('jt2')->whereJsonLength('data->b', '>', 0)->count())->toBe(1);
    expect($connection->table('jt2')->whereJsonLength('data->b', '=', 0)->count())->toBe(1);
});

it('whereJsonContainsKey works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE jt3 (data JSON)');
    $connection->getPdo()->exec("INSERT INTO jt3 VALUES ('{\"a\": 1, \"b\": [1,2,3]}')");
    $connection->getPdo()->exec("INSERT INTO jt3 VALUES ('{\"c\": 2}')");

    expect($connection->table('jt3')->whereJsonContainsKey('data->a')->count())->toBe(1);
    expect($connection->table('jt3')->whereJsonContainsKey('data->c')->count())->toBe(1);
    expect($connection->table('jt3')->whereJsonContainsKey('data->b')->count())->toBe(1);
    expect($connection->table('jt3')->whereJsonContainsKey('data->z')->count())->toBe(0);
});

it('whereJsonContains compiles to json_contains', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE jt4 (data JSON)');
    $connection->getPdo()->exec("INSERT INTO jt4 VALUES ('{\"a\": 1}')");

    expect($connection->table('jt4')->whereJsonContains('data', ['a' => 1])->exists())->toBeTrue();
    expect($connection->table('jt4')->whereJsonContains('data', ['a' => 2])->exists())->toBeFalse();
});

it('whereJsonBoolean works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE jt5 (data JSON)');
    $connection->getPdo()->exec("INSERT INTO jt5 VALUES ('{\"a\": true}'), ('{\"a\": false}')");

    expect($connection->table('jt5')->where('data->a', '=', true)->count())->toBe(1);
});

it('compileJsonValueCast returns value as-is', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $grammar = $connection->getQueryGrammar();

    expect($grammar->compileJsonValueCast('col'))->toBe('col');
    expect($grammar->compileJsonValueCast('col->path'))->toBe('col->path');
});

it('wrapJsonSelector returns json_extract expression', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $grammar = new TestableDuckDBGrammarJson($connection);

    $result = $grammar->wrapJsonSelector('data->key');
    expect($result)->toContain('json_extract');
    expect($result)->toContain('data');
    expect($result)->toContain('key');
});

it('wrapJsonBooleanSelector wraps like wrapJsonSelector', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $grammar = new TestableDuckDBGrammarJson($connection);

    $result = $grammar->wrapJsonBooleanSelector('data->flag');
    expect($result)->toContain('json_extract');
});

it('wrapJsonBooleanValue returns value as-is', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $grammar = new TestableDuckDBGrammarJson($connection);

    expect($grammar->wrapJsonBooleanValue('?'))->toBe('?');
    expect($grammar->wrapJsonBooleanValue('true'))->toBe('true');
});

it('whereJsonOverlaps throws RuntimeException', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $grammar = $connection->getQueryGrammar();

    $builder = $connection->table('test');
    $builder->whereJsonOverlaps('data', ['key' => 'value']);

    try {
        $grammar->compileSelect($builder);
        expect(true)->toBeFalse(); // Should not reach here
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toContain('overlaps');
    }
});

it('update compiles JSON columns correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ujct (id INTEGER, data JSON)');
    $connection->table('ujct')->insert(['id' => 1, 'data' => json_encode(['a' => 1])]);

    $connection->table('ujct')->where('id', 1)->update(['data->a' => 2]);

    $result = $connection->table('ujct')->where('id', 1)->value('data->a');
    expect((int) $result)->toBe(2);
});

it('whereJsonContains with not works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wjcn (data JSON)');
    $connection->getPdo()->exec("INSERT INTO wjcn VALUES ('{\"a\": 1}')");
    $connection->getPdo()->exec("INSERT INTO wjcn VALUES ('{\"a\": 2}')");

    expect($connection->table('wjcn')->whereJsonContains('data', ['a' => 1])->count())->toBe(1);
    expect($connection->table('wjcn')->whereJsonDoesntContain('data', ['a' => 1])->count())->toBe(1);
});

it('whereJsonContainsKey with not works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wjckn (data JSON)');
    $connection->getPdo()->exec("INSERT INTO wjckn VALUES ('{\"a\": 1}')");
    $connection->getPdo()->exec("INSERT INTO wjckn VALUES ('{\"b\": 2}')");

    expect($connection->table('wjckn')->whereJsonDoesntContainKey('data->a')->count())->toBe(1);
});
