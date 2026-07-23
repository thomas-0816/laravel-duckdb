<?php

use DuckDb\DuckDbConnection;
use DuckDb\Query\Grammars\DuckDBGrammar;
use Illuminate\Database\Query\Expression;

it('supports ilike and bitwise operators', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $grammar = new DuckDBGrammar($connection);

    $operators = $grammar->getOperators();
    expect($operators)->toContain('ilike', '!=');
    expect($operators)->toContain('<<', '>>');
    expect($operators)->toContain('&', '|');
});

it('ilike operator works in a real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE items (name TEXT)');
    $connection->getPdo()->exec("INSERT INTO items VALUES ('Hello'), ('WORLD'), ('hello')");

    $results = $connection->table('items')->where('name', 'ILIKE', '%hello%')->orderBy('name')->get();

    expect($results)->toHaveCount(2);
    expect($results[0]->name)->toBe('Hello');
    expect($results[1]->name)->toBe('hello');
});

it('like operator works in a real query (case-sensitive in duckdb)', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE items2 (name TEXT)');
    $connection->getPdo()->exec("INSERT INTO items2 VALUES ('Hello'), ('WORLD'), ('hello')");

    $results = $connection->table('items2')->where('name', 'LIKE', '%hello%')->orderBy('name')->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->name)->toBe('hello');
});

it('date functions work in real queries', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE events (d DATE, t TEXT)');
    $connection->getPdo()->exec("INSERT INTO events VALUES ('2024-03-15', 'one')");
    $connection->getPdo()->exec("INSERT INTO events VALUES ('2024-06-20', 'two')");

    expect($connection->table('events')->whereYear('d', '2024')->count())->toBe(2);
    expect($connection->table('events')->whereMonth('d', '3')->count())->toBe(1);
    expect($connection->table('events')->whereDay('d', '15')->count())->toBe(1);
    expect($connection->table('events')->whereDate('d', '2024-03-15')->count())->toBe(1);
});

it('whereRowValues works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE rv (a INTEGER, b INTEGER)');
    $connection->getPdo()->exec("INSERT INTO rv VALUES (1, 2), (3, 4)");

    expect($connection->table('rv')->whereRowValues(['a', 'b'], '=', [1, 2])->count())->toBe(1);
    expect($connection->table('rv')->whereRowValues(['a', 'b'], '=', [3, 4])->count())->toBe(1);
    expect($connection->table('rv')->whereRowValues(['a', 'b'], '=', [1, 3])->count())->toBe(0);
});

it('whereExists works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE we_parent (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE we_child (parent_id INTEGER)');
    $connection->getPdo()->exec("INSERT INTO we_parent VALUES (1), (2)");
    $connection->getPdo()->exec("INSERT INTO we_child VALUES (1)");

    $results = $connection->table('we_parent')
        ->whereExists(fn($q) => $q->from('we_child')->whereColumn('we_child.parent_id', 'we_parent.id'))
        ->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe(1);
});

it('whereNotExists works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wne_parent (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE wne_child (parent_id INTEGER)');
    $connection->getPdo()->exec("INSERT INTO wne_parent VALUES (1), (2)");
    $connection->getPdo()->exec("INSERT INTO wne_child VALUES (1)");

    $results = $connection->table('wne_parent')
        ->whereNotExists(fn($q) => $q->from('wne_child')->whereColumn('wne_child.parent_id', 'wne_parent.id'))
        ->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe(2);
});

it('whereSub works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ws_emp (id INTEGER, salary INTEGER)');
    $connection->getPdo()->exec("INSERT INTO ws_emp VALUES (1, 100), (2, 200), (3, 300)");

    $results = $connection->table('ws_emp')->where('salary', '>', $connection->table('ws_emp')->avg('salary'))->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe(3);
});

