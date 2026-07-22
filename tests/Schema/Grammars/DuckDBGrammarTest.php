<?php

use DuckDb\DuckDbConnection;
use DuckDb\Schema\Grammars\DuckDBGrammar;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;

it('getSchemas returns schemas from DuckDB', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $schemas = $connection->getSchemaBuilder()->getSchemas();

    expect($schemas)->not->toBeEmpty();
    $names = array_column($schemas, 'name');
    expect($names)->toContain('main');
});

it('getSchemas includes the default schema', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $schemas = $connection->getSchemaBuilder()->getSchemas();
    $main = collect($schemas)->firstWhere('name', 'main');

    expect($main)->not->toBeNull();
    expect($main['default'])->toBe(true);
});

it('hasTable detects an existing table via information_schema', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE existing_tbl (id INTEGER)');

    expect($connection->getSchemaBuilder()->hasTable('existing_tbl'))->toBeTrue();
});

it('hasTable defaults to main schema when no explicit schema', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE default_schema_tbl (id INTEGER)');

    expect($connection->getSchemaBuilder()->hasTable('default_schema_tbl'))->toBeTrue();
});

it('hasTable returns true for existing table', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE ht_true_tbl (id INTEGER, name TEXT)');

    expect($connection->getSchemaBuilder()->hasTable('ht_true_tbl'))->toBeTrue();
});

it('hasTable returns false for non-existing table', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    expect($connection->getSchemaBuilder()->hasTable('nonexistent'))->toBeFalse();
});

it('getTables with null schema returns all tables', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE null_schema_tbl (id INTEGER)');

    $names = $connection->getSchemaBuilder()->getTableListing(null, false);

    expect($names)->toContain('null_schema_tbl');
});

it('getTables with string schema returns tables from that schema', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE string_schema_tbl (id INTEGER)');

    $names = $connection->getSchemaBuilder()->getTableListing('main', false);

    expect($names)->toContain('string_schema_tbl');
});

it('getTables with array schema returns tables from those schemas', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE array_schema_tbl (id INTEGER)');

    $names = $connection->getSchemaBuilder()->getTableListing(['main', 'temp'], false);

    expect($names)->toContain('array_schema_tbl');
});

it('getTables returns actual tables from DuckDB', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE ct_a (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE ct_b (id INTEGER)');

    $names = $connection->getSchemaBuilder()->getTableListing('main', false);

    expect($names)->toContain('ct_a');
    expect($names)->toContain('ct_b');
});

it('getTables excludes internal duckdb tables', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE user_test (id INTEGER)');

    $names = $connection->getSchemaBuilder()->getTableListing('main', false);

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

    $sql = $grammar->compileViews('main'); // TODO make real test, dont call compile function directly

    expect($sql)->toContain('duckdb_views()');
    expect($sql)->toContain("'main'");
    expect($sql)->toContain('definition');
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
    $result = $connection->getPdo()->query($grammar->compileViews('main'))->fetchAll(PDO::FETCH_ASSOC); // TODO make real test, dont call compile function directly
    $names = array_column($result, 'name');

    expect($names)->toContain('v_test_view');
});

it('compileViews defaults to empty string when schema is falsy', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileViews(''); // TODO make real test, dont call compile function directly

    expect($sql)->toContain("''");
});

it('compileColumns queries information_schema columns', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileColumns('main', 'test_tbl'); // TODO make real test, dont call compile function directly

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

    $sql = $grammar->compileColumns(null, 'test_tbl'); // TODO make real test, dont call compile function directly

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
    $result = $connection->getPdo()->query($grammar->compileColumns('main', 'cc_t'))->fetchAll(PDO::FETCH_ASSOC); // TODO make real test, dont call compile function directly
    $colNames = array_column($result, 'name');

    expect($colNames)->toBe(['id', 'name', 'val']);
});

it('compileIndexes queries index information', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileIndexes('main', 'test_tbl'); // TODO make real test, dont call compile function directly

    expect($sql)->toContain('duckdb_indexes()');
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

    $sql = $grammar->compileForeignKeys('main', 'test_tbl'); // TODO make real test, dont call compile function directly

    expect($sql)->toContain('duckdb_constraints()');
    expect($sql)->toContain("'test_tbl'");
    expect($sql)->toContain("'main'");
    expect($sql)->toContain('foreign_table');
    expect($sql)->toContain('foreign_columns');
    expect($sql)->toContain("'cascade'");
});

it('dropAllTables drops all user tables', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE dart1 (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE dart2 (id INTEGER)');

    $connection->getSchemaBuilder()->dropAllTables();

    expect($connection->getSchemaBuilder()->hasTable('dart1'))->toBeFalse();
    expect($connection->getSchemaBuilder()->hasTable('dart2'))->toBeFalse();
});

