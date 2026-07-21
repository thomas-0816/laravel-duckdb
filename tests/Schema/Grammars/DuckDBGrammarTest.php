<?php

use DuckDb\DuckDbConnection;
use DuckDb\Schema\Grammars\DuckDBGrammar;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;

it('compileSchemas queries information_schema', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileSchemas();

    expect($sql)->toContain('information_schema.schemata');
    expect($sql)->toContain('schema_name');
    expect($sql)->toContain('"default"');
});

it('compileSchemas executes against DuckDB', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);

    $result = $connection->getPdo()->query($grammar->compileSchemas())->fetchAll(PDO::FETCH_ASSOC);

    expect($result)->not->toBeEmpty();
    $names = array_column($result, 'name');
    expect($names)->toContain('main');
});

it('compileTableExists queries information_schema', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileTableExists('main', 'test_table');

    expect($sql)->toContain('information_schema.tables');
    expect($sql)->toContain("'test_table'");
    expect($sql)->toContain("'main'");
    expect($sql)->toContain("'BASE TABLE'");
    expect($sql)->toContain('"exists"');
});

it('compileTableExists defaults to main schema when null', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileTableExists(null, 'test_table');

    expect($sql)->toContain("'main'");
});

it('compileTableExists returns true for existing table', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);

    $connection->getPdo()->exec('CREATE TABLE existing_tbl (id INTEGER)');
    $result = $connection->getPdo()->query($grammar->compileTableExists('main', 'existing_tbl'))->fetch(PDO::FETCH_ASSOC);

    expect($result['exists'])->toBe(true);
});

it('compileTableExists returns false for non-existing table', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);

    $result = $connection->getPdo()->query($grammar->compileTableExists('main', 'nonexistent'))->fetch(PDO::FETCH_ASSOC);

    expect($result['exists'])->toBe(false);
});

it('compileTables with null schema omits schema filter', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileTables(null);

    expect($sql)->toContain('information_schema.tables');
    expect($sql)->not->toContain('table_schema =');
    expect($sql)->not->toContain('table_schema in');
    expect($sql)->toContain("'BASE TABLE'");
    expect($sql)->toContain('not like');
});

it('compileTables with string schema filters by schema', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileTables('main');

    expect($sql)->toContain("table_schema = 'main'");
});

it('compileTables with array schema uses IN clause', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileTables(['main', 'temp']);

    expect($sql)->toContain('table_schema in');
    expect($sql)->toContain("'main'");
    expect($sql)->toContain("'temp'");
});

it('compileTables returns actual tables from DuckDB', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);

    $connection->getPdo()->exec('CREATE TABLE ct_a (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE ct_b (id INTEGER)');
    $result = $connection->getPdo()->query($grammar->compileTables('main'))->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($result, 'name');

    expect($names)->toContain('ct_a');
    expect($names)->toContain('ct_b');
});

it('compileTables excludes internal duckdb tables', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);

    $connection->getPdo()->exec('CREATE TABLE user_test (id INTEGER)');
    $result = $connection->getPdo()->query($grammar->compileTables('main'))->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($result, 'name');

    expect($names)->not->toBeEmpty();
    foreach ($names as $name) {
        expect($name)->not->toMatch('/^duckdb_/');
    }
});

it('compileViews queries information_schema views', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileViews('main');

    expect($sql)->toContain('information_schema.views');
    expect($sql)->toContain("'main'");
    expect($sql)->toContain('view_definition');
});

it('compileViews returns actual views from DuckDB', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);

    $connection->getPdo()->exec('CREATE TABLE vt_src (id INTEGER)');
    $connection->getPdo()->exec('CREATE VIEW v_test_view AS SELECT * FROM vt_src');
    $result = $connection->getPdo()->query($grammar->compileViews('main'))->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($result, 'name');

    expect($names)->toContain('v_test_view');
});

it('compileViews defaults to empty string when schema is falsy', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileViews('');

    expect($sql)->toContain("''");
});

it('compileColumns queries information_schema columns', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileColumns('main', 'test_tbl');

    expect($sql)->toContain('information_schema.columns');
    expect($sql)->toContain("'test_tbl'");
    expect($sql)->toContain("'main'");
    expect($sql)->toContain('ordinal_position');
    expect($sql)->toContain('"nullable"');
    expect($sql)->toContain('"default"');
    expect($sql)->toContain('"cid"');
});

it('compileColumns defaults to main schema when null', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileColumns(null, 'test_tbl');

    expect($sql)->toContain("'main'");
});

it('compileColumns returns actual columns from DuckDB', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);

    $connection->getPdo()->exec('CREATE TABLE cc_t (id INTEGER, name TEXT, val DOUBLE)');
    $result = $connection->getPdo()->query($grammar->compileColumns('main', 'cc_t'))->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($result, 'name');

    expect($colNames)->toBe(['id', 'name', 'val']);
});

it('compileIndexes queries index information', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileIndexes('main', 'test_tbl');

    expect($sql)->toContain('information_schema.index_columns');
    expect($sql)->toContain('information_schema.indexes');
    expect($sql)->toContain('information_schema.table_constraints');
    expect($sql)->toContain("'test_tbl'");
    expect($sql)->toContain("'main'");
    expect($sql)->toContain('PRIMARY KEY');
    expect($sql)->toContain('union all');
    expect($sql)->toContain('group_concat');
});

it('compileForeignKeys queries key_column_usage', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileForeignKeys('main', 'test_tbl');

    expect($sql)->toContain('information_schema.key_column_usage');
    expect($sql)->toContain("'test_tbl'");
    expect($sql)->toContain("'main'");
    expect($sql)->toContain('foreign_table_name');
    expect($sql)->toContain('foreign_column_name');
    expect($sql)->toContain("'cascade'");
});

it('compileChange returns empty string', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 't');
    $command = new Fluent(['column' => new Fluent(['name' => 'id', 'type' => 'integer'])]);

    expect($grammar->compileChange($blueprint, $command))->toBe('');
});

it('compilePrimary returns empty string', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 't');
    $command = new Fluent(['columns' => ['id']]);

    expect($grammar->compilePrimary($blueprint, $command))->toBe('');
});