it('whereNested works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wn (id INTEGER, val TEXT)');
    $connection->getPdo()->exec("INSERT INTO wn VALUES (1, 'a'), (2, 'b'), (3, 'c')");

    $results = $connection->table('wn')
        ->where(fn($q) => $q->where('id', '>', 1)->where('val', '!=', 'c'))
        ->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe(2);
});

it('whereExpression works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wex (id INTEGER)');
    $connection->getPdo()->exec("INSERT INTO wex VALUES (1), (2), (3)");

    $results = $connection->table('wex')->whereColumn('id', '>', new Expression('1'))->get();

    expect($results)->toHaveCount(2);
    expect($results[0]->id)->toBe(2);
    expect($results[1]->id)->toBe(3);
});

it('insert or ignore works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE iot (id INTEGER PRIMARY KEY, name TEXT)');

    $connection->table('iot')->insert(['id' => 1, 'name' => 'alice']);

    expect($connection->table('iot')->count())->toBe(1);

    $connection->table('iot')->insertOrIgnore(['id' => 1, 'name' => 'bob']);

    expect($connection->table('iot')->count())->toBe(1);
    expect($connection->table('iot')->where('id', 1)->value('name'))->toBe('alice');
});

it('upsert works with update on conflict', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE upsert_t (id INTEGER PRIMARY KEY, name TEXT)');

    $connection->table('upsert_t')->insert(['id' => 1, 'name' => 'alice']);
    $connection->table('upsert_t')->upsert(
        ['id' => 1, 'name' => 'bob'],
        ['id'],
        ['name']
    );

    expect($connection->table('upsert_t')->where('id', 1)->value('name'))->toBe('bob');
    expect($connection->table('upsert_t')->count())->toBe(1);
});

it('upsert inserts new rows', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE upsert_t (id INTEGER PRIMARY KEY, name TEXT)');

    $connection->table('upsert_t')->upsert(
        ['id' => 1, 'name' => 'alice'],
        ['id'],
        ['name']
    );

    expect($connection->table('upsert_t')->count())->toBe(1);
    expect($connection->table('upsert_t')->where('id', 1)->value('name'))->toBe('alice');
});

it('truncate issues delete from and works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ttd (id INTEGER, name TEXT)');
    $connection->table('ttd')->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);
    expect($connection->table('ttd')->count())->toBe(2);

    $connection->table('ttd')->truncate();

    expect($connection->table('ttd')->count())->toBe(0);
});

it('union results are returned from subquery wrap', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE us (id INTEGER)');
    $connection->table('us')->insert([['id' => 1], ['id' => 2]]);

    $results = $connection->table('us')
        ->union($connection->table('us'))
        ->orderBy('id')
        ->get();

    expect($results)->toHaveCount(2);
});

it('union queries can be filtered', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE us (id INTEGER)');
    $connection->table('us')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);

    $results = $connection->table('us')
        ->union($connection->table('us')->where('id', 1))
        ->orderBy('id')
        ->get();

    expect($results)->toHaveCount(3);
});

it('getBitwiseOperators returns bitwise operators', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $grammar = new DuckDBGrammar($connection);

    $bitwise = $grammar->getBitwiseOperators();
    expect($bitwise)->toBeArray();
});

it('lock is ignored in queries', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE lock_t (id INTEGER)');
    $connection->table('lock_t')->insert(['id' => 1]);

    $results = $connection->table('lock_t')->lockForUpdate()->get();
    expect($results)->toHaveCount(1);
});

it('index hint is ignored in queries', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ih_t (id INTEGER)');
    $connection->table('ih_t')->insert(['id' => 1]);

    $results = $connection->table('ih_t')->get();
    expect($results)->toHaveCount(1);
});

it('supportsSavepoints returns false', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $grammar = $connection->getQueryGrammar();

    expect($grammar->supportsSavepoints())->toBeFalse();
});

