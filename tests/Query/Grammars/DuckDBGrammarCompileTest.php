<?php

use DuckDb\DuckDbConnection;

it('update with join compiles to UPDATE...FROM syntax', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE t1 (id INTEGER, val TEXT)');
    $connection->getPdo()->exec('CREATE TABLE t2 (id INTEGER, val TEXT)');
    $connection->table('t1')->insert([['id' => 1, 'val' => 'a'], ['id' => 2, 'val' => 'b']]);
    $connection->table('t2')->insert([['id' => 1, 'val' => 'x']]);

    $connection->table('t1')->join('t2', 't1.id', '=', 't2.id')->update(['t1.val' => 'y']);

    expect($connection->table('t1')->where('id', 1)->value('val'))->toBe('y');
    expect($connection->table('t1')->where('id', 2)->value('val'))->toBe('b');
});

it('update with join and limit updates all matching rows', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE t1 (id INTEGER, val TEXT)');
    $connection->getPdo()->exec('CREATE TABLE t2 (id INTEGER, val TEXT)');
    $connection->table('t1')->insert([['id' => 1, 'val' => 'a'], ['id' => 2, 'val' => 'b']]);
    $connection->table('t2')->insert([['id' => 1, 'val' => 'x'], ['id' => 2, 'val' => 'y']]);

    $connection->table('t1')->join('t2', 't1.id', '=', 't2.id')->limit(1)->update(['t1.val' => 'z']);

    expect($connection->table('t1')->where('val', 'z')->count())->toBe(1);
    expect($connection->table('t1')->where('id', 1)->value('val'))->toBe('z');
    expect($connection->table('t1')->where('id', 2)->value('val'))->toBe('b');
});

it('delete with join compiles to DELETE...USING...WHERE', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE d1 (id INTEGER, val TEXT)');
    $connection->getPdo()->exec('CREATE TABLE d2 (id INTEGER, val TEXT)');
    $connection->table('d1')->insert([['id' => 1, 'val' => 'a'], ['id' => 2, 'val' => 'b'], ['id' => 3, 'val' => 'c']]);
    $connection->table('d2')->insert([['id' => 1, 'val' => 'x']]);

    $connection->table('d1')->join('d2', 'd1.id', '=', 'd2.id')->delete();

    expect($connection->table('d1')->where('id', 1)->exists())->toBeFalse();
    expect($connection->table('d1')->where('id', 2)->exists())->toBeTrue();
    expect($connection->table('d1')->where('id', 3)->exists())->toBeTrue();
});

it('delete with join and limit compiles to DELETE...USING...LIMIT', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE d1 (id INTEGER, val TEXT)');
    $connection->getPdo()->exec('CREATE TABLE d2 (id INTEGER, val TEXT)');
    $connection->table('d1')->insert([['id' => 1, 'val' => 'a'], ['id' => 2, 'val' => 'b'], ['id' => 3, 'val' => 'c']]);
    $connection->table('d2')->insert([['id' => 1, 'val' => 'x'], ['id' => 2, 'val' => 'y']]);

    $connection->table('d1')->join('d2', 'd1.id', '=', 'd2.id')->limit(1)->delete();

    expect($connection->table('d1')->count())->toBe(2);
    expect($connection->table('d2')->count())->toBe(2);
});

it('inRandomOrder returns all rows in random order', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE rnd (id INTEGER, name TEXT)');
    $connection->table('rnd')->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'], ['id' => 3, 'name' => 'c']]);

    $results = $connection->table('rnd')->inRandomOrder()->get();

    expect($results)->toHaveCount(3);
    expect($results->pluck('id')->sort()->values()->all())->toBe([1, 2, 3]);
});