it('compileDropAllTables returns select query with correct structure', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileDropAllTables('main');

    expect($sql)->toContain('information_schema.tables');
    expect($sql)->toContain("'main'");
    expect($sql)->toContain('drop table if exists');
    expect($sql)->toContain("table_type = 'BASE TABLE'");
});

it('compileDropAllTables defaults to main schema', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileDropAllTables();

    expect($sql)->toContain("'main'");
});

it('compileDropAllViews returns select query with correct structure', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileDropAllViews('main');

    expect($sql)->toContain('information_schema.views');
    expect($sql)->toContain("'main'");
    expect($sql)->toContain('drop view if exists');
});

it('compileDropAllViews defaults to main schema', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileDropAllViews();

    expect($sql)->toContain("'main'");
});

it('compileRebuild returns vacuum statement', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    expect($grammar->compileRebuild('main'))->toBe("vacuum 'main'");
});

it('compileRebuild defaults to main schema', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    expect($grammar->compileRebuild())->toBe("vacuum 'main'");
});

it('compileSpatialIndex throws RuntimeException', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 't');
    $command = new Fluent(['index' => 'idx', 'columns' => ['geom']]);

    $grammar->compileSpatialIndex($blueprint, $command);
})->throws(RuntimeException::class, 'spatial indexes');

it('compileDropPrimary throws RuntimeException', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 't');
    $command = new Fluent(['index' => 'primary']);

    $grammar->compileDropPrimary($blueprint, $command);
})->throws(RuntimeException::class, 'dropping primary keys');

it('compileDropSpatialIndex throws RuntimeException', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 't');
    $command = new Fluent(['index' => 'idx']);

    $grammar->compileDropSpatialIndex($blueprint, $command);
})->throws(RuntimeException::class, 'spatial indexes');

it('compileEnableForeignKeyConstraints throws RuntimeException', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());
    $grammar->compileEnableForeignKeyConstraints();
})->throws(RuntimeException::class, 'foreign key constraints');

it('compileDisableForeignKeyConstraints throws RuntimeException', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());
    $grammar->compileDisableForeignKeyConstraints();
})->throws(RuntimeException::class, 'foreign key constraints');

it('typeComputed throws RuntimeException via blueprint', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('computed_test', function (Blueprint $table) {
        $table->string('name');
        $table->computed('doubled', '1 + 1');
    });
})->throws(RuntimeException::class, 'virtualAs / storedAs');

it('compileDropForeign throws when columns are empty', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 't');
    $command = new Fluent(['columns' => []]);

    $grammar->compileDropForeign($blueprint, $command);
})->throws(RuntimeException::class, 'dropping foreign keys by name');

it('compileDropForeign returns empty string when columns provided', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 't');
    $command = new Fluent(['columns' => ['user_id']]);

    expect($grammar->compileDropForeign($blueprint, $command))->toBe('');
});

it('creates table with varchar columns via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE type_varchar (a CHAR, b VARCHAR, c TEXT)');
    $columns = $connection->getPdo()->query(
        "select column_name, data_type from information_schema.columns where table_name = 'type_varchar' order by ordinal_position"
    )->fetchAll(PDO::FETCH_ASSOC);

    expect($columns)->toHaveCount(3);
});

it('creates table with integer types via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE type_int (a INTEGER, b BIGINT, c SMALLINT, d TINYINT)');
    $columns = $connection->getPdo()->query(
        "select column_name, data_type from information_schema.columns where table_name = 'type_int' order by ordinal_position"
    )->fetchAll(PDO::FETCH_ASSOC);
    $types = array_column($columns, 'data_type');

    expect($types)->toContain('INTEGER');
    expect($types)->toContain('BIGINT');
    expect($types)->toContain('SMALLINT');
    expect($types)->toContain('TINYINT');
});

it('creates table with float type via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE type_float (a FLOAT)');
    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_float'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('FLOAT');
});

it('creates table with double type via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE type_double (a DOUBLE)');
    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_double'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('DOUBLE');
});

it('creates table with decimal type via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE type_decimal (a DECIMAL(10, 2))');
    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_decimal'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('DECIMAL(10,2)');
});

it('creates table with boolean type via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE type_bool (a BOOLEAN)');
    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_bool'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('BOOLEAN');
});

it('creates table with json type via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE type_json (a JSON)');
    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_json'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('JSON');
});

it('creates table with date type via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE type_date (a DATE)');
    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_date'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('DATE');
});

it('creates table with timestamp type via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE type_ts (a TIMESTAMP)');
    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_ts'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('TIMESTAMP');
});

it('creates table with timestamptz type via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE type_tstz (a TIMESTAMPTZ)');
    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_tstz'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('TIMESTAMP WITH TIME ZONE');
});

it('creates table with time type via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE type_time (a TIME)');
    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_time'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('TIME');
});

it('creates table with blob type via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE type_blob (a BLOB)');
    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_blob'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('BLOB');
});

it('creates table with uuid type via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE type_uuid (a UUID)');
    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_uuid'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('UUID');
});

it('creates table with year mapped to integer via grammar', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('type_year', function (Blueprint $table) {
        $table->year('a');
    });

    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_year'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('INTEGER');
});

it('creates table with boolean type via grammar', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('type_bool_g', function (Blueprint $table) {
        $table->boolean('a');
    });

    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_bool_g'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('BOOLEAN');
});

it('creates table with enum type and check constraint', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('type_enum', function (Blueprint $table) {
        $table->enum('status', ['active', 'inactive']);
    });

    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'type_enum'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('VARCHAR');
});

it('create table with multiple column types end-to-end', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('multi_type', function (Blueprint $table) {
        $table->string('name');
        $table->integer('age');
        $table->boolean('active');
    });

    expect($connection->getSchemaBuilder()->hasTable('multi_type'))->toBeTrue();

    $columns = $connection->getPdo()->query(
        "select column_name from information_schema.columns where table_name = 'multi_type' order by ordinal_position"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($columns)->toContain('name');
    expect($columns)->toContain('age');
    expect($columns)->toContain('active');
});

it('compileCreate creates a table with data', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE create_data (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->getPdo()->exec("INSERT INTO create_data VALUES (1, 'Alice')");

    expect($connection->table('create_data')->count())->toBe(1);
    expect($connection->table('create_data')->where('id', 1)->value('name'))->toBe('Alice');
});