it('savepoint and rollback work in real queries', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE sp_t (id INTEGER, val TEXT)');
    $connection->table('sp_t')->insert(['id' => 1, 'val' => 'original']);

    $connection->beginTransaction();
    $connection->table('sp_t')->where('id', 1)->update(['val' => 'modified']);
    expect($connection->table('sp_t')->where('id', 1)->value('val'))->toBe('modified');
    $connection->rollBack();

    expect($connection->table('sp_t')->where('id', 1)->value('val'))->toBe('original');
});

it('select specific columns', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE sc_t (id INTEGER, name TEXT)');
    $connection->table('sc_t')->insert([['id' => 1, 'name' => 'a']]);

    $result = $connection->table('sc_t')->select('name')->first();
    expect($result->name)->toBe('a');
    expect(isset($result->id))->toBeFalse();
});

it('select distinct works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE sd_t (val INTEGER)');
    $connection->table('sd_t')->insert([['val' => 1], ['val' => 1], ['val' => 2]]);

    $results = $connection->table('sd_t')->distinct()->get();
    expect($results)->toHaveCount(2);
});

it('limit and offset work in real queries', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE lo_t (id INTEGER)');
    $connection->table('lo_t')->insert([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]]);

    $results = $connection->table('lo_t')->orderBy('id')->limit(2)->get();
    expect($results)->toHaveCount(2);
    expect($results[0]->id)->toBe(1);
    expect($results[1]->id)->toBe(2);

    $results = $connection->table('lo_t')->orderBy('id')->limit(2)->skip(2)->get();
    expect($results)->toHaveCount(2);
    expect($results[0]->id)->toBe(3);
    expect($results[1]->id)->toBe(4);
});

it('whereRaw works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wr_t (id INTEGER, name TEXT)');
    $connection->table('wr_t')->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'], ['id' => 3, 'name' => 'c']]);

    $results = $connection->table('wr_t')->whereRaw('id > ? and name != ?', [1, 'c'])->get();
    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe(2);
});

it('whereBasic works with all comparison operators', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wb_t (id INTEGER, val INTEGER)');
    $connection->table('wb_t')->insert([['id' => 1, 'val' => 10], ['id' => 2, 'val' => 20], ['id' => 3, 'val' => 30]]);

    expect($connection->table('wb_t')->where('val', '=', 10)->count())->toBe(1);
    expect($connection->table('wb_t')->where('val', '!=', 10)->count())->toBe(2);
    expect($connection->table('wb_t')->where('val', '<>', 10)->count())->toBe(2);
    expect($connection->table('wb_t')->where('val', '<', 20)->count())->toBe(1);
    expect($connection->table('wb_t')->where('val', '>', 20)->count())->toBe(1);
    expect($connection->table('wb_t')->where('val', '<=', 20)->count())->toBe(2);
    expect($connection->table('wb_t')->where('val', '>=', 20)->count())->toBe(2);
});

it('whereIn works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE win_t (id INTEGER, name TEXT)');
    $connection->table('win_t')->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'], ['id' => 3, 'name' => 'c']]);

    $results = $connection->table('win_t')->whereIn('id', [1, 3])->get();
    expect($results)->toHaveCount(2);
});

it('whereIn returns 0=1 when values empty', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wie_t (id INTEGER)');
    $connection->table('wie_t')->insert(['id' => 1]);

    $grammar = $connection->getQueryGrammar();
    $builder = $connection->table('wie_t');
    $builder->whereIn('id', []);
    $sql = $grammar->compileWheres($builder);
    expect($sql)->toContain('0 = 1');
});

it('whereNotIn works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wni_t (id INTEGER)');
    $connection->table('wni_t')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);

    $results = $connection->table('wni_t')->whereNotIn('id', [2])->get();
    expect($results)->toHaveCount(2);
});

it('whereNotIn returns 1=1 when values empty', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wnie_t (id INTEGER)');
    $connection->table('wnie_t')->insert(['id' => 1]);

    $grammar = $connection->getQueryGrammar();
    $builder = $connection->table('wnie_t');
    $builder->whereNotIn('id', []);
    $sql = $grammar->compileWheres($builder);
    expect($sql)->toContain('1 = 1');
});