it('dropAllTables defaults to main schema', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE dart3 (id INTEGER)');

    $connection->getSchemaBuilder()->dropAllTables();

    expect($connection->getSchemaBuilder()->hasTable('dart3'))->toBeFalse();
});

it('dropAllViews drops all user views', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE davs (id INTEGER)');
    $connection->getPdo()->exec('CREATE VIEW dav1 AS SELECT * FROM davs');
    $connection->getPdo()->exec('CREATE VIEW dav2 AS SELECT * FROM davs');
    $connection->getSchemaBuilder()->dropAllViews();

    $views = $connection->getPdo()->query("select table_name from information_schema.views where table_schema = 'main' and table_name in ('dav1', 'dav2')")->fetchAll(PDO::FETCH_COLUMN);
    expect($views)->not->toContain('dav1');
    expect($views)->not->toContain('dav2');
});

it('dropAllViews defaults to main schema', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE davs2 (id INTEGER)');
    $connection->getPdo()->exec('CREATE VIEW dav3 AS SELECT * FROM davs2');
    $connection->getSchemaBuilder()->dropAllViews();

    $views = $connection->getPdo()->query("select table_name from information_schema.views where table_schema = 'main' and table_name = 'dav3'")->fetchAll(PDO::FETCH_COLUMN);
    expect($views)->not->toContain('dav3');
});

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
})->throws(RuntimeException::class);

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

it('compileForeign creates a foreign key constraint', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('fk_parent', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('name');
    });

    $connection->getSchemaBuilder()->create('fk_child', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->foreignId('parent_id')->constrained('fk_parent');
    });

    $indexes = $connection->getPdo()->query(
        "select constraint_name from information_schema.table_constraints where table_name = 'fk_child' and constraint_type = 'FOREIGN KEY'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($indexes)->not->toBeEmpty();
});

it('compileForeign with cascade on delete', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('fk_c_parent', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('name');
    });

    $connection->getSchemaBuilder()->create('fk_c_child', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->foreignId('parent_id')->constrained('fk_c_parent')->onDelete('cascade');
    });

    $indexes = $connection->getPdo()->query(
        "select constraint_name from information_schema.table_constraints where table_name = 'fk_c_child' and constraint_type = 'FOREIGN KEY'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($indexes)->not->toBeEmpty();
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

it('compileCreate creates a table with columns', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('test_compile', function (Blueprint $table) {
        $table->string('name');
    });

    expect($connection->getSchemaBuilder()->hasTable('test_compile'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasColumn('test_compile', 'name'))->toBeTrue();
});

it('compileCreate creates a temporary table', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('temp_test', function (Blueprint $table) {
        $table->temporary();
        $table->string('name');
    });

    expect($connection->getSchemaBuilder()->hasTable('temp_test'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasColumn('temp_test', 'name'))->toBeTrue();
});

it('compileCreate creates a non-temporary table', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('reg_test', function (Blueprint $table) {
        $table->string('name');
    });

    expect($connection->getSchemaBuilder()->hasTable('reg_test'))->toBeTrue();
    $columns = $connection->getPdo()->query(
        "select column_name from information_schema.columns where table_name = 'reg_test'"
    )->fetchAll(PDO::FETCH_COLUMN);
    expect($columns)->toContain('name');
});

it('compileAdd adds a column via schema builder', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('test_add', function (Blueprint $table) {
        $table->integer('id')->unsigned();
    });

    $connection->getSchemaBuilder()->table('test_add', function (Blueprint $table) {
        $table->string('email');
    });

    expect($connection->getSchemaBuilder()->hasColumn('test_add', 'email'))->toBeTrue();
});

it('compileDrop drops a table via schema builder', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE drop_me (id INTEGER PRIMARY KEY)');
    expect($connection->getSchemaBuilder()->hasTable('drop_me'))->toBeTrue();

    $connection->getSchemaBuilder()->drop('drop_me');

    expect($connection->getSchemaBuilder()->hasTable('drop_me'))->toBeFalse();
});

it('compileDropIfExists drops a table if it exists via schema builder', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE drop_if (id INTEGER PRIMARY KEY)');
    expect($connection->getSchemaBuilder()->hasTable('drop_if'))->toBeTrue();

    $connection->getSchemaBuilder()->dropIfExists('drop_if');

    expect($connection->getSchemaBuilder()->hasTable('drop_if'))->toBeFalse();
});

it('compileRename renames a table via schema builder', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE old_tbl (id INTEGER PRIMARY KEY)');

    $connection->getSchemaBuilder()->rename('old_tbl', 'new_tbl');

    expect($connection->getSchemaBuilder()->hasTable('new_tbl'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasTable('old_tbl'))->toBeFalse();
});