it('compileAdd adds a column to existing table', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE add_test (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->getPdo()->exec('ALTER TABLE add_test ADD COLUMN age INTEGER');

    expect($connection->getSchemaBuilder()->hasColumn('add_test', 'age'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasColumn('add_test', 'name'))->toBeTrue();
});

it('compileAlter preserves data when adding a column', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE alter_preserve (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->getPdo()->exec("INSERT INTO alter_preserve VALUES (1, 'Alice')");
    $connection->getPdo()->exec("INSERT INTO alter_preserve VALUES (2, 'Bob')");

    $connection->getPdo()->exec('ALTER TABLE alter_preserve ADD COLUMN age INTEGER DEFAULT 0');

    expect($connection->table('alter_preserve')->count())->toBe(2);
    expect($connection->table('alter_preserve')->where('id', 1)->value('name'))->toBe('Alice');
    expect($connection->table('alter_preserve')->where('id', 2)->value('name'))->toBe('Bob');
    expect($connection->table('alter_preserve')->where('id', 1)->value('age'))->toBe(0);
});

it('compileAlter adds multiple columns', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE alter_multi (id INTEGER PRIMARY KEY)');
    $connection->getPdo()->exec('INSERT INTO alter_multi VALUES (1)');

    $connection->getPdo()->exec('ALTER TABLE alter_multi ADD COLUMN first_name TEXT');
    $connection->getPdo()->exec('ALTER TABLE alter_multi ADD COLUMN last_name TEXT');

    expect($connection->getSchemaBuilder()->hasColumn('alter_multi', 'first_name'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasColumn('alter_multi', 'last_name'))->toBeTrue();
    expect($connection->table('alter_multi')->where('id', 1)->value('first_name'))->toBeNull();
});

it('compileDrop drops a table', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE drop_test (id INTEGER PRIMARY KEY)');
    expect($connection->getSchemaBuilder()->hasTable('drop_test'))->toBeTrue();

    $connection->getSchemaBuilder()->drop('drop_test');

    expect($connection->getSchemaBuilder()->hasTable('drop_test'))->toBeFalse();
});

it('compileDropIfExists drops a table if it exists', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE drop_ife (id INTEGER PRIMARY KEY)');
    expect($connection->getSchemaBuilder()->hasTable('drop_ife'))->toBeTrue();

    $connection->getSchemaBuilder()->dropIfExists('drop_ife');

    expect($connection->getSchemaBuilder()->hasTable('drop_ife'))->toBeFalse();
});

it('compileDropIfExists does not fail on non-existing table', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->dropIfExists('never_existed');

    expect($connection->getSchemaBuilder()->hasTable('never_existed'))->toBeFalse();
});

it('compileRename renames a table', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE rename_old (id INTEGER PRIMARY KEY)');

    $connection->getSchemaBuilder()->rename('rename_old', 'rename_new');

    expect($connection->getSchemaBuilder()->hasTable('rename_new'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasTable('rename_old'))->toBeFalse();
});

it('compileRename preserves data', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE rename_data (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->getPdo()->exec("INSERT INTO rename_data VALUES (1, 'Alice')");

    $connection->getSchemaBuilder()->rename('rename_data', 'rename_data_new');

    expect($connection->table('rename_data_new')->where('id', 1)->value('name'))->toBe('Alice');
});

it('compileUnique creates a unique index via grammar', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('uniq_test', function (Blueprint $table) {
        $table->string('email');
        $table->unique('email');
    });

    $result = $connection->getPdo()->query("pragma table_info('uniq_test')")->fetchAll(PDO::FETCH_ASSOC);
    expect($result)->not->toBeEmpty();
});

it('compileUnique prevents duplicate values', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('uniq_dup', function (Blueprint $table) {
        $table->string('email');
        $table->unique('email');
    });

    $connection->table('uniq_dup')->insert(['email' => 'a@test.com']);

    try {
        $connection->table('uniq_dup')->insert(['email' => 'a@test.com']);
        expect(true)->toBeFalse();
    } catch (\Exception $e) {
        expect($e->getMessage())->toContain('Duplicate key');
    }
});

it('compileIndex creates a regular index via grammar', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('idx_test', function (Blueprint $table) {
        $table->string('name');
        $table->index('name');
    });

    $result = $connection->getPdo()->query("pragma table_info('idx_test')")->fetchAll(PDO::FETCH_ASSOC);
    expect($result)->not->toBeEmpty();
});

it('compileForeign creates a foreign key constraint via grammar', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);

    $connection->getPdo()->exec('CREATE TABLE fk_parent (id INTEGER PRIMARY KEY, name TEXT)');

    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'fk_child');
    $command = new Fluent([
        'index' => 'fk_child_parent_id_foreign',
        'columns' => ['parent_id'],
        'on' => 'fk_parent',
        'references' => ['id'],
        'onDelete' => null,
        'onUpdate' => null,
    ]);

    $sql = $grammar->compileForeign($blueprint, $command);

    expect($sql)->toContain('foreign key');
    expect($sql)->toContain('references');
    expect($sql)->toContain('fk_parent');
});

it('compileForeign with cascade on delete via grammar', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);

    $connection->getPdo()->exec('CREATE TABLE fk_c_parent (id INTEGER PRIMARY KEY, name TEXT)');

    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'fk_c_child');
    $command = new Fluent([
        'index' => 'fk_c_child_parent_id_foreign',
        'columns' => ['parent_id'],
        'on' => 'fk_c_parent',
        'references' => ['id'],
        'onDelete' => 'cascade',
        'onUpdate' => null,
    ]);

    $sql = $grammar->compileForeign($blueprint, $command);

    expect($sql)->toContain('foreign key');
    expect($sql)->toContain('on delete cascade');
});

it('compileDropColumn drops a column', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE drop_col (id INTEGER PRIMARY KEY, name TEXT, age INTEGER)');

    expect($connection->getSchemaBuilder()->hasColumn('drop_col', 'age'))->toBeTrue();

    $connection->getSchemaBuilder()->table('drop_col', function (Blueprint $table) {
        $table->dropColumn('age');
    });

    expect($connection->getSchemaBuilder()->hasColumn('drop_col', 'age'))->toBeFalse();
    expect($connection->getSchemaBuilder()->hasColumn('drop_col', 'name'))->toBeTrue();
});