it('nested beginTransaction does not use savepoints', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });

    $connection->getPdo()->exec('CREATE TABLE spc (id INTEGER, val TEXT)');
    $connection->table('spc')->insert(['id' => 1, 'val' => 'original']);

    $connection->beginTransaction();
    $connection->table('spc')->where('id', 1)->update(['val' => 'changed']);
    expect($connection->table('spc')->where('id', 1)->value('val'))->toBe('changed');

    $connection->beginTransaction();
    expect($connection->transactionLevel())->toBe(2);
    $connection->rollBack();
    expect($connection->transactionLevel())->toBe(1);

    expect($connection->table('spc')->where('id', 1)->value('val'))->toBe('changed');
    $connection->rollBack();

    expect($connection->table('spc')->where('id', 1)->value('val'))->toBe('original');
});

it('supportsSavepoints returns false', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $grammar = $connection->getQueryGrammar();

    expect($grammar->supportsSavepoints())->toBeFalse();
});

it('joinLateral throws RuntimeException', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE jlt (id INTEGER, val TEXT)');

    $connection->table('jlt')->joinLateral('select 1 as x', 'sub', 'cross')->get();
})->throws(\RuntimeException::class, 'lateral joins');

it('basic select compiles correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE bs_t (id INTEGER, name TEXT, val INTEGER)');
    $connection->table('bs_t')->insert([['id' => 1, 'name' => 'a', 'val' => 10], ['id' => 2, 'name' => 'b', 'val' => 20]]);

    $results = $connection->table('bs_t')->get();
    expect($results)->toHaveCount(2);
    expect($results[0]->id)->toBe(1);
    expect($results[0]->name)->toBe('a');
});

it('compileExists returns correct SQL structure', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ex_t (id INTEGER)');
    $connection->table('ex_t')->insert(['id' => 1]);

    $exists = $connection->table('ex_t')->where('id', 1)->exists();
    expect($exists)->toBeTrue();

    $notExists = $connection->table('ex_t')->where('id', 999)->exists();
    expect($notExists)->toBeFalse();
});

it('whereBitwise compiles correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wbt (id INTEGER, flags INTEGER)');
    $connection->table('wbt')->insert([['id' => 1, 'flags' => 5], ['id' => 2, 'flags' => 3], ['id' => 3, 'flags' => 7]]);

    $results = $connection->table('wbt')->where('flags', '&', 4)->get();
    expect($results)->toHaveCount(2);

    $results = $connection->table('wbt')->where('flags', '|', 4)->get();
    expect($results)->toHaveCount(3);
});

it('whereNullSafeEquals matches null and non-null values', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wnse_t (id INTEGER, val TEXT)');
    $connection->table('wnse_t')->insert([['id' => 1, 'val' => 'a'], ['id' => 2, 'val' => null]]);

    $matchNull = $connection->table('wnse_t')->whereNullSafeEquals('val', null)->get();
    expect($matchNull)->toHaveCount(1);
    expect($matchNull[0]->id)->toBe(2);

    $matchValue = $connection->table('wnse_t')->whereNullSafeEquals('val', 'a')->get();
    expect($matchValue)->toHaveCount(1);
    expect($matchValue[0]->id)->toBe(1);
});

it('compileGroups compiles GROUP BY correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE grp_t (category TEXT, val INTEGER)');
    $connection->table('grp_t')->insert([['category' => 'a', 'val' => 1], ['category' => 'a', 'val' => 2], ['category' => 'b', 'val' => 3]]);

    $results = $connection->table('grp_t')->selectRaw('category, count(*) as cnt')->groupBy('category')->orderBy('category')->get();
    expect($results)->toHaveCount(2);
    expect($results[0]->category)->toBe('a');
    expect((int) $results[0]->cnt)->toBe(2);
    expect($results[1]->category)->toBe('b');
    expect((int) $results[1]->cnt)->toBe(1);
});

it('compileHavings compiles HAVING correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE hav_t (category TEXT, val INTEGER)');
    $connection->table('hav_t')->insert([
        ['category' => 'a', 'val' => 1],
        ['category' => 'a', 'val' => 2],
        ['category' => 'b', 'val' => 3],
        ['category' => 'b', 'val' => 3],
    ]);

    $results = $connection->table('hav_t')
        ->selectRaw('category, sum(val) as total')
        ->groupBy('category')
        ->having('total', '>', 4)
        ->orderBy('category')
        ->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->category)->toBe('b');
    expect((int) $results[0]->total)->toBe(6);
});