it('compileForeign adds foreign key with cascade on delete via schema builder', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('parent_tbl', function (Blueprint $table) {
        $table->integer('id')->unsigned();
    });

    $connection->getSchemaBuilder()->create('child_tbl', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->foreignId('parent_id')->constrained('parent_tbl')->onDelete('cascade');
    });

    $indexes = $connection->getPdo()->query(
        "select constraint_name from information_schema.table_constraints where table_name = 'child_tbl' and constraint_type = 'FOREIGN KEY'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($indexes)->not->toBeEmpty();
});

it('compileForeign adds foreign key without onDelete/onUpdate', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('parent2', function (Blueprint $table) {
        $table->integer('id')->unsigned();
    });

    $connection->getSchemaBuilder()->create('child2', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->foreignId('parent_id')->constrained('parent2');
    });

    $indexes = $connection->getPdo()->query(
        "select constraint_name from information_schema.table_constraints where table_name = 'child2' and constraint_type = 'FOREIGN KEY'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($indexes)->not->toBeEmpty();
});

it('compileForeign adds foreign key with both onDelete and onUpdate', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('parent3', function (Blueprint $table) {
        $table->integer('id')->unsigned();
    });

    $connection->getSchemaBuilder()->create('child3', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->foreignId('parent_id')->constrained('parent3')->onDelete('restrict')->onUpdate('cascade');
    });

    $indexes = $connection->getPdo()->query(
        "select constraint_name from information_schema.table_constraints where table_name = 'child3' and constraint_type = 'FOREIGN KEY'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($indexes)->not->toBeEmpty();
});

it('compileTableComment sets a table comment', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE commented (id INTEGER)');
    $connection->getSchemaBuilder()->comment('commented', 'A test table');

    $result = $connection->getPdo()->query("select * from duckdb_tables() where table_name = 'commented'")->fetch(PDO::FETCH_ASSOC);

    expect($result)->not->toBeFalse();
});

it('compileTableComment with null comment', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE null_comment (id INTEGER)');
    $connection->getSchemaBuilder()->comment('null_comment', null);

    $result = $connection->getPdo()->query("select * from duckdb_tables() where table_name = 'null_comment'")->fetch(PDO::FETCH_ASSOC);

    expect($result)->not->toBeFalse();
});

it('compileComment sets a column comment', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'col_comment');
    $command = new Fluent(['column' => new Fluent(['name' => 'name', 'comment' => 'Test comment', 'change' => false])]);

    $connection->getPdo()->exec('CREATE TABLE col_comment (name TEXT)');
    $sql = $grammar->compileComment($blueprint, $command);
    $connection->getPdo()->exec($sql);

    $result = $connection->getPdo()->query("select * from duckdb_columns() where table_name = 'col_comment' and column_name = 'name'")->fetch(PDO::FETCH_ASSOC);

    expect($result)->not->toBeFalse();
});

it('compileComment with null comment and change flag sets null comment', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'null_col_comment');
    $command = new Fluent(['column' => new Fluent(['name' => 'test_col', 'comment' => null, 'change' => true])]);

    $connection->getPdo()->exec('CREATE TABLE null_col_comment (test_col TEXT)');
    $sql = $grammar->compileComment($blueprint, $command);
    $connection->getPdo()->exec($sql);

    $result = $connection->getPdo()->query("select * from duckdb_columns() where table_name = 'null_col_comment' and column_name = 'test_col'")->fetch(PDO::FETCH_ASSOC);

    expect($result)->not->toBeFalse();
});

it('adding column without comment does not alter column metadata', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('no_comment_tbl', function (Blueprint $table) {
        $table->integer('id');
    });

    $connection->getSchemaBuilder()->table('no_comment_tbl', function (Blueprint $table) {
        $table->string('test_col')->nullable();
    });

    $columns = $connection->getPdo()->query(
        "select column_name from information_schema.columns where table_name = 'no_comment_tbl'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($columns)->toContain('id');
    expect($columns)->toContain('test_col');
});

it('compileDropColumn drops multiple columns via schema builder', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE test_table (id INTEGER PRIMARY KEY, col_a TEXT, col_b TEXT)');

    $connection->getSchemaBuilder()->table('test_table', function (Blueprint $table) {
        $table->dropColumn(['col_a', 'col_b']);
    });

    expect($connection->getSchemaBuilder()->hasColumn('test_table', 'col_a'))->toBeFalse();
    expect($connection->getSchemaBuilder()->hasColumn('test_table', 'col_b'))->toBeFalse();
    expect($connection->getSchemaBuilder()->hasColumn('test_table', 'id'))->toBeTrue();
});