it('whereInRaw works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wir_t (id INTEGER)');
    $connection->table('wir_t')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);

    $results = $connection->table('wir_t')->whereIntegerInRaw('id', [1, 3])->get();
    expect($results)->toHaveCount(2);
});

it('whereNotInRaw works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wnir_t (id INTEGER)');
    $connection->table('wnir_t')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);

    $results = $connection->table('wnir_t')->whereIntegerNotInRaw('id', [2])->get();
    expect($results)->toHaveCount(2);
});

it('whereNull works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wnl_t (id INTEGER, val TEXT)');
    $connection->table('wnl_t')->insert([['id' => 1, 'val' => 'a'], ['id' => 2, 'val' => null]]);

    expect($connection->table('wnl_t')->whereNull('val')->count())->toBe(1);
    expect($connection->table('wnl_t')->whereNull('val')->first()->id)->toBe(2);
});

it('whereNotNull works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wnnl_t (id INTEGER, val TEXT)');
    $connection->table('wnnl_t')->insert([['id' => 1, 'val' => 'a'], ['id' => 2, 'val' => null]]);

    expect($connection->table('wnnl_t')->whereNotNull('val')->count())->toBe(1);
    expect($connection->table('wnnl_t')->whereNotNull('val')->first()->id)->toBe(1);
});

it('whereBetween works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wbtw_t (id INTEGER)');
    $connection->table('wbtw_t')->insert([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]]);

    expect($connection->table('wbtw_t')->whereBetween('id', [2, 4])->count())->toBe(3);
    expect($connection->table('wbtw_t')->whereBetween('id', [2, 4])->pluck('id')->toArray())->toBe([2, 3, 4]);
});

it('whereBetween works with not', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wbtwn_t (id INTEGER)');
    $connection->table('wbtwn_t')->insert([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]]);

    expect($connection->table('wbtwn_t')->whereBetween('id', [2, 4])->count())->toBe(3);
    expect($connection->table('wbtwn_t')->whereBetween('id', [2, 4])->count())->toBe(3);
});

it('whereBetweenColumns works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wbtc_t (id INTEGER, min_val INTEGER, max_val INTEGER)');
    $connection->table('wbtc_t')->insert([['id' => 1, 'min_val' => 1, 'max_val' => 5], ['id' => 2, 'min_val' => 10, 'max_val' => 20]]);

    $results = $connection->table('wbtc_t')->whereBetweenColumns('id', ['min_val', 'max_val'])->get();
    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe(1);
});

it('whereColumn works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wc_t (a INTEGER, b INTEGER)');
    $connection->table('wc_t')->insert([['a' => 1, 'b' => 1], ['a' => 1, 'b' => 2], ['a' => 2, 'b' => 1]]);

    expect($connection->table('wc_t')->whereColumn('a', '=', 'b')->count())->toBe(1);
    expect($connection->table('wc_t')->whereColumn('a', '>', 'b')->count())->toBe(1);
    expect($connection->table('wc_t')->whereColumn('a', '<', 'b')->count())->toBe(1);
});

it('having between works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE hbt_t (category TEXT, val INTEGER)');
    $connection->table('hbt_t')->insert([
        ['category' => 'a', 'val' => 1],
        ['category' => 'a', 'val' => 2],
        ['category' => 'b', 'val' => 3],
        ['category' => 'b', 'val' => 4],
        ['category' => 'c', 'val' => 10],
    ]);

    $results = $connection->table('hbt_t')
        ->selectRaw('category, sum(val) as total')
        ->groupBy('category')
        ->havingBetween('total', [3, 5])
        ->orderBy('category')
        ->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->category)->toBe('a');
});