it('compileDropColumn drops multiple columns', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE drop_multi (id INTEGER PRIMARY KEY, a TEXT, b TEXT, c TEXT)');

    $connection->getSchemaBuilder()->table('drop_multi', function (Blueprint $table) {
        $table->dropColumn(['a', 'b']);
    });

    expect($connection->getSchemaBuilder()->hasColumn('drop_multi', 'a'))->toBeFalse();
    expect($connection->getSchemaBuilder()->hasColumn('drop_multi', 'b'))->toBeFalse();
    expect($connection->getSchemaBuilder()->hasColumn('drop_multi', 'c'))->toBeTrue();
});

it('compileDropColumn preserves data in remaining columns', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec("CREATE TABLE drop_preserve (id INTEGER PRIMARY KEY, keep TEXT, remove TEXT)");
    $connection->getPdo()->exec("INSERT INTO drop_preserve VALUES (1, 'yes', 'no')");

    $connection->getSchemaBuilder()->table('drop_preserve', function (Blueprint $table) {
        $table->dropColumn('remove');
    });

    expect($connection->table('drop_preserve')->where('id', 1)->value('keep'))->toBe('yes');
});

it('compileDropUnique drops a unique index via grammar', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('du_test', function (Blueprint $table) {
        $table->string('email');
        $table->unique('email', 'du_test_email_unique');
    });

    $connection->getSchemaBuilder()->table('du_test', function (Blueprint $table) {
        $table->dropUnique('du_test_email_unique');
    });

    expect($connection->getSchemaBuilder()->hasTable('du_test'))->toBeTrue();
});

it('compileDropIndex drops a regular index via grammar', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('di_test', function (Blueprint $table) {
        $table->string('name');
        $table->index('name', 'di_test_name_index');
    });

    $connection->getSchemaBuilder()->table('di_test', function (Blueprint $table) {
        $table->dropIndex('di_test_name_index');
    });

    expect($connection->getSchemaBuilder()->hasTable('di_test'))->toBeTrue();
});

it('modifyNullable allows null values', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('nullable_test', function (Blueprint $table) {
        $table->string('name')->nullable();
    });

    $connection->table('nullable_test')->insert(['name' => null]);
    $result = $connection->table('nullable_test')->first();

    expect($result->name)->toBeNull();
});

it('modifyNullable prevents null values by default', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('notnull_test', function (Blueprint $table) {
        $table->string('name');
    });

    try {
        $connection->getPdo()->exec("INSERT INTO notnull_test (name) VALUES (NULL)");
        expect(true)->toBeFalse();
    } catch (\Exception $e) {
        expect($e->getMessage())->toContain('NOT NULL');
    }
});

it('modifyDefault sets a default value', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('default_test', function (Blueprint $table) {
        $table->string('status')->default('active');
    });

    $connection->getPdo()->exec('INSERT INTO default_test DEFAULT VALUES');
    $result = $connection->table('default_test')->first();

    expect($result->status)->toBe('active');
});

it('modifyDefault with boolean default', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('default_bool', function (Blueprint $table) {
        $table->boolean('flag')->default(true);
    });

    $connection->getPdo()->exec('INSERT INTO default_bool DEFAULT VALUES');
    $result = $connection->table('default_bool')->first();

    expect($result->flag)->toBe(true);
});

it('modifyDefault with integer default', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('default_int', function (Blueprint $table) {
        $table->integer('count')->default(42);
    });

    $connection->getPdo()->exec('INSERT INTO default_int DEFAULT VALUES');
    $result = $connection->table('default_int')->first();

    expect($result->count)->toBe(42);
});

it('compileCreate compiles create table SQL correctly', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'test_compile');
    $blueprint->string('name');

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('create table');
    expect($sql)->toContain('test_compile');
    expect($sql)->toContain('varchar');
});

it('compileCreate with temporary table flag', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'temp_test');
    $blueprint->temporary = true;
    $blueprint->string('name');

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('create temporary table');
});

it('compileCreate compiles regular table', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'reg_test');
    $blueprint->temporary = false;
    $blueprint->string('name');

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('create table');
    expect($sql)->not->toContain('temporary');
});

it('compileAdd compiles add column SQL correctly', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'test_add');
    $column = new Fluent(['name' => 'email', 'type' => 'string']);
    $command = new Fluent(['column' => $column]);

    $sql = $grammar->compileAdd($blueprint, $command);

    expect($sql)->toContain('alter table');
    expect($sql)->toContain('add column');
    expect($sql)->toContain('test_add');
});

it('compileDrop compiles drop table SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'drop_me');
    $command = new Fluent([]);

    $sql = $grammar->compileDrop($blueprint, $command);

    expect($sql)->toBe('drop table "drop_me"');
});

it('compileDropIfExists compiles drop if exists SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'drop_if');
    $command = new Fluent([]);

    $sql = $grammar->compileDropIfExists($blueprint, $command);

    expect($sql)->toBe('drop table if exists "drop_if"');
});

it('compileRename compiles rename SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'old_tbl');
    $command = new Fluent(['to' => 'new_tbl']);

    $sql = $grammar->compileRename($blueprint, $command);

    expect($sql)->toContain('alter table');
    expect($sql)->toContain('rename to');
    expect($sql)->toContain('new_tbl');
});

it('compileForeign compiles foreign key SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'child_tbl');
    $command = new Fluent([
        'index' => 'fk_test',
        'columns' => ['parent_id'],
        'on' => 'parent_tbl',
        'references' => ['id'],
        'onDelete' => 'cascade',
        'onUpdate' => null,
    ]);

    $sql = $grammar->compileForeign($blueprint, $command);

    expect($sql)->toContain('alter table');
    expect($sql)->toContain('foreign key');
    expect($sql)->toContain('references');
    expect($sql)->toContain('on delete cascade');
});

it('compileForeign without onDelete/onUpdate', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'child2');
    $command = new Fluent([
        'index' => 'fk_test2',
        'columns' => ['parent_id'],
        'on' => 'parent2',
        'references' => ['id'],
        'onDelete' => null,
        'onUpdate' => null,
    ]);

    $sql = $grammar->compileForeign($blueprint, $command);

    expect($sql)->toContain('foreign key');
    expect($sql)->not->toContain('on delete');
    expect($sql)->not->toContain('on update');
});