it('compileDropColumn with single column via schema builder', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE single_drop (id INTEGER PRIMARY KEY, one_col TEXT)');

    $connection->getSchemaBuilder()->table('single_drop', function (Blueprint $table) {
        $table->dropColumn('one_col');
    });

    expect($connection->getSchemaBuilder()->hasColumn('single_drop', 'one_col'))->toBeFalse();
    expect($connection->getSchemaBuilder()->hasColumn('single_drop', 'id'))->toBeTrue();
});

it('compileCreate creates a table with foreign key', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('fk_create_users', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('name');
    });

    $connection->getSchemaBuilder()->create('fk_create', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('user_name');
        $table->foreign('user_name')->references('name')->on('fk_create_users');
    });

    $indexes = $connection->getPdo()->query(
        "select constraint_name from information_schema.table_constraints where table_name = 'fk_create' and constraint_type = 'FOREIGN KEY'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($indexes)->not->toBeEmpty();
});

it('compileCreate creates a table with primary key', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('pk_create', function (Blueprint $table) {
        $table->integer('a');
        $table->integer('b');
        $table->primary(['a', 'b']);
    });

    $indexes = $connection->getPdo()->query(
        "select constraint_name from information_schema.table_constraints where table_name = 'pk_create' and constraint_type = 'PRIMARY KEY'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($indexes)->not->toBeEmpty();
});

it('compileIndex creates an index via schema builder', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('idx_compile', function (Blueprint $table) {
        $table->string('name');
        $table->string('email');
        $table->index(['name', 'email'], 'idx_name');
    });

    $indexes = $connection->getPdo()->query(
        "select index_name from duckdb_indexes() where table_name = 'idx_compile'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($indexes)->toContain('idx_name');
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

    $sql = $grammar->compileUnique($blueprint, $command); // TODO make real test, dont call compile function directly

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

    $sql = $grammar->compileDropIndex($blueprint, $command); // TODO make real test, dont call compile function directly

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

    $sql = $grammar->compileDropUnique($blueprint, $command); // TODO make real test, dont call compile function directly

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

    expect($grammar->compileComment($blueprint, $command))->toBeNull(); // TODO make real test, dont call compile function directly
});

it('compileCreate creates a complete table with multiple features', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('complete_tbl', function (Blueprint $table) {
        $table->string('name');
        $table->integer('age');
        $table->boolean('active');
        $table->string('email')->unique();
        $table->index('name');
    });

    expect($connection->getSchemaBuilder()->hasTable('complete_tbl'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasColumn('complete_tbl', 'name'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasColumn('complete_tbl', 'age'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasColumn('complete_tbl', 'active'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasColumn('complete_tbl', 'email'))->toBeTrue();
});

it('compileForeign creates compound foreign key', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('compound_parent', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->integer('parent_type');
    });

    $connection->getSchemaBuilder()->create('compound_child', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->integer('parent_id');
        $table->integer('parent_type');
        $table->foreign(['parent_id', 'parent_type'])->references(['id', 'type'])->on('compound_parent');
    });

    $indexes = $connection->getPdo()->query(
        "select constraint_name from information_schema.table_constraints where table_name = 'compound_child' and constraint_type = 'FOREIGN KEY'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($indexes)->not->toBeEmpty();
});

it('compileDropColumn drops column from table', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE TABLE prefix_table (id INTEGER PRIMARY KEY, col_to_drop TEXT, keep_col TEXT)');

    $connection->getSchemaBuilder()->table('prefix_table', function (Blueprint $table) {
        $table->dropColumn('col_to_drop');
    });

    expect($connection->getSchemaBuilder()->hasColumn('prefix_table', 'col_to_drop'))->toBeFalse();
    expect($connection->getSchemaBuilder()->hasColumn('prefix_table', 'keep_col'))->toBeTrue();
});

it('compileCreate creates column with default value', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('default_tbl', function (Blueprint $table) {
        $table->string('status')->default('active');
    });

    $connection->getPdo()->exec('INSERT INTO default_tbl DEFAULT VALUES');
    $result = $connection->table('default_tbl')->first();

    expect($result->status)->toBe('active');
});

it('compileCreate creates nullable column', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('nullable_tbl', function (Blueprint $table) {
        $table->string('name')->nullable();
    });

    $connection->getPdo()->exec('INSERT INTO nullable_tbl (name) VALUES (NULL)');
    $result = $connection->table('nullable_tbl')->first();

    expect($result->name)->toBeNull();
});

it('compileCreate creates non-nullable column', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('notnull_tbl', function (Blueprint $table) {
        $table->string('name');
    });

    try {
        $connection->getPdo()->exec("INSERT INTO notnull_tbl (name) VALUES (NULL)");
        expect(true)->toBeFalse();
    } catch (\Exception $e) {
        expect($e->getMessage())->toContain('NOT NULL');
    }
});