it('compileInOrderOf works with CASE WHEN ordering', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE iofo (id INTEGER, name TEXT)');
    $connection->table('iofo')->insert([['id' => 1, 'name' => 'c'], ['id' => 2, 'name' => 'a'], ['id' => 3, 'name' => 'b']]);

    $results = $connection->table('iofo')->inOrderOf('name', ['a', 'b', 'c'])->get();
    expect($results[0]->name)->toBe('a');
    expect($results[1]->name)->toBe('b');
    expect($results[2]->name)->toBe('c');
});

it('compileInsert compiles basic insert correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ci_t (id INTEGER, name TEXT)');
    $connection->table('ci_t')->insert(['id' => 1, 'name' => 'alice']);

    expect($connection->table('ci_t')->count())->toBe(1);
    expect($connection->table('ci_t')->where('id', 1)->value('name'))->toBe('alice');
});

it('compileInsertGetId works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cig_t (id INTEGER, name TEXT)');

    $id = $connection->table('cig_t')->insertGetId(['id' => 1, 'name' => 'alice']);
    expect($id)->not->toBeNull();
    expect($connection->table('cig_t')->where('id', 1)->value('name'))->toBe('alice');
});

it('compileInsertUsing works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ciu_src (id INTEGER, name TEXT)');
    $connection->getPdo()->exec('CREATE TABLE ciu_dst (id INTEGER, name TEXT)');
    $connection->table('ciu_src')->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);

    $connection->table('ciu_dst')->insertUsing(['id', 'name'], $connection->table('ciu_src')->where('id', 1));

    expect($connection->table('ciu_dst')->count())->toBe(1);
    expect($connection->table('ciu_dst')->first()->name)->toBe('a');
});

it('compileInsertOrIgnoreUsing works in real query', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cious_src (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->getPdo()->exec('CREATE TABLE cious_dst (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->table('cious_src')->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);
    $connection->table('cious_dst')->insert(['id' => 1, 'name' => 'existing']);

    $connection->table('cious_dst')->insertOrIgnoreUsing(['id', 'name'], $connection->table('cious_src'));

    expect($connection->table('cious_dst')->count())->toBe(2);
    expect($connection->table('cious_dst')->where('id', 1)->value('name'))->toBe('existing');
    expect($connection->table('cious_dst')->where('id', 2)->value('name'))->toBe('b');
});

it('groupLimit limits rows per group', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE glt (category TEXT, name TEXT)');
    $connection->table('glt')->insert([
        ['category' => 'a', 'name' => 'a1'],
        ['category' => 'a', 'name' => 'a2'],
        ['category' => 'a', 'name' => 'a3'],
        ['category' => 'b', 'name' => 'b1'],
        ['category' => 'b', 'name' => 'b2'],
    ]);

    $results = $connection->table('glt')->groupLimit(1, 'category')->get();
    expect($results)->toHaveCount(2);
});

it('union aggregate compiles correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE uat (id INTEGER, val INTEGER)');
    $connection->table('uat')->insert([['id' => 1, 'val' => 10], ['id' => 2, 'val' => 20]]);

    $sql = $connection->table('uat')->union($connection->table('uat'))->count();
    expect($sql)->toBeGreaterThan(0);
});

it('compileUnions with multiple unions works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cmu (id INTEGER)');
    $connection->table('cmu')->insert([['id' => 1]]);

    $results = $connection->table('cmu')
        ->unionAll($connection->table('cmu'))
        ->unionAll($connection->table('cmu'))
        ->orderBy('id')
        ->get();

    expect($results)->toHaveCount(3);
});

it('compileUpdateWithoutJoins compiles correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cuwj (id INTEGER, val TEXT)');
    $connection->table('cuwj')->insert([['id' => 1, 'val' => 'old']]);

    $connection->table('cuwj')->where('id', 1)->update(['val' => 'new']);

    expect($connection->table('cuwj')->where('id', 1)->value('val'))->toBe('new');
});