it('compileForeign with both onDelete and onUpdate', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'child3');
    $command = new Fluent([
        'index' => 'fk_test3',
        'columns' => ['parent_id'],
        'on' => 'parent3',
        'references' => ['id'],
        'onDelete' => 'restrict',
        'onUpdate' => 'cascade',
    ]);

    $sql = $grammar->compileForeign($blueprint, $command);

    expect($sql)->toContain('on delete restrict');
    expect($sql)->toContain('on update cascade');
});

it('compileTableComment compiles comment SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'commented');
    $command = new Fluent(['comment' => 'A test table']);

    $sql = $grammar->compileTableComment($blueprint, $command);

    expect($sql)->toContain('comment on table');
    expect($sql)->toContain('commented');
    expect($sql)->toContain('A test table');
});

it('compileTableComment with null comment', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'null_comment');
    $command = new Fluent(['comment' => null]);

    $sql = $grammar->compileTableComment($blueprint, $command);

    expect($sql)->toContain('NULL');
});

it('compileComment compiles column comment SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'col_comment');
    $column = new Fluent(['name' => 'test_col', 'comment' => 'Test comment']);
    $command = new Fluent(['column' => $column]);

    $sql = $grammar->compileComment($blueprint, $command);

    expect($sql)->toContain('comment on column');
    expect($sql)->toContain('col_comment');
    expect($sql)->toContain('Test comment');
});

it('compileComment with null comment and change flag', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'null_col_comment');
    $column = new Fluent(['name' => 'test_col', 'comment' => null, 'change' => true]);
    $command = new Fluent(['column' => $column]);

    $sql = $grammar->compileComment($blueprint, $command);

    expect($sql)->toContain('NULL');
});

it('compileComment returns null when no comment and not changing', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'no_comment');
    $column = new Fluent(['name' => 'test_col', 'comment' => null, 'change' => false]);
    $command = new Fluent(['column' => $column]);

    $sql = $grammar->compileComment($blueprint, $command);

    expect($sql)->toBeNull();
});

it('compileDropColumn returns array of SQL statements', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'test_table');
    $command = new Fluent(['columns' => ['col_a', 'col_b']]);

    $result = $grammar->compileDropColumn($blueprint, $command);

    expect($result)->toBeArray();
    expect($result)->toHaveCount(2);
    expect($result[0])->toContain('alter table');
    expect($result[0])->toContain('drop column');
    expect($result[1])->toContain('alter table');
    expect($result[1])->toContain('drop column');
});

it('compileDropColumn with single column', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'single_drop');
    $command = new Fluent(['columns' => ['one_col']]);

    $result = $grammar->compileDropColumn($blueprint, $command);

    expect($result)->toHaveCount(1);
    expect($result[0])->toContain('alter table');
    expect($result[0])->toContain('drop column');
    expect($result[0])->toContain('"one_col"');
});

it('compileCreate compiles foreign keys in create statement', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'fk_create');
    $blueprint->string('user_name');
    $blueprint->foreign('user_name')->references('name')->on('users');

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('foreign key');
    expect($sql)->toContain('references');
});

it('compileCreate compiles primary key in create statement', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'pk_create');
    $blueprint->integer('a');
    $blueprint->integer('b');
    $blueprint->primary(['a', 'b']);

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('primary key');
});

it('compileIndex compiles index SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'idx_compile');
    $command = new Fluent(['index' => 'idx_name', 'columns' => ['name', 'email']]);

    $sql = $grammar->compileIndex($blueprint, $command);

    expect($sql)->toContain('create index');
    expect($sql)->toContain('idx_name');
    expect($sql)->toContain('name');
    expect($sql)->toContain('email');
});

it('compileUnique compiles unique index SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'uniq_compile');
    $command = new Fluent(['index' => 'uniq_email', 'columns' => ['email']]);

    $sql = $grammar->compileUnique($blueprint, $command);

    expect($sql)->toContain('create unique index');
    expect($sql)->toContain('uniq_email');
    expect($sql)->toContain('email');
});

it('compileDropIndex compiles drop index SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'di_compile');
    $command = new Fluent(['index' => 'idx_to_drop']);

    $sql = $grammar->compileDropIndex($blueprint, $command);

    expect($sql)->toContain('drop index');
    expect($sql)->toContain('idx_to_drop');
});

it('compileDropUnique compiles drop index SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'du_compile');
    $command = new Fluent(['index' => 'uniq_to_drop']);

    $sql = $grammar->compileDropUnique($blueprint, $command);

    expect($sql)->toContain('drop index');
    expect($sql)->toContain('uniq_to_drop');
});

it('compileAdd with nullable column', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE add_nullable (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->getPdo()->exec('ALTER TABLE add_nullable ADD COLUMN nickname TEXT');

    $connection->table('add_nullable')->insert(['id' => 1, 'name' => 'Alice']);
    $result = $connection->table('add_nullable')->first();

    expect($result->nickname)->toBeNull();
});

it('compileAdd with default value', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE add_default (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->getPdo()->exec("ALTER TABLE add_default ADD COLUMN color TEXT DEFAULT 'blue'");

    $result = $connection->table('add_default')->insertGetId(['id' => 1, 'name' => 'test']);
    $row = $connection->table('add_default')->where('id', 1)->first();

    expect($row->color)->toBe('blue');
});

it('typeDate with useCurrent sets default to current_date', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('date_current', function (Blueprint $table) {
        $table->date('created_at')->useCurrent();
    });

    $connection->getPdo()->exec('INSERT INTO date_current DEFAULT VALUES');
    $result = $connection->table('date_current')->first();

    expect($result->created_at)->not->toBeNull();
    expect((string) $result->created_at)->toContain(date('Y'));
});

it('typeTimestamp with useCurrent sets default to current_timestamp', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('ts_current', function (Blueprint $table) {
        $table->timestamp('created_at')->useCurrent();
    });

    $connection->getPdo()->exec('INSERT INTO ts_current DEFAULT VALUES');
    $result = $connection->table('ts_current')->first();

    expect($result->created_at)->not->toBeNull();
});

it('typeYear with useCurrent sets default to year', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('year_current', function (Blueprint $table) {
        $table->year('yr')->useCurrent();
    });

    $connection->getPdo()->exec('INSERT INTO year_current DEFAULT VALUES');
    $result = $connection->table('year_current')->first();

    expect($result->yr)->toBe((int) date('Y'));
});