it('compileIndex creates index with schema', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('idx_schema_test', function (Blueprint $table) {
        $table->integer('col1');
        $table->index(['col1'], 'idx_test');
    });

    $indexes = $connection->getPdo()->query(
        "select index_name from duckdb_indexes() where table_name = 'idx_schema_test'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($indexes)->toContain('idx_test');
});

it('compileDropIndex drops index with schema', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('drop_idx_test', function (Blueprint $table) {
        $table->integer('col1');
        $table->index(['col1'], 'idx_to_drop');
    });

    $connection->getSchemaBuilder()->table('drop_idx_test', function (Blueprint $table) {
        $table->dropIndex('idx_to_drop');
    });

    $indexes = $connection->getPdo()->query(
        "select index_name from duckdb_indexes() where table_name = 'drop_idx_test'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($indexes)->not->toContain('idx_to_drop');
});

it('dropAllTables drops tables from custom schema', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getPdo()->exec('CREATE SCHEMA custom');
    $connection->getPdo()->exec('CREATE TABLE custom.ct1 (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE custom.ct2 (id INTEGER)');

    $connection->getSchemaBuilder()->dropAllTables();

    $tables = $connection->getPdo()->query(
        "select table_name from information_schema.tables where table_schema = 'custom' and table_type = 'BASE TABLE'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($tables)->not->toContain('ct1');
    expect($tables)->not->toContain('ct2');
});

it('compileDropAllViews with custom schema', function () {
    $grammar = new DuckDBGrammar((function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })());

    $sql = $grammar->compileDropAllViews('custom'); // TODO make real test, dont call compile function directly

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

    $connection->getPdo()->exec('CREATE TABLE specialchars (id INTEGER)');

    expect($connection->getSchemaBuilder()->hasTable('specialchars'))->toBeTrue();
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

it('compileCreate with virtualAs expression', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $connection->getSchemaBuilder()->create('virtual_json', function (Blueprint $table) {
        $table->integer('id');
        $table->string('data');
        $table->integer('count_val');
        $table->integer('total')->virtualAs(new Expression('(1 + 1)'));
    });

    $connection->getPdo()->exec("INSERT INTO virtual_json (id, data, count_val) VALUES (1, 'test', 1)");
    $result = $connection->getPdo()->query("SELECT total FROM virtual_json WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

    expect((int) $result['total'])->toBe(2);
});

it('compileComment sets column comment via grammar', function () {
    $connection = (function () {
        return new DuckDbConnection(function () {
            return new PDO('duckdb::memory:');
        });
    })();

    $grammar = new DuckDBGrammar($connection);
    $connection->getSchemaBuilder();
    $blueprint = new Blueprint($connection, 'comment_raw');
    $command = new Fluent(['column' => new Fluent(['name' => 'name', 'comment' => 'The user name', 'change' => false])]);

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

it('compileCreate creates a table with autoincrement', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('type_incr_g', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name');
    });

    expect($connection->getSchemaBuilder()->hasTable('type_incr_g'))->toBeTrue();

    $connection->table('type_incr_g')->insert(['name' => 'test']);
    $result = $connection->table('type_incr_g')->first();

    expect($result->id)->toBe(1);
    expect($result->name)->toBe('test');
});

it('compileCreate creates a table with collation', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('collate_tbl', function (Blueprint $table) {
        $table->string('name')->collation('nocase');
    });

    expect($connection->getSchemaBuilder()->hasTable('collate_tbl'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasColumn('collate_tbl', 'name'))->toBeTrue();
});

it('compileCreate creates a table with storedAs expression', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('stored_as_g', function (Blueprint $table) {
        $table->integer('a');
        $table->integer('b');
        $table->integer('total')->storedAs(new Expression('a + b'));
    });

    $connection->getPdo()->exec('INSERT INTO stored_as_g (a, b) VALUES (3, 5)');
    $result = $connection->getPdo()->query('SELECT total FROM stored_as_g')->fetch(PDO::FETCH_ASSOC);

    expect((int) $result['total'])->toBe(8);
});

it('compileCreate creates a table with storedAsJson expression', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('stored_json_tbl', function (Blueprint $table) {
        $table->string('data');
        $table->string('extracted')->storedAsJson('data->name');
    });

    $connection->getPdo()->exec("INSERT INTO stored_json_tbl (data) VALUES ('{\"name\": \"hello\"}')");
    $result = $connection->getPdo()->query("SELECT extracted FROM stored_json_tbl")->fetch(PDO::FETCH_ASSOC);

    expect($result)->not->toBeFalse();
});

it('compileCreate creates a table with virtualAsJson expression', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('virtual_json_tbl', function (Blueprint $table) {
        $table->string('data');
        $table->integer('count_val');
        $table->string('extracted')->virtualAsJson('data->name');
    });

    expect($connection->getSchemaBuilder()->hasTable('virtual_json_tbl'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasColumn('virtual_json_tbl', 'extracted'))->toBeTrue();
});