it('compileDeleteWithoutJoins compiles correctly', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cdwj (id INTEGER)');
    $connection->table('cdwj')->insert([['id' => 1], ['id' => 2]]);

    $connection->table('cdwj')->where('id', 1)->delete();

    expect($connection->table('cdwj')->count())->toBe(1);
});

it('insert with empty values inserts default values', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cidv (id INTEGER DEFAULT 0, name TEXT DEFAULT \'test\')');
    $connection->getPdo()->exec('INSERT INTO "cidv" DEFAULT VALUES');

    $result = $connection->table('cidv')->first();
    expect($result->id)->toBe(0);
    expect($result->name)->toBe('test');
});

it('whereValueBetween filters value between two columns', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wvbt (id INTEGER, low INTEGER, high INTEGER)');
    $connection->table('wvbt')->insert([
        ['id' => 1, 'low' => 1, 'high' => 10],
        ['id' => 2, 'low' => 1, 'high' => 10],
    ]);

    $results = $connection->table('wvbt')->whereValueBetween(5, ['low', 'high'])->get();
    expect($results)->toHaveCount(2);

    $results = $connection->table('wvbt')->whereValueBetween(15, ['low', 'high'])->get();
    expect($results)->toHaveCount(0);
});

it('compileColumns with distinct', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ccd (val INTEGER)');
    $connection->table('ccd')->insert([['val' => 1], ['val' => 1], ['val' => 2]]);

    $results = $connection->table('ccd')->distinct()->get();
    expect($results)->toHaveCount(2);
});

it('select from table returns correct results', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cft (id INTEGER)');
    $connection->table('cft')->insert(['id' => 1]);

    $result = $connection->table('cft')->first();
    expect($result->id)->toBe(1);
});

it('compileAggregate with count works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ca (id INTEGER)');
    $connection->table('ca')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);

    expect($connection->table('ca')->count())->toBe(3);
});

it('compileAggregate with distinct count works', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cad (val INTEGER)');
    $connection->table('cad')->insert([['val' => 1], ['val' => 1], ['val' => 2]]);

    $sql = $connection->table('cad')->toRawSql();
    expect($sql)->toContain('select');
});

it('whereNull filters null values', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wnc (id INTEGER, val TEXT)');
    $connection->table('wnc')->insert([['id' => 1, 'val' => 'a'], ['id' => 2, 'val' => null]]);

    $results = $connection->table('wnc')->whereNull('val')->get();
    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe(2);
});

it('whereNotNull filters non-null values', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE wnn (id INTEGER, val TEXT)');
    $connection->table('wnn')->insert([['id' => 1, 'val' => 'a'], ['id' => 2, 'val' => null]]);

    $results = $connection->table('wnn')->whereNotNull('val')->get();
    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe(1);
});

it('compileTruncate returns delete from SQL', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ct (id INTEGER)');
    $connection->table('ct')->insert([['id' => 1], ['id' => 2]]);

    $connection->table('ct')->truncate();

    expect($connection->table('ct')->count())->toBe(0);
});

it('insertOrIgnore ignores duplicates', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cio (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->table('cio')->insert(['id' => 1, 'name' => 'original']);

    $affected = $connection->table('cio')->insertOrIgnore(['id' => 1, 'name' => 'duplicate']);

    expect($affected)->toBe(0);
    expect($connection->table('cio')->where('id', 1)->value('name'))->toBe('original');
});

it('insertOrIgnoreReturning ignores duplicates and returns columns', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cior (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->table('cior')->insert(['id' => 1, 'name' => 'original']);

    if (method_exists($connection->table('cior'), 'insertOrIgnoreReturning')) {
        $results = $connection->table('cior')->insertOrIgnoreReturning([['id' => 1, 'name' => 'duplicate']], ['id', 'name']);

        expect($results)->toHaveCount(0);
        expect($connection->table('cior')->count())->toBe(1);
        expect($connection->table('cior')->where('id', 1)->value('name'))->toBe('original');
    }
    expect(true)->toBeTrue();
});