it('compileCreate with geometry type', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('type_geom', function (Blueprint $table) {
        $table->geometry('shape');
    });

    expect($connection->getSchemaBuilder()->hasColumn('type_geom', 'shape'))->toBeTrue();
});

it('compileCreate with geography type', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('type_geo', function (Blueprint $table) {
        $table->geography('location');
    });

    expect($connection->getSchemaBuilder()->hasColumn('type_geo', 'location'))->toBeTrue();
});

it('integer types work with primary key via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE serial_bigint (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->getPdo()->exec("INSERT INTO serial_bigint VALUES (1, 'a')");
    $connection->getPdo()->exec("INSERT INTO serial_bigint VALUES (2, 'b')");

    $rows = $connection->table('serial_bigint')->orderBy('id')->get();
    expect($rows[0]->id)->toBe(1);
    expect($rows[1]->id)->toBe(2);
});

it('tinyInteger type works with primary key via raw SQL', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE serial_tiny (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->getPdo()->exec("INSERT INTO serial_tiny VALUES (1, 'a')");
    $connection->getPdo()->exec("INSERT INTO serial_tiny VALUES (2, 'b')");

    $rows = $connection->table('serial_tiny')->orderBy('id')->get();
    expect($rows[0]->id)->toBe(1);
    expect($rows[1]->id)->toBe(2);
});

it('compileRename with data preserved across rename', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE rename_multi (id INTEGER PRIMARY KEY, name TEXT, val INTEGER)');
    $connection->getPdo()->exec("INSERT INTO rename_multi VALUES (1, 'first', 100)");
    $connection->getPdo()->exec("INSERT INTO rename_multi VALUES (2, 'second', 200)");

    $connection->getSchemaBuilder()->rename('rename_multi', 'renamed_multi');

    expect($connection->table('renamed_multi')->count())->toBe(2);
    expect($connection->table('renamed_multi')->where('id', 1)->value('val'))->toBe(100);
});

it('compileComment returns null for column without comment and no change', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'no_comment');
    $column = new Fluent(['name' => 'col', 'comment' => null, 'change' => false]);
    $command = new Fluent(['column' => $column]);

    expect($grammar->compileComment($blueprint, $command))->toBeNull();
});

it('compileCreate compiles a complete table with multiple features', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'complete_tbl');
    $blueprint->string('name');
    $blueprint->integer('age');
    $blueprint->boolean('active');
    $blueprint->string('email')->unique();
    $blueprint->index('name');

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('create table');
    expect($sql)->toContain('complete_tbl');
    expect($sql)->toContain('varchar');
    expect($sql)->toContain('integer');
    expect($sql)->toContain('boolean');
});

it('compileDropForeign returns empty string for non-empty columns', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'fk_table');
    $command = new Fluent(['columns' => ['col1', 'col2']]);

    expect($grammar->compileDropForeign($blueprint, $command))->toBe('');
});

it('compileForeign compiles with compound columns', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'compound_child');
    $command = new Fluent([
        'index' => 'fk_compound',
        'columns' => ['parent_id', 'parent_type'],
        'on' => 'compound_parent',
        'references' => ['id', 'type'],
        'onDelete' => 'cascade',
        'onUpdate' => 'cascade',
    ]);

    $sql = $grammar->compileForeign($blueprint, $command);

    expect($sql)->toContain('foreign key');
    expect($sql)->toContain('parent_id');
    expect($sql)->toContain('parent_type');
    expect($sql)->toContain('references');
    expect($sql)->toContain('on delete cascade');
    expect($sql)->toContain('on update cascade');
});

it('compileDropColumn compiles SQL for table with prefix', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'prefix_table');
    $command = new Fluent(['columns' => ['col_to_drop']]);

    $result = $grammar->compileDropColumn($blueprint, $command);

    expect($result[0])->toContain('prefix_table');
    expect($result[0])->toContain('drop column');
});

it('compileCreate compiles column with default value', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'default_tbl');
    $blueprint->string('status')->default('active');

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('default');
    expect($sql)->toContain("'active'");
});

it('compileCreate compiles nullable column', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'nullable_tbl');
    $blueprint->string('name')->nullable();

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('varchar');
    expect($sql)->not->toContain('not null');
});

it('compileCreate compiles non-nullable column', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'notnull_tbl');
    $blueprint->string('name');

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('not null');
});

it('compileIndex compiles index with schema', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'main.idx_schema_test');
    $command = new Fluent(['index' => 'idx_test', 'columns' => ['col1']]);

    $sql = $grammar->compileIndex($blueprint, $command);

    expect($sql)->toContain('create index');
    expect($sql)->toContain('idx_test');
});

it('compileDropIndex compiles drop with schema', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'main.drop_idx_test');
    $command = new Fluent(['index' => 'idx_to_drop']);

    $sql = $grammar->compileDropIndex($blueprint, $command);

    expect($sql)->toContain('drop index');
    expect($sql)->toContain('idx_to_drop');
});

it('compileRebuild with custom schema', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    expect($grammar->compileRebuild('custom'))->toBe("vacuum 'custom'");
});

it('compileDropAllTables with custom schema', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileDropAllTables('custom');

    expect($sql)->toContain("'custom'");
    expect($sql)->toContain('drop table if exists');
});

it('compileDropAllViews with custom schema', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileDropAllViews('custom');

    expect($sql)->toContain("'custom'");
    expect($sql)->toContain('drop view if exists');
});

it('compileSchemas returns all schemas', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);

    $result = $connection->getPdo()->query($grammar->compileSchemas())->fetchAll(PDO::FETCH_ASSOC);

    expect(count($result))->toBeGreaterThanOrEqual(1);
    foreach ($result as $schema) {
        expect($schema)->toHaveKey('name');
        expect($schema)->toHaveKey('default');
    }
});

it('compileTableExists with special characters in table name', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);

    $sql = $grammar->compileTableExists('main', "specialchars");

    expect($sql)->toContain('information_schema.tables');
    expect($sql)->toContain("'specialchars'");
});