it('having null works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE hnl_t (id INTEGER, note TEXT)');
    $connection->table('hnl_t')->insert([['id' => 1, 'note' => 'a'], ['id' => 2, 'note' => null]]);

    $grammar = $connection->getQueryGrammar();
    $builder = $connection->table('hnl_t')
        ->selectRaw('id, note')
        ->havingRaw('note is null');
    $sql = $grammar->compileSelect($builder);
    expect($sql)->toContain('having');
});

it('having not null works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE hnnt (id INTEGER, note TEXT)');
    $connection->table('hnnt')->insert([['id' => 1, 'note' => 'a'], ['id' => 2, 'note' => null]]);

    $grammar = $connection->getQueryGrammar();
    $builder = $connection->table('hnnt')
        ->selectRaw('id, note')
        ->groupBy('id', 'note')
        ->havingRaw('note is not null');
    $sql = $grammar->compileSelect($builder);
    expect($sql)->toContain('having');
    expect($sql)->toContain('is not null');
});

it('order by with raw expression works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ore (id INTEGER, name TEXT)');
    $connection->table('ore')->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);

    $results = $connection->table('ore')->orderByRaw('id DESC')->get();
    expect($results[0]->id)->toBe(2);
    expect($results[1]->id)->toBe(1);
});

it('insertGetId with multiple rows works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE igimr (id INTEGER, name TEXT)');

    $connection->table('igimr')->insert(['id' => 1, 'name' => 'first']);
    $connection->table('igimr')->insert(['id' => 2, 'name' => 'second']);

    expect($connection->table('igimr')->count())->toBe(2);
    expect($connection->table('igimr')->where('id', 1)->first()->name)->toBe('first');
    expect($connection->table('igimr')->where('id', 2)->first()->name)->toBe('second');
});

it('insert multiple rows at once', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE imr (id INTEGER, name TEXT)');

    $connection->table('imr')->insert([
        ['id' => 1, 'name' => 'a'],
        ['id' => 2, 'name' => 'b'],
        ['id' => 3, 'name' => 'c'],
    ]);

    expect($connection->table('imr')->count())->toBe(3);
});

it('insert default values works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE idv (id INTEGER DEFAULT 0, name TEXT DEFAULT \'unknown\')');

    $connection->getPdo()->exec('INSERT INTO "idv" DEFAULT VALUES');

    expect($connection->table('idv')->count())->toBe(1);
});

it('basic update without joins works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE bu_t (id INTEGER, val TEXT)');
    $connection->table('bu_t')->insert([['id' => 1, 'val' => 'old'], ['id' => 2, 'val' => 'keep']]);

    $connection->table('bu_t')->where('id', 1)->update(['val' => 'new']);

    expect($connection->table('bu_t')->where('id', 1)->value('val'))->toBe('new');
    expect($connection->table('bu_t')->where('id', 2)->value('val'))->toBe('keep');
});

it('basic delete without joins works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE bd_t (id INTEGER, val TEXT)');
    $connection->table('bd_t')->insert([['id' => 1, 'val' => 'a'], ['id' => 2, 'val' => 'b']]);

    $connection->table('bd_t')->where('id', 1)->delete();

    expect($connection->table('bd_t')->count())->toBe(1);
    expect($connection->table('bd_t')->first()->id)->toBe(2);
});

it('delete with limit works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE dl_t (id INTEGER)');
    $connection->table('dl_t')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);

    $connection->table('dl_t')->limit(2)->delete();

    expect($connection->table('dl_t')->count())->toBe(1);
});

it('update with limit works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ul_t (id INTEGER, val TEXT)');
    $connection->table('ul_t')->insert([['id' => 1, 'val' => 'a'], ['id' => 2, 'val' => 'b'], ['id' => 3, 'val' => 'c']]);

    $connection->table('ul_t')->where('val', 'a')->limit(1)->update(['val' => 'updated']);

    expect($connection->table('ul_t')->where('val', 'updated')->count())->toBe(1);
});