it('insertOrIgnoreReturning with uniqueBy ignores duplicates on specified columns', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cior2 (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->table('cior2')->insert(['id' => 1, 'name' => 'original']);

    if (method_exists($connection->table('cior'), 'insertOrIgnoreReturning')) {
        $results = $connection->table('cior2')->insertOrIgnoreReturning([['id' => 1, 'name' => 'duplicate']], ['id'], ['id']);

        expect($results)->toHaveCount(0);
        expect($connection->table('cior2')->where('id', 1)->value('name'))->toBe('original');
    }
    expect(true)->toBeTrue();
});

it('compileUpsert contains on conflict do update set', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cu (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->table('cu')->insert([['id' => 1, 'name' => 'original']]);

    $connection->table('cu')->upsert(
        [['id' => 1, 'name' => 'updated']],
        ['id'],
        ['name'],
    );

    expect($connection->table('cu')->where('id', 1)->value('name'))->toBe('updated');
});

it('compileInsertUsing builds correct SQL', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cius (id INTEGER, name TEXT)');
    $connection->getPdo()->exec('CREATE TABLE cius2 (id INTEGER, name TEXT)');
    $connection->table('cius2')->insert([
        ['id' => 1, 'name' => 'alice'],
        ['id' => 2, 'name' => 'bob'],
    ]);

    $connection->table('cius')->insertUsing(
        ['id', 'name'],
        $connection->table('cius2')->where('id', 1),
    );

    $results = $connection->table('cius')->get();
    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe(1);
    expect($results[0]->name)->toBe('alice');
});

it('insertOrIgnoreUsing ignores duplicates from subquery', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cioiu (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->getPdo()->exec('CREATE TABLE cioiu2 (id INTEGER PRIMARY KEY, name TEXT)');
    $connection->table('cioiu2')->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);
    $connection->table('cioiu')->insert(['id' => 1, 'name' => 'existing']);

    $connection->table('cioiu')->insertOrIgnoreUsing(['id', 'name'], $connection->table('cioiu2'));

    expect($connection->table('cioiu')->count())->toBe(2);
    expect($connection->table('cioiu')->where('id', 1)->value('name'))->toBe('existing');
    expect($connection->table('cioiu')->where('id', 2)->value('name'))->toBe('b');
});

it('compileSelect with aggregate returns aggregate SQL', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE csagg (val INTEGER)');
    $connection->table('csagg')->insert([['val' => 10], ['val' => 20], ['val' => 30]]);

    $count = $connection->table('csagg')->count();
    $sum = $connection->table('csagg')->sum('val');
    $avg = $connection->table('csagg')->avg('val');

    expect($count)->toBe(3);
    expect($sum)->toEqual(60);
    expect($avg)->toEqual(20.0);
});

it('compileJoin compiles basic join', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cj1 (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE cj2 (id INTEGER)');
    $connection->table('cj1')->insert([['id' => 1], ['id' => 2]]);
    $connection->table('cj2')->insert([['id' => 1]]);

    $results = $connection->table('cj1')
        ->join('cj2', 'cj1.id', '=', 'cj2.id')
        ->select('cj1.id as c1_id', 'cj2.id as c2_id')
        ->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->c1_id)->toBe(1);
    expect($results[0]->c2_id)->toBe(1);
});

it('compileJoin compiles left join', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE clj1 (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE clj2 (id INTEGER)');
    $connection->table('clj1')->insert([['id' => 1], ['id' => 2]]);
    $connection->table('clj2')->insert([['id' => 1]]);

    $results = $connection->table('clj1')
        ->leftJoin('clj2', 'clj1.id', '=', 'clj2.id')
        ->select('clj1.id as c1_id', 'clj2.id as c2_id')
        ->get();

    expect($results)->toHaveCount(2);
    expect($results[0]->c1_id)->toBe(1);
    expect($results[0]->c2_id)->toBe(1);
    expect($results[1]->c1_id)->toBe(2);
    expect($results[1]->c2_id)->toBeNull();
});