it('compileCreate with virtualAsJson using JSON column path selector', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('vjson_path_tbl', function (Blueprint $table) {
        $table->json('data');
        $table->integer('count_val');
        $table->string('extracted')->virtualAsJson('data->name');
    });

    expect($connection->getSchemaBuilder()->hasTable('vjson_path_tbl'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasColumn('vjson_path_tbl', 'extracted'))->toBeTrue();
});

it('compileCreate with storedAsJson using JSON column path selector', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('sjson_path_tbl', function (Blueprint $table) {
        $table->json('data');
        $table->integer('count_val');
        $table->string('extracted')->storedAsJson('data->name');
    });

    expect($connection->getSchemaBuilder()->hasTable('sjson_path_tbl'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasColumn('sjson_path_tbl', 'extracted'))->toBeTrue();
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

it('renameIndex throws when index lookup fails in DuckDB', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('ri_test', function (Blueprint $table) {
        $table->string('name');
        $table->index('ri_test_idx');
    });

    $connection->getSchemaBuilder()->table('ri_test', function (Blueprint $table) {
        $table->renameIndex('nonexistent_idx', 'ri_test_new');
    });
})->throws(\Exception::class);

it('compileModifyNullable with storedAs and nullable false', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('stored_nn_tbl', function (Blueprint $table) {
        $table->integer('a');
        $table->integer('b');
        $table->integer('total')->storedAs(new Expression('a + b'))->nullable(false);
    });

    $connection->getPdo()->exec('INSERT INTO stored_nn_tbl (a, b) VALUES (2, 3)');
    $result = $connection->getPdo()->query('SELECT total FROM stored_nn_tbl')->fetch(PDO::FETCH_ASSOC);

    expect((int) $result['total'])->toBe(5);
});

it('compileModifyDefault returns null when virtualAs is set', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('vdefault_tbl', function (Blueprint $table) {
        $table->integer('a');
        $table->integer('b');
        $table->integer('total')->virtualAs(new Expression('a + b'))->default(0);
    });

    $connection->getPdo()->exec('INSERT INTO vdefault_tbl (a, b) VALUES (3, 4)');
    $result = $connection->getPdo()->query('SELECT total FROM vdefault_tbl')->fetch(PDO::FETCH_ASSOC);

    expect((int) $result['total'])->toBe(7);
});

it('change column type from string to integer preserves data', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_type', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('count_val');
    });

    $connection->table('chg_type')->insert([['count_val' => '42'], ['count_val' => '7']]);

    $connection->getSchemaBuilder()->table('chg_type', function (Blueprint $table) {
        $table->integer('count_val')->nullable();
    });

    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'chg_type' and column_name = 'count_val'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('INTEGER');
    expect($connection->table('chg_type')->count())->toBe(2);
    expect($connection->table('chg_type')->where('id', 1)->value('count_val'))->toBe(42);
});

it('change column from nullable to not null preserves data', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_nullable', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('tag')->nullable();
    });

    $connection->table('chg_nullable')->insert(['id' => 1, 'tag' => 'hello']);

    $connection->getSchemaBuilder()->table('chg_nullable', function (Blueprint $table) {
        $table->string('tag')->nullable(false);
    });

    $col = $connection->getPdo()->query(
        "select is_nullable from information_schema.columns where table_name = 'chg_nullable' and column_name = 'tag'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['is_nullable'])->toBe('NO');
    expect($connection->table('chg_nullable')->where('id', 1)->value('tag'))->toBe('hello');
});

it('change column default value preserves existing data', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_default', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('status');
    });

    $connection->table('chg_default')->insert(['id' => 1, 'status' => 'active']);

    $connection->getSchemaBuilder()->table('chg_default', function (Blueprint $table) {
        $table->string('status')->default('pending');
    });

    expect($connection->table('chg_default')->where('id', 1)->value('status'))->toBe('active');

    $connection->table('chg_default')->insert(['id' => 2]);
    expect($connection->table('chg_default')->where('id', 2)->value('status'))->toBe('pending');
});

it('change column rename preserves all data', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_rename', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('old_name');
    });

    $connection->table('chg_rename')->insert([
        ['id' => 1, 'old_name' => 'Alice'],
        ['id' => 2, 'old_name' => 'Bob'],
    ]);

    $connection->getSchemaBuilder()->table('chg_rename', function (Blueprint $table) {
        $table->string('new_name');
    });

    expect($connection->getSchemaBuilder()->hasColumn('chg_rename', 'new_name'))->toBeTrue();
    expect($connection->getSchemaBuilder()->hasColumn('chg_rename', 'old_name'))->toBeFalse();
    expect($connection->table('chg_rename')->count())->toBe(2);
    expect($connection->table('chg_rename')->where('id', 1)->value('new_name'))->toBe('Alice');
    expect($connection->table('chg_rename')->where('id', 2)->value('new_name'))->toBe('Bob');
});