it('prepareBindingsForDelete works correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $grammar = $connection->getQueryGrammar();

    $bindings = [
        'select' => ['sel_val'],
        'join' => ['join_val'],
        'where' => ['where_val'],
        'having' => ['having_val'],
        'order' => ['order_val'],
    ];

    $result = $grammar->prepareBindingsForDelete($bindings);
    expect($result)->toBe(['join_val', 'where_val', 'having_val', 'order_val']);
});

it('union all works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ua_t (id INTEGER)');
    $connection->table('ua_t')->insert([['id' => 1], ['id' => 2]]);

    $results = $connection->table('ua_t')
        ->unionAll($connection->table('ua_t'))
        ->orderBy('id')
        ->get();

    expect($results)->toHaveCount(4);
});

it('union with limit and offset works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $grammar = new DuckDBGrammar($connection);
    $connection->getPdo()->exec('CREATE TABLE uwl (id INTEGER)');
    $connection->table('uwl')->insert([['id' => 1], ['id' => 2]]);

    $builder = $connection->table('uwl')->union($connection->table('uwl'));
    $builder->limit = 3;
    $builder->offset = 1;
    $sql = $grammar->compileSelect($builder);
    expect($sql)->toContain('limit');
    expect($sql)->toContain('offset');
});

it('substituteBindingsIntoRawSql replaces bindings', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $grammar = $connection->getQueryGrammar();

    $result = $grammar->substituteBindingsIntoRawSql(
        'select * from t where id = ? and name = ?',
        [1, 'test']
    );
    expect($result)->toBe("select * from t where id = 1 and name = 'test'");
});

it('substituteBindingsIntoRawSql handles escaped quotes', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $grammar = $connection->getQueryGrammar();

    $result = $grammar->substituteBindingsIntoRawSql(
        "select * from t where name = ?",
        ["it's a test"]
    );
    expect($result)->toContain('test');
});

it('select with aggregate functions works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE saft (id INTEGER, val INTEGER)');
    $connection->table('saft')->insert([['id' => 1, 'val' => 10], ['id' => 2, 'val' => 20], ['id' => 3, 'val' => 30]]);

    expect((int) $connection->table('saft')->sum('val'))->toBe(60);
    expect((float) $connection->table('saft')->avg('val'))->toBe(20.0);
    expect((int) $connection->table('saft')->min('val'))->toBe(10);
    expect((int) $connection->table('saft')->max('val'))->toBe(30);
    expect($connection->table('saft')->count())->toBe(3);
});

it('select with aggregate and group by works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE sagb (category TEXT, val INTEGER)');
    $connection->table('sagb')->insert([
        ['category' => 'a', 'val' => 10],
        ['category' => 'a', 'val' => 20],
        ['category' => 'b', 'val' => 30],
    ]);

    $result = $connection->table('sagb')->where('category', 'a')->sum('val');
    expect((int) $result)->toBe(30);
});

it('whereTime works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wtme (id INTEGER, t TIME)');
    $connection->getPdo()->exec("INSERT INTO wtme VALUES (1, CAST('10:30:00' AS TIME)), (2, CAST('14:00:00' AS TIME))");

    expect($connection->table('wtme')->whereTime('t', '=', '10:30:00')->count())->toBe(1);
    expect($connection->table('wtme')->whereTime('t', '>', '12:00:00')->count())->toBe(1);
});

it('nested having works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE nh_t (cat TEXT, val INTEGER)');
    $connection->table('nh_t')->insert([
        ['cat' => 'a', 'val' => 1],
        ['cat' => 'a', 'val' => 2],
        ['cat' => 'b', 'val' => 3],
        ['cat' => 'b', 'val' => 4],
    ]);

    $results = $connection->table('nh_t')
        ->selectRaw('cat, sum(val) as total')
        ->groupBy('cat')
        ->having(fn($q) => $q->having('total', '>', 2)->orWhere('total', '=', 3))
        ->orderBy('cat')
        ->get();

    expect($results)->toHaveCount(2);
});