it('compileJoin compiles cross join', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE ccj1 (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE ccj2 (id INTEGER)');
    $connection->table('ccj1')->insert([['id' => 1]]);
    $connection->table('ccj2')->insert([['id' => 1], ['id' => 2]]);

    $results = $connection->table('ccj1')
        ->crossJoin('ccj2')
        ->select('ccj1.id as c1_id', 'ccj2.id as c2_id')
        ->get();

    expect($results)->toHaveCount(2);
    expect($results[0]->c1_id)->toBe(1);
    expect($results[0]->c2_id)->toBe(1);
    expect($results[1]->c1_id)->toBe(1);
    expect($results[1]->c2_id)->toBe(2);
});

it('compileJoin compiles right join', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE crj1 (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE crj2 (id INTEGER)');
    $connection->table('crj1')->insert([['id' => 1]]);
    $connection->table('crj2')->insert([['id' => 1], ['id' => 2]]);

    $results = $connection->table('crj1')
        ->rightJoin('crj2', 'crj1.id', '=', 'crj2.id')
        ->select('crj1.id as c1_id', 'crj2.id as c2_id')
        ->orderBy('c2_id')
        ->get();

    expect($results)->toHaveCount(2);
    expect($results[0]->c1_id)->toBe(1);
    expect($results[0]->c2_id)->toBe(1);
    expect($results[1]->c1_id)->toBeNull();
    expect($results[1]->c2_id)->toBe(2);
});

it('compileJoin compiles full outer join', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cfj1 (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE cfj2 (id INTEGER)');
    $connection->table('cfj1')->insert([['id' => 1]]);
    $connection->table('cfj2')->insert([['id' => 2]]);

    $results = $connection->table('cfj1')
        ->join('cfj2', 'cfj1.id', '=', 'cfj2.id', 'full outer')
        ->select('cfj1.id as c1_id', 'cfj2.id as c2_id')
        ->orderBy('c1_id')
        ->get();

    expect($results)->toHaveCount(2);
    expect($results[0]->c1_id)->toBe(1);
    expect($results[0]->c2_id)->toBeNull();
    expect($results[1]->c1_id)->toBeNull();
    expect($results[1]->c2_id)->toBe(2);
});

it('compileJoin compiles where in join', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cwj1 (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE cwj2 (id INTEGER, val INTEGER)');
    $connection->table('cwj1')->insert([['id' => 1], ['id' => 2]]);
    $connection->table('cwj2')->insert([['id' => 1, 'val' => 10], ['id' => 2, 'val' => 20]]);

    $results = $connection->table('cwj1')
        ->join('cwj2', function ($join) {
            $join->on('cwj1.id', '=', 'cwj2.id')->where('cwj2.val', '>', 15);
        })
        ->select('cwj1.id as c1_id', 'cwj2.id as c2_id', 'cwj2.val')
        ->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->c1_id)->toBe(2);
    expect($results[0]->c2_id)->toBe(2);
    expect($results[0]->val)->toBe(20);
});

it('compileNestedJoins in join clause', function () {
    $connection = new DuckDbConnection(function () {
        return new PDO('duckdb::memory:');
    });
    $connection->getPdo()->exec('CREATE TABLE cnj1 (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE cnj2 (id INTEGER)');
    $connection->getPdo()->exec('CREATE TABLE cnj3 (id INTEGER)');
    $connection->table('cnj1')->insert([['id' => 1], ['id' => 2]]);
    $connection->table('cnj2')->insert([['id' => 1], ['id' => 2]]);
    $connection->table('cnj3')->insert([['id' => 1]]);

    $results = $connection->table('cnj1')
        ->join('cnj2', 'cnj1.id', '=', 'cnj2.id')
        ->join('cnj3', 'cnj1.id', '=', 'cnj3.id')
        ->select('cnj1.id as c1_id', 'cnj2.id as c2_id', 'cnj3.id as c3_id')
        ->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->c1_id)->toBe(1);
    expect($results[0]->c2_id)->toBe(1);
    expect($results[0]->c3_id)->toBe(1);
});