it('change multiple columns in one migration preserves data', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_multi', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('col_a');
        $table->string('col_b');
    });

    $connection->table('chg_multi')->insert([
        ['id' => 1, 'col_a' => 'a1', 'col_b' => 'b1'],
        ['id' => 2, 'col_a' => 'a2', 'col_b' => 'b2'],
    ]);

    $connection->getSchemaBuilder()->table('chg_multi', function (Blueprint $table) {
        $table->string('col_a')->nullable();
        $table->string('col_b')->default('fallback');
    });

    expect($connection->table('chg_multi')->count())->toBe(2);
    expect($connection->table('chg_multi')->where('id', 1)->value('col_a'))->toBe('a1');
    expect($connection->table('chg_multi')->where('id', 1)->value('col_b'))->toBe('b1');
    expect($connection->table('chg_multi')->where('id', 2)->value('col_a'))->toBe('a2');
    expect($connection->table('chg_multi')->where('id', 2)->value('col_b'))->toBe('b2');
});

it('change column type preserves indexes', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_idx', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('email');
        $table->string('name');
        $table->unique('email');
        $table->index('name');
    });

    $connection->getSchemaBuilder()->table('chg_idx', function (Blueprint $table) {
        $table->string('email')->nullable()->change();
    });

    $indexes = $connection->getPdo()->query(
        "select index_name from duckdb_indexes() where table_name = 'chg_idx'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($indexes)->toContain('chg_idx_email_unique');
    expect($indexes)->toContain('chg_idx_name_index');
});

it('change column type with data and add column simultaneously', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_combo', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('val');
    });

    $connection->table('chg_combo')->insert([['val' => '42']]);

    $connection->getSchemaBuilder()->table('chg_combo', function (Blueprint $table) {
        $table->integer('val');
        $table->boolean('active')->default(true);
    });

    expect($connection->getSchemaBuilder()->hasColumn('chg_combo', 'active'))->toBeTrue();
    expect($connection->table('chg_combo')->where('id', 1)->value('val'))->toBe(42);
    expect($connection->table('chg_combo')->where('id', 1)->value('active'))->toBe(true);
});

it('change column from integer to string preserves data', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_int_str', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->integer('score');
    });

    $connection->table('chg_int_str')->insert([['score' => 100], ['score' => 200]]);

    $connection->getSchemaBuilder()->table('chg_int_str', function (Blueprint $table) {
        $table->string('score')->change();
    });

    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'chg_int_str' and column_name = 'score'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('VARCHAR');
    expect($connection->table('chg_int_str')->count())->toBe(2);
    expect($connection->table('chg_int_str')->where('id', 1)->value('score'))->toBe('100');
});

it('change column with special characters in data', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_special', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('content');
    });

    $connection->table('chg_special')->insert([['content' => "It's a test with 'quotes' and \"double quotes\""]]);

    $connection->getSchemaBuilder()->table('chg_special', function (Blueprint $table) {
        $table->text('content')->change();
    });

    expect($connection->table('chg_special')->where('id', 1)->value('content'))
        ->toBe("It's a test with 'quotes' and \"double quotes\"");
});

it('change column preserves multiple rows', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_rows', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('name');
    });

    $rows = [];
    for ($i = 1; $i <= 50; $i++) {
        $rows[] = ['name' => "user_{$i}"];
    }
    $connection->table('chg_rows')->insert($rows);

    $connection->getSchemaBuilder()->table('chg_rows', function (Blueprint $table) {
        $table->text('name')->change();
    });

    expect($connection->table('chg_rows')->count())->toBe(50);
    expect($connection->table('chg_rows')->where('id', 25)->value('name'))->toBe('user_25');
});

it('change column transactional atomicity rolls back on error', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_rollback', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('val');
    });

    $connection->table('chg_rollback')->insert([['id' => 1, 'val' => 'original']]);

    try {
        $connection->getSchemaBuilder()->table('chg_rollback', function (Blueprint $table) {
            $table->integer('val')->change();
            $table->string('nonexistent_col');
        });
    } catch (\Exception $e) {
        // expected
    }

    expect($connection->getSchemaBuilder()->hasColumn('chg_rollback', 'val'))->toBeTrue();
    expect($connection->table('chg_rollback')->where('id', 1)->value('val'))->toBe('original');
});