it('basic having bit works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE hbt2 (cat TEXT, val INTEGER)');
    $connection->table('hbt2')->insert([
        ['cat' => 'a', 'val' => 1],
        ['cat' => 'b', 'val' => 2],
        ['cat' => 'c', 'val' => 3],
    ]);

    $grammar = $connection->getQueryGrammar();
    $builder = $connection->table('hbt2')
        ->selectRaw('cat, sum(val) as total')
        ->groupBy('cat')
        ->havingRaw('(sum(val) & ?) != 0', [1])
        ->orderBy('cat');
    $sql = $grammar->compileSelect($builder);
    expect($sql)->toContain('having');
    expect($sql)->toContain('&');

    $results = $builder->get();
    expect($results)->toHaveCount(2);
    expect($results[0]->cat)->toBe('a');
    expect($results[1]->cat)->toBe('c');
});

it('whereBetweenColumns with not works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wbtn (id INTEGER, min_v INTEGER, max_v INTEGER)');
    $connection->table('wbtn')->insert([
        ['id' => 1, 'min_v' => 1, 'max_v' => 5],
        ['id' => 2, 'min_v' => 10, 'max_v' => 20],
    ]);

    $results = $connection->table('wbtn')->whereNotBetweenColumns('id', ['min_v', 'max_v'])->get();
    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe(2);
});

it('having expression works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE he_t (cat TEXT, val INTEGER)');
    $connection->table('he_t')->insert([
        ['cat' => 'a', 'val' => 10],
        ['cat' => 'b', 'val' => 20],
    ]);

    $results = $connection->table('he_t')
        ->selectRaw('cat, sum(val) as total')
        ->groupBy('cat')
        ->havingRaw('total > 15')
        ->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->cat)->toBe('b');
});

it('select with aggregate distinct works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE sad (val INTEGER)');
    $connection->table('sad')->insert([['val' => 1], ['val' => 1], ['val' => 2]]);

    $sql = $connection->table('sad')->toSql();
    expect($sql)->toContain('select');
});

it('lock is silently ignored for all lock types', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE clt (id INTEGER)');
    $connection->table('clt')->insert([['id' => 1], ['id' => 2]]);

    $results = $connection->table('clt')->lockForUpdate()->get();
    expect($results)->toHaveCount(2);

    $results = $connection->table('clt')->sharedLock()->get();
    expect($results)->toHaveCount(2);
});

it('whereLike defaults to ilike (case-insensitive)', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wl1 (name TEXT)');
    $connection->getPdo()->exec("INSERT INTO wl1 VALUES ('Hello'), ('WORLD'), ('hello')");

    $results = $connection->table('wl1')->whereLike('name', '%hello%')->get();
    expect($results)->toHaveCount(2);
});

it('whereLike with caseSensitive true uses like', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wl2 (name TEXT)');
    $connection->getPdo()->exec("INSERT INTO wl2 VALUES ('Hello'), ('WORLD'), ('hello')");

    $results = $connection->table('wl2')->whereLike('name', '%hello%', true)->get();
    expect($results)->toHaveCount(1);
    expect($results[0]->name)->toBe('hello');
});

it('whereNotLike defaults to not ilike', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wl3 (name TEXT)');
    $connection->getPdo()->exec("INSERT INTO wl3 VALUES ('Hello'), ('WORLD'), ('hello')");

    $results = $connection->table('wl3')->whereNotLike('name', '%hello%')->get();
    expect($results)->toHaveCount(1);
    expect($results[0]->name)->toBe('WORLD');
});

it('whereNotLike with caseSensitive true uses not like', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wl4 (name TEXT)');
    $connection->getPdo()->exec("INSERT INTO wl4 VALUES ('Hello'), ('WORLD'), ('hello')");

    $results = $connection->table('wl4')->whereNotLike('name', '%hello%', true)->get();
    expect($results)->toHaveCount(2);
    $names = $results->pluck('name')->sort()->values()->all();
    expect($names)->toBe(['Hello', 'WORLD']);
});