it('compileDropAllViews returns views from DuckDB', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);

    $connection->getPdo()->exec('CREATE TABLE dv_src (id INTEGER)');
    $connection->getPdo()->exec('CREATE VIEW dv1 AS SELECT * FROM dv_src');
    $connection->getPdo()->exec('CREATE VIEW dv2 AS SELECT * FROM dv_src');

    $dropStatements = $connection->getPdo()->query($grammar->compileDropAllViews('main'))->fetchAll(PDO::FETCH_COLUMN);

    expect($dropStatements)->not->toBeEmpty();
    expect($dropStatements)->toContain('drop view if exists dv1');
    expect($dropStatements)->toContain('drop view if exists dv2');

    $userDropStatements = array_filter($dropStatements, fn($s) => str_contains($s, 'dv1') || str_contains($s, 'dv2'));
    foreach ($userDropStatements as $sql) {
        $connection->getPdo()->exec($sql);
    }

    $views = $connection->getPdo()->query("select table_name from information_schema.views where table_name in ('dv1', 'dv2')")->fetchAll(PDO::FETCH_COLUMN);
    expect($views)->not->toContain('dv1');
    expect($views)->not->toContain('dv2');
});

it('compileDropAllTables returns tables from DuckDB', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);

    $connection->getPdo()->exec('CREATE TABLE da1 (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE da2 (id INTEGER)');

    $dropStatements = $connection->getPdo()->query($grammar->compileDropAllTables('main'))->fetchAll(PDO::FETCH_COLUMN);

    expect($dropStatements)->not->toBeEmpty();
    expect($dropStatements)->toContain('drop table if exists "main"."da1";');
    expect($dropStatements)->toContain('drop table if exists "main"."da2";');

    foreach ($dropStatements as $sql) {
        $connection->getPdo()->exec($sql);
    }

    expect($connection->getSchemaBuilder()->hasTable('da1'))->toBeFalse();
    expect($connection->getSchemaBuilder()->hasTable('da2'))->toBeFalse();
});

it('compileCreate with virtualAs expression via grammar', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'virtual_json');
    $blueprint->integer('id');
    $blueprint->string('data');
    $blueprint->integer('count_val');
    $blueprint->integer('total')->virtualAs(new Expression('(1 + 1)'));

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);
    $connection->getPdo()->exec($sql);

    $connection->getPdo()->exec("INSERT INTO virtual_json (id, data, count_val) VALUES (1, 'test', 1)");
    $result = $connection->getPdo()->query("SELECT total FROM virtual_json WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

    expect((int) $result['total'])->toBe(2);
});

it('compileComment on column via grammar', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'comment_raw');
    $column = new Fluent(['name' => 'name', 'comment' => 'The user name']);
    $command = new Fluent(['column' => $column]);

    $connection->getPdo()->exec('CREATE TABLE comment_raw (name TEXT)');
    $sql = $grammar->compileComment($blueprint, $command);
    $connection->getPdo()->exec($sql);

    $result = $connection->getPdo()->query("select * from duckdb_columns() where table_name = 'comment_raw' and column_name = 'name'")->fetch(PDO::FETCH_ASSOC);

    expect($result)->not->toBeFalse();
});

it('compileTableComment via grammar', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'tcomment_raw');
    $command = new Fluent(['comment' => 'This is a test table']);

    $connection->getPdo()->exec('CREATE TABLE tcomment_raw (name TEXT)');
    $sql = $grammar->compileTableComment($blueprint, $command);
    $connection->getPdo()->exec($sql);

    $result = $connection->getPdo()->query("select * from duckdb_tables() where table_name = 'tcomment_raw'")->fetch(PDO::FETCH_ASSOC);

    expect($result)->not->toBeFalse();
});

