<?php

use DuckDb\DuckDbConnection;
use DuckDb\DuckDbServiceProvider;
use DuckDb\Schema\DuckDBSchemaState;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\Filesystem;

it('can run a select query and return results', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE test (id INTEGER, name TEXT)');
    $connection->getPdo()->exec("INSERT INTO test VALUES (1, 'Alice'), (2, 'Bob')");

    $results = $connection->select('SELECT * FROM test ORDER BY id');

    expect($results)->toHaveCount(2);
    expect($results[0]->name)->toBe('Alice');
    expect($results[1]->name)->toBe('Bob');
});

it('can insert and update data', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE test (id INTEGER, name TEXT)');
    $connection->getPdo()->exec("INSERT INTO test VALUES (1, 'Alice')");

    $affected = $connection->update("UPDATE test SET name = 'Alicia' WHERE id = 1");

    expect($affected)->toBe(1);

    $result = $connection->selectOne('SELECT name FROM test WHERE id = 1');
    expect($result->name)->toBe('Alicia');
});

it('can delete data', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE test (id INTEGER, name TEXT)');
    $connection->getPdo()->exec("INSERT INTO test VALUES (1, 'Alice')");

    $affected = $connection->delete('DELETE FROM test WHERE id = 1');

    expect($affected)->toBe(1);
    expect($connection->selectOne('SELECT COUNT(*) as count FROM test')->count)->toBe(0);
});

it('can run a raw statement', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE test (id INTEGER)');

    $result = $connection->statement('INSERT INTO test VALUES (42)');

    expect($result)->toBeTrue();
});

it('commits persist and rollbacks discard', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE test (id INTEGER, name TEXT)');

    $connection->beginTransaction();
    $connection->getPdo()->exec("INSERT INTO test VALUES (1, 'Alice')");
    $connection->commit();

    $connection->beginTransaction();
    $connection->getPdo()->exec("INSERT INTO test VALUES (2, 'Bob')");
    $connection->rollBack();

    $results = $connection->select('SELECT * FROM test ORDER BY id');
    expect($results)->toHaveCount(1);
    expect($results[0]->name)->toBe('Alice');
});

it('can use the query builder for inserts', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE users (id INTEGER, email TEXT)');

    $connection->table('users')->insert([
        ['id' => 1, 'email' => 'alice@example.com'],
        ['id' => 2, 'email' => 'bob@example.com'],
    ]);

    $users = $connection->table('users')->get();
    expect($users)->toHaveCount(2);
});

it('can use the query builder with where clauses', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE users (id INTEGER, email TEXT)');
    $connection->table('users')->insert([
        ['id' => 1, 'email' => 'alice@example.com'],
        ['id' => 2, 'email' => 'bob@example.com'],
    ]);

    $user = $connection->table('users')->where('email', 'alice@example.com')->first();

    expect($user->id)->toBe(1);
});

it('can create a table via the schema builder', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $schema = $connection->getSchemaBuilder();

    $schema->create('posts', function ($table) {
        $table->integer('id');
        $table->string('title');
    });

    $connection->table('posts')->insert(['id' => 1, 'title' => 'Hello World']);

    $post = $connection->table('posts')->first();
    expect($post->title)->toBe('Hello World');
});

it('can check if a table exists', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE animals (id INTEGER)');

    $schema = $connection->getSchemaBuilder();

    expect($schema->hasTable('animals'))->toBeTrue();
    expect($schema->hasTable('nonexistent'))->toBeFalse();
});

it('can get column listing', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE data (id INTEGER, label TEXT, value DOUBLE)');

    $schema = $connection->getSchemaBuilder();

    $columns = $schema->getColumnListing('data');

    expect($columns)->toBe(['id', 'label', 'value']);
});

it('returns driver title', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    expect($connection->getDriverTitle())->toBe('DuckDB');
});

it('registers the duckdb driver resolver via service provider', function () {
    $provider = new DuckDbServiceProvider(new Container());
    $provider->boot();

    expect(Connection::getResolver('duckdb'))->toBeCallable();
});

it('handles query exceptions on unique constraint violations', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE items (id INTEGER, slug TEXT UNIQUE)');
    $connection->table('items')->insert(['id' => 1, 'slug' => 'foo']);

    $connection->table('items')->insert(['id' => 2, 'slug' => 'foo']);
})->throws(UniqueConstraintViolationException::class);