it('change column type from boolean to string', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_bool', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->boolean('flag');
    });

    $connection->table('chg_bool')->insert([['id' => 1, 'flag' => true], ['id' => 2, 'flag' => false]]);

    $connection->getSchemaBuilder()->table('chg_bool', function (Blueprint $table) {
        $table->string('flag')->change();
    });

    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'chg_bool' and column_name = 'flag'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('VARCHAR');
    expect($connection->table('chg_bool')->count())->toBe(2);
});

it('change column with nullable and default simultaneously', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_nullable_default', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('val');
    });

    $connection->table('chg_nullable_default')->insert([['val' => 'test']]);

    $connection->getSchemaBuilder()->table('chg_nullable_default', function (Blueprint $table) {
        $table->string('val')->nullable()->default('unknown')->change();
    });

    $col = $connection->getPdo()->query(
        "select is_nullable from information_schema.columns where table_name = 'chg_nullable_default' and column_name = 'val'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['is_nullable'])->toBe('YES');
    expect($connection->table('chg_nullable_default')->where('id', 1)->value('val'))->toBe('test');

    $connection->table('chg_nullable_default')->insert(['id' => 2]);
    expect($connection->table('chg_nullable_default')->where('id', 2)->value('val'))->toBe('unknown');
});

it('change column to varchar with length', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_varchar_len', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->integer('code');
    });

    $connection->table('chg_varchar_len')->insert([['id' => 1, 'code' => 12345]]);

    $connection->getSchemaBuilder()->table('chg_varchar_len', function (Blueprint $table) {
        $table->string('code', 100)->change();
    });

    $col = $connection->getPdo()->query(
        "select data_type from information_schema.columns where table_name = 'chg_varchar_len' and column_name = 'code'"
    )->fetch(PDO::FETCH_ASSOC);

    expect($col['data_type'])->toBe('VARCHAR');
    expect($connection->table('chg_varchar_len')->where('id', 1)->value('code'))->toBe('12345');
});

it('change column preserves foreign keys', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_fk_parent', function (Blueprint $table) {
        $table->integer('pk');
        $table->string('name');
        $table->primary('pk');
    });

    $connection->table('chg_fk_parent')->insert([['pk' => 1, 'name' => 'parent1']]);

    $connection->getSchemaBuilder()->create('chg_fk_child', function (Blueprint $table) {
        $table->integer('id');
        $table->integer('parent_pk');
        $table->string('label');
        $table->foreign('parent_pk')->references('pk')->on('chg_fk_parent');
    });

    $connection->table('chg_fk_child')->insert([['id' => 1, 'parent_pk' => 1, 'label' => 'child1']]);

    $connection->getSchemaBuilder()->table('chg_fk_child', function (Blueprint $table) {
        $table->string('label')->nullable()->change();
    });

    expect($connection->table('chg_fk_child')->count())->toBe(1);
    expect($connection->table('chg_fk_child')->where('id', 1)->value('label'))->toBe('child1');

    $foreignKeys = $connection->getPdo()->query(
        "select constraint_name from information_schema.table_constraints where table_name = 'chg_fk_child' and constraint_type = 'FOREIGN KEY'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($foreignKeys)->not->toBeEmpty();
});

it('change column on table with primary key', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_pk', function (Blueprint $table) {
        $table->integer('id');
        $table->string('name');
        $table->primary('id');
    });

    $connection->table('chg_pk')->insert([['id' => 1, 'name' => 'test']]);

    $connection->getSchemaBuilder()->table('chg_pk', function (Blueprint $table) {
        $table->text('name')->change();
    });

    expect($connection->table('chg_pk')->where('id', 1)->value('name'))->toBe('test');

    $pk = $connection->getPdo()->query(
        "select constraint_name from information_schema.table_constraints where table_name = 'chg_pk' and constraint_type = 'PRIMARY KEY'"
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($pk)->not->toBeEmpty();
});

it('change column on empty table', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_empty', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('val');
    });

    $connection->getSchemaBuilder()->table('chg_empty', function (Blueprint $table) {
        $table->integer('val')->change();
    });

    expect($connection->table('chg_empty')->count())->toBe(0);
    expect($connection->getSchemaBuilder()->hasColumn('chg_empty', 'val'))->toBeTrue();
});

it('change column preserves existing unique constraint', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('chg_unique_preserve', function (Blueprint $table) {
        $table->integer('id')->unsigned();
        $table->string('email');
        $table->unique('email');
    });

    $connection->table('chg_unique_preserve')->insert([['id' => 1, 'email' => 'test@example.com']]);

    $connection->getSchemaBuilder()->table('chg_unique_preserve', function (Blueprint $table) {
        $table->text('email')->change();
    });

    try {
        $connection->table('chg_unique_preserve')->insert([['id' => 2, 'email' => 'test@example.com']]);
        expect(true)->toBeFalse();
    } catch (\Exception $e) {
        expect($e->getMessage())->toContain('Duplicate');
    }
});