it('compileCreate with char type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_char_g', function (Blueprint $table) {
        $table->char('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_char_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('VARCHAR');
});

it('compileCreate with tinyText type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_tinytext_g', function (Blueprint $table) {
        $table->tinyText('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_tinytext_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('VARCHAR');
});

it('compileCreate with text type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_text_g', function (Blueprint $table) {
        $table->text('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_text_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('VARCHAR');
});

it('compileCreate with mediumText type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_mediumtext_g', function (Blueprint $table) {
        $table->mediumText('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_mediumtext_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('VARCHAR');
});

it('compileCreate with longText type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_longtext_g', function (Blueprint $table) {
        $table->longText('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_longtext_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('VARCHAR');
});

it('compileCreate with bigInteger type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_bigint_g', function (Blueprint $table) {
        $table->bigInteger('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_bigint_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('BIGINT');
});

it('compileCreate with mediumInteger type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_mediumint_g', function (Blueprint $table) {
        $table->mediumInteger('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_mediumint_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('INTEGER');
});

it('compileCreate with tinyInteger type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_tinyint_g', function (Blueprint $table) {
        $table->tinyInteger('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_tinyint_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('TINYINT');
});

it('compileCreate with smallInteger type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_smallint_g', function (Blueprint $table) {
        $table->smallInteger('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_smallint_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('SMALLINT');
});

it('compileCreate with float type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_float_g', function (Blueprint $table) {
        $table->float('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_float_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('FLOAT');
});

it('compileCreate with double type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_double_g', function (Blueprint $table) {
        $table->double('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_double_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('DOUBLE');
});

it('compileCreate with decimal type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_decimal_g', function (Blueprint $table) {
        $table->decimal('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_decimal_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toContain('DECIMAL');
});

it('compileCreate with json type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_json_g', function (Blueprint $table) {
        $table->json('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_json_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('JSON');
});

it('compileCreate with jsonb type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_jsonb_g', function (Blueprint $table) {
        $table->jsonb('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_jsonb_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('JSON');
});

it('compileCreate with dateTime type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_datetime_g', function (Blueprint $table) {
        $table->dateTime('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_datetime_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('TIMESTAMP');
});

it('compileCreate with dateTimeTz type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_datetimetz_g', function (Blueprint $table) {
        $table->dateTimeTz('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_datetimetz_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('TIMESTAMP WITH TIME ZONE');
});

it('compileCreate with time type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_time_g', function (Blueprint $table) {
        $table->time('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_time_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('TIME');
});

it('compileCreate with timeTz type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_timetz_g', function (Blueprint $table) {
        $table->timeTz('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_timetz_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('TIME WITH TIME ZONE');
});

it('compileCreate with timestampTz type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_timestamptz_g', function (Blueprint $table) {
        $table->timestampTz('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_timestamptz_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('TIMESTAMP WITH TIME ZONE');
});

it('compileCreate with binary type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_binary_g', function (Blueprint $table) {
        $table->binary('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_binary_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('BLOB');
});

it('compileCreate with uuid type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_uuid_g', function (Blueprint $table) {
        $table->uuid('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_uuid_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('UUID');
});

it('compileCreate with ipAddress type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_ip_g', function (Blueprint $table) {
        $table->ipAddress('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_ip_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('VARCHAR');
});

it('compileCreate with macAddress type via grammar', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_mac_g', function (Blueprint $table) {
        $table->macAddress('c');
    });

    $col = $connection->getPdo()->query("select data_type from information_schema.columns where table_name = 'type_mac_g'")->fetch(PDO::FETCH_ASSOC);
    expect($col['data_type'])->toBe('VARCHAR');
});

it('compileCreate with increments generates autoincrement SQL', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));
    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'type_incr_g');
    $blueprint->increments('id');
    $blueprint->string('name');

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('primary key autoincrement');
});

it('compileCreate with collation on column', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'collate_tbl');
    $blueprint->string('name')->collation('nocase');

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain("collate 'nocase'");
});

it('compileCreate with storedAs expression generates correct SQL', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'stored_as_g');
    $blueprint->integer('a');
    $blueprint->integer('b');
    $blueprint->integer('total')->storedAs(new Expression('a + b'));

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('stored');
    expect($sql)->toContain('a + b');
});

it('compileCreate with storedAsJson expression', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'stored_json_tbl');
    $blueprint->string('data');
    $blueprint->string('extracted')->storedAsJson('data->name');

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('stored');
    expect($sql)->toContain('json_extract');
});

it('compileCreate with virtualAsJson expression', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'virtual_json_tbl');
    $blueprint->string('data');
    $blueprint->integer('count_val');
    $blueprint->string('extracted')->virtualAsJson('data->name');

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('as (');
    expect($sql)->toContain('json_extract');
});

it('compileCreate with virtualAsJson using JSON column path selector', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'vjson_path_tbl');
    $blueprint->json('data');
    $blueprint->integer('count_val');
    $blueprint->string('extracted')->virtualAsJson('data->name');

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('json_extract');
});

it('compileCreate with storedAsJson using JSON column path selector', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'sjson_path_tbl');
    $blueprint->json('data');
    $blueprint->integer('count_val');
    $blueprint->string('extracted')->storedAsJson('data->name');

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('json_extract');
    expect($sql)->toContain('stored');
});

it('compileAlter modifies an existing table by adding a column', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('alter_add_g', function (Blueprint $table) {
        $table->integer('id');
        $table->string('name');
    });

    $connection->table('alter_add_g')->insert(['id' => 1, 'name' => 'Alice']);

    $connection->getSchemaBuilder()->table('alter_add_g', function (Blueprint $table) {
        $table->integer('age')->nullable()->default(0);
    });

    expect($connection->getSchemaBuilder()->hasColumn('alter_add_g', 'age'))->toBeTrue();
    expect($connection->table('alter_add_g')->where('name', 'Alice')->value('age'))->toBe(0);
});

it('compileAlter modifies column type', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('alter_type_g', function (Blueprint $table) {
        $table->integer('id');
        $table->string('val');
    });

    $connection->table('alter_type_g')->insert(['id' => 1, 'val' => 'hello']);

    $connection->getSchemaBuilder()->table('alter_type_g', function (Blueprint $table) {
        $table->integer('count_val')->nullable();
    });

    expect($connection->getSchemaBuilder()->hasColumn('alter_type_g', 'count_val'))->toBeTrue();
    expect($connection->table('alter_type_g')->count())->toBe(1);
});

it('compileAlter drops and recreates table preserving data with multiple columns', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('alter_preserve_g', function (Blueprint $table) {
        $table->integer('id');
        $table->string('name');
        $table->integer('score');
    });

    $connection->table('alter_preserve_g')->insert([
        ['id' => 1, 'name' => 'Alice', 'score' => 100],
        ['id' => 2, 'name' => 'Bob', 'score' => 200],
    ]);

    $connection->getSchemaBuilder()->table('alter_preserve_g', function (Blueprint $table) {
        $table->string('email')->nullable();
    });

    expect($connection->table('alter_preserve_g')->count())->toBe(2);
    expect($connection->getSchemaBuilder()->hasColumn('alter_preserve_g', 'email'))->toBeTrue();
    expect($connection->table('alter_preserve_g')->where('name', 'Alice')->value('score'))->toBe(100);
});

it('compileAlter adds index on existing table', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('alter_idx_g', function (Blueprint $table) {
        $table->integer('id');
        $table->string('name');
    });

    $connection->getSchemaBuilder()->table('alter_idx_g', function (Blueprint $table) {
        $table->string('email')->nullable();
        $table->index('email', 'alter_idx_email');
    });

    expect($connection->getSchemaBuilder()->hasColumn('alter_idx_g', 'email'))->toBeTrue();
});

it('compileRenameIndex throws when index lookup fails in DuckDB', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('ri_test', function (Blueprint $table) {
        $table->string('name');
        $table->index('name', 'ri_test_idx');
    });

    $grammar = new DuckDBGrammar($connection);
    $blueprint = new Blueprint($connection, 'ri_test');
    $command = new Fluent(['from' => 'ri_test_idx', 'to' => 'ri_test_new']);

    $grammar->compileRenameIndex($blueprint, $command);
})->throws(\Exception::class);

it('compileModifyNullable with storedAs and nullable false generates correct SQL', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'stored_nn_tbl');
    $blueprint->integer('a');
    $blueprint->integer('b');
    $col = $blueprint->integer('total')->storedAs(new Expression('a + b'));
    $col->nullable(false);

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('not null');
    expect($sql)->toContain('stored');
});

it('compileModifyDefault returns null when virtualAs is set', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'vdefault_tbl');
    $blueprint->integer('a');
    $blueprint->integer('b');
    $blueprint->integer('total')->virtualAs(new Expression('a + b'))->default(0);

    $command = new Fluent([]);
    $sql = $grammar->compileCreate($blueprint, $command);

    expect($sql)->toContain('as (');
    expect($sql)->not->toContain('default 0');
});