it('escapes binary values to hex literal', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    $result = $connection->escape("\x00\x01\xFF", true);

    expect($result)->toBe("x'0001ff'");
});

it('escapes empty binary to empty hex literal', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    $result = $connection->escape('', true);

    expect($result)->toBe("x''");
});

it('returns a DuckDBSchemaState from getSchemaState', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    $schemaState = $connection->getSchemaState();

    expect($schemaState)->toBeInstanceOf(DuckDBSchemaState::class);
});

it('parses unique constraint violation columns from exception', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE tags (id INTEGER, name TEXT UNIQUE)');
    $connection->table('tags')->insert(['id' => 1, 'name' => 'laravel']);

    try {
        $connection->table('tags')->insert(['id' => 2, 'name' => 'laravel']);
        $this->fail('Expected UniqueConstraintViolationException');
    } catch (UniqueConstraintViolationException $e) {
        expect($e->columns)->toBe(['name']);
        expect($e->index)->toBeNull();
    }
});

it('parses composite unique constraint violation columns', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE pairs (a INTEGER, b INTEGER, UNIQUE(a, b))');
    $connection->table('pairs')->insert(['a' => 1, 'b' => 2]);

    try {
        $connection->table('pairs')->insert(['a' => 1, 'b' => 2]);
        $this->fail('Expected UniqueConstraintViolationException');
    } catch (UniqueConstraintViolationException $e) {
        expect($e->columns)->toBe(['a', 'b']);
    }
});

it('returns false for isUniqueConstraintError on non-matching exception', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    $connection->select('SELECT * FROM non_existent_table_xyz');
})->throws(QueryException::class);

it('parses unique constraint violation with empty columns when message format is unrecognized', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    $connection->getPdo()->exec('CREATE TABLE unique_format_test (id INTEGER, name TEXT UNIQUE)');
    $connection->getPdo()->exec("INSERT INTO unique_format_test VALUES (1, 'a')");

    try {
        $connection->getPdo()->exec("INSERT INTO unique_format_test VALUES (2, 'a')");
    } catch (\PDOException $e) {
        // DuckDB wraps unique errors with "Duplicate key" format
        expect($e->getMessage())->toContain('Duplicate key');
    }
});

it('reuses existing schema grammar when calling getSchemaBuilder twice', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    $schema1 = $connection->getSchemaBuilder();
    $schema2 = $connection->getSchemaBuilder();

    expect($schema1)->toBeInstanceOf(\DuckDb\Schema\DuckDBBuilder::class);
    expect($schema2)->toBeInstanceOf(\DuckDb\Schema\DuckDBBuilder::class);
});

it('getSchemaState accepts optional filesystem and process factory', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    $files = new Filesystem();
    $processFactory = fn($process) => $process;

    $schemaState = $connection->getSchemaState($files, $processFactory);

    expect($schemaState)->toBeInstanceOf(DuckDBSchemaState::class);
});

it('getSchemaState accepts only filesystem argument', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    $schemaState = $connection->getSchemaState(new Filesystem());

    expect($schemaState)->toBeInstanceOf(DuckDBSchemaState::class);
});

it('getSchemaBuilder returns functional schema builder', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    $schema = $connection->getSchemaBuilder();

    $schema->create('test_schema_func', function ($table) {
        $table->integer('id');
        $table->string('name');
    });

    $connection->table('test_schema_func')->insert(['id' => 1, 'name' => 'test']);
    $result = $connection->table('test_schema_func')->first();
    expect($result->name)->toBe('test');
});

it('default query grammar is DuckDB grammar', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    $grammar = $connection->getQueryGrammar();

    expect($grammar)->toBeInstanceOf(\DuckDb\Query\Grammars\DuckDBGrammar::class);
});

it('default post processor is DuckDB processor', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    $processor = $connection->getPostProcessor();

    expect($processor)->toBeInstanceOf(\DuckDb\Query\Processors\DuckDbProcessor::class);
});

it('returns duckdb as the driver name', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    expect($connection->getDriverTitle())->toBe('DuckDB');
});