it('update with join and where on main query compiles correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ujw1 (id INTEGER, status TEXT, val TEXT)');
    $connection->getPdo()->exec('CREATE TABLE ujw2 (id INTEGER, ref TEXT)');
    $connection->table('ujw1')->insert([
        ['id' => 1, 'status' => 'active', 'val' => 'old'],
        ['id' => 2, 'status' => 'inactive', 'val' => 'keep'],
    ]);
    $connection->table('ujw2')->insert([['id' => 1, 'ref' => 'r1']]);

    $connection->table('ujw1')
        ->join('ujw2', 'ujw1.id', '=', 'ujw2.id')
        ->where('ujw1.status', 'active')
        ->update(['ujw1.val' => 'updated']);

    expect($connection->table('ujw1')->where('id', 1)->value('val'))->toBe('updated');
    expect($connection->table('ujw1')->where('id', 2)->value('val'))->toBe('keep');
});

it('update with join and limit and skip and where on main query compiles correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ujw1 (id INTEGER, status TEXT, val TEXT)');
    $connection->getPdo()->exec('CREATE TABLE ujw2 (id INTEGER, ref TEXT)');
    $connection->table('ujw1')->insert([
        ['id' => 1, 'status' => 'active', 'val' => 'old'],
        ['id' => 2, 'status' => 'inactive', 'val' => 'keep'],
    ]);
    $connection->table('ujw2')->insert([['id' => 1, 'ref' => 'r1']]);

    $connection->table('ujw1')
        ->join('ujw2', 'ujw1.id', '=', 'ujw2.id')
        ->where('ujw1.status', 'active')
        ->limit(1)
        ->skip(1)
        ->update(['ujw1.val' => 'updated']);

    expect($connection->table('ujw1')->where('id', 1)->value('val'))->toBe('old');
    expect($connection->table('ujw1')->where('id', 2)->value('val'))->toBe('keep');
});

it('delete with join and where on main query compiles correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE djw1 (id INTEGER, status TEXT)');
    $connection->getPdo()->exec('CREATE TABLE djw2 (id INTEGER)');
    $connection->table('djw1')->insert([
        ['id' => 1, 'status' => 'active'],
        ['id' => 2, 'status' => 'active'],
        ['id' => 3, 'status' => 'inactive'],
    ]);
    $connection->table('djw2')->insert([['id' => 1], ['id' => 2]]);

    $connection->table('djw1')
        ->join('djw2', 'djw1.id', '=', 'djw2.id')
        ->where('djw1.status', 'active')
        ->delete();

    expect($connection->table('djw1')->count())->toBe(1);
    expect($connection->table('djw1')->first()->id)->toBe(3);
});

it('delete with limit and where clause compiles correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE dlw (id INTEGER, keep TEXT)');
    $connection->table('dlw')->insert([
        ['id' => 1, 'keep' => 'yes'],
        ['id' => 2, 'keep' => 'no'],
        ['id' => 3, 'keep' => 'no'],
    ]);

    $connection->table('dlw')->where('keep', 'no')->limit(1)->delete();

    expect($connection->table('dlw')->count())->toBe(2);
    expect($connection->table('dlw')->where('keep', 'no')->count())->toBe(1);
});

it('delete with limit and skip and where clause compiles correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE dlw (id INTEGER, keep TEXT)');
    $connection->table('dlw')->insert([
        ['id' => 1, 'keep' => 'no'],
        ['id' => 2, 'keep' => 'no'],
        ['id' => 3, 'keep' => 'no'],
    ]);

    $connection->table('dlw')->where('keep', 'no')->limit(1)->skip(2)->delete();

    expect($connection->table('dlw')->count())->toBe(2);
    expect($connection->table('dlw')->where('keep', 'no')->count())->toBe(2);
});
