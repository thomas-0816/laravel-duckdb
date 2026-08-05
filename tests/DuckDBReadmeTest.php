<?php

use DuckDb\DuckDbConnection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

class Resolver implements ConnectionResolverInterface
{
    public function __construct(private DuckDbConnection $connection) {}
    public function connection($connection = null)
    {
        return $this->connection;
    }
    public function getDefaultConnection() {}
    public function setDefaultConnection($name) {}
}

class Person extends Model
{
    protected $guarded = [];
}

class Event extends Model
{
    protected $connection = 'duckdb';
    protected $table = 'events';

    protected function person(): Attribute
    {
        return Attribute::get(fn($person) => new Person($person));
    }
    protected function persons(): Attribute
    {
        return Attribute::get(fn($persons) => collect($persons)->map(fn($values) => new Person($values)));
    }
}

class TestCsv extends Model
{
    protected $connection = 'duckdb';
    protected $table = '-';
}

class LogsJson extends Model
{
    protected $connection = 'duckdb';
    protected $table = '-';
}

it('verifies examples from readme', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));
    $connection->getSchemaBuilder()->create('events', function (Blueprint $table) {
        $table->id();
        $table->string('category');
        $table->decimal('amount', 12, 2);
        $table->json('tags')->nullable();
        $table->timestamps();
    });

    $connection->table('events')->insert([[
        'category' => 'conference',
        'amount' => 42.21,
        'tags' => ['Hello', 'DuckDB'],
        'created_at' => '2026-01-02 03:04:05',
        'updated_at' => '2026-02-03 04:05:06',
    ]]);

    $result = $connection->query()
        ->selectExpression("date_trunc('week', created_at)", 'week')
        ->selectExpression('sum(amount)', 'revenue')
        ->selectExpression('histogram(tags)', 'tags')
        ->from('events')
        ->groupBy('week')
        ->orderBy('week')
        ->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->week)->toBe('2025-12-29 00:00:00')
        ->and($result->first()->revenue)->toBe(42.21)
        ->and($result->first()->tags)->toBe(['Hello, DuckDB' => 1]);

    Event::setConnectionResolver(new Resolver($connection));
    $event = new Event();
    $event->category = 'conference';
    $event->amount = 42.21;
    $event->tags = ['Hello', 'DuckDB'];
    $event->save();

    expect($event->id)->not->toBeNull()
        ->and($event->category)->toBe('conference')
        ->and($event->amount)->toBe(42.21);

    $events = Event::where('created_at', '>=', '2026-01-01')->get();
    expect($events)->toHaveCount(2);

    $connection->getSchemaBuilder()->dropIfExists('events');
    $connection->getSchemaBuilder()->dropSequence('seq_events_id');
    $sequences = $connection->getPdo()->query("select * from duckdb_sequences()")->fetchAll(PDO::FETCH_ASSOC);
    expect($sequences)->toBeEmpty();

    $connection->getSchemaBuilder()->createSequence('seq_events_id', 1, 1);
    $sequences = $connection->getPdo()->query("select * from duckdb_sequences()")->fetchAll(PDO::FETCH_ASSOC);
    expect($sequences)->not->toBeEmpty();
});

it('verifies examples from readme, csv files', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $list = [
        ['aaa', 'bbb', 'ccc'],
        ['123', '456', '789'],
        ['ddd', 'eee', 'fff'],
    ];
    $tmpFile = sys_get_temp_dir() . '/test.csv';

    $fp = fopen($tmpFile, 'w');
    foreach ($list as $fields) {
        fputcsv($fp, $fields, ',', '"', "");
    }
    fclose($fp);

    $result = $connection->query()
        ->select('aaa')
        ->from($tmpFile)
        ->get()
        ->toArray();
    expect((array) $result[0])->toBe(['aaa' => '123']);
    expect((array) $result[1])->toBe(['aaa' => 'ddd']);

    TestCsv::setConnectionResolver(new Resolver($connection));
    $testCsv = new TestCsv();
    $testCsv->setTable($tmpFile);
    $result = $testCsv->newQuery()->select('aaa')->get()->toArray();
    expect($result[0])->toBe(['aaa' => '123']);
    expect($result[1])->toBe(['aaa' => 'ddd']);
});

it('verifies examples from readme, json files', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $tmpFile = sys_get_temp_dir() . '/logs.json';
    $tmpFileParquet = sys_get_temp_dir() . '/logs_json.parquet';

    file_put_contents($tmpFile, json_encode(['log' => 'log text']) . PHP_EOL, FILE_APPEND);
    file_put_contents($tmpFile, json_encode(['log' => 'log text 2']) . PHP_EOL, FILE_APPEND);

    $result = $connection->query()
        ->select('log')
        ->from($tmpFile)
        ->get()
        ->toArray();
    expect((array) $result[0])->toBe(['log' => 'log text']);
    expect((array) $result[1])->toBe(['log' => 'log text 2']);

    $connection->statement("COPY (SELECT * FROM '{$tmpFile}') TO '{$tmpFileParquet}' (COMPRESSION zstd)");

    $result = $connection->query()
        ->select('log')
        ->from($tmpFileParquet)
        ->get()
        ->toArray();

    expect((array) $result[0])->toBe(['log' => 'log text']);
    expect((array) $result[1])->toBe(['log' => 'log text 2']);

    LogsJson::setConnectionResolver(new Resolver($connection));
    $logsJson = new LogsJson();
    $logsJson->setTable($tmpFile);
    $result = $logsJson->newQuery()->select('log')->get()->toArray();
    expect($result[0])->toBe(['log' => 'log text']);
    expect($result[1])->toBe(['log' => 'log text 2']);
});

it('verifies special schema types with query builder', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('events', function (Blueprint $table) {
        $table->id();
        $table->rawColumn('categories', 'varchar[]');
        $table->rawColumn('numbers', 'integer[]');
        $table->rawColumn('person', 'STRUCT(v VARCHAR, i INTEGER, va VARCHAR[], d DECIMAL)');
        $table->rawColumn('persons', 'STRUCT(v VARCHAR, i INTEGER, va VARCHAR[], d DECIMAL)[]');
    });

    $person = new Person();
    $person->v = 'foo';
    $person->i = 42;
    $person->va = ['foo', 'bar'];
    $person->d = 42.21;

    $connection->table('events')->insert([[
        'categories' => ['foo', 'bar'],
        'numbers' => [42, 21],
        'person' => $person,
        'persons' => [$person],
    ]]);

    $result = $connection->query()
        ->from('events')
        ->get()
        ->toArray();

    expect((array) $result[0])->toBe(
        ['id' => 1, 'categories' => ['foo', 'bar'], 'numbers' => [42, 21],
            'person' => ['v' => 'foo', 'i' => 42, 'va' => ['foo', 'bar'], 'd' => 42.21],
            'persons' => [['v' => 'foo', 'i' => 42, 'va' => ['foo', 'bar'], 'd' => 42.21]],
        ]
    );
});

it('verifies special schema types with eloquent', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));
    Event::setConnectionResolver(new Resolver($connection));

    $connection->getSchemaBuilder()->create('events', function (Blueprint $table) {
        $table->id();
        $table->rawColumn('categories', 'varchar[]');
        $table->rawColumn('numbers', 'integer[]');
        $table->rawColumn('number', 'integer');
        $table->rawColumn('person', 'STRUCT(v VARCHAR, i INTEGER, va VARCHAR[], d DECIMAL)');
        $table->rawColumn('persons', 'STRUCT(v VARCHAR, i INTEGER, va VARCHAR[], d DECIMAL)[]');
        $table->timestamps();
    });

    $person = new Person();
    $person->v = 'foo';
    $person->i = 42;
    $person->va = ['foo', 'bar'];
    $person->d = 42.21;

    $event = new Event();
    $event->number = 41;
    $event->categories = ['foo', 'bar'];
    $event->numbers = [42, 21];
    $event->person = $person;
    $event->persons = [$person];
    $event->save();

    $event = Event::first();

    $person = $event->person;
    expect($person)->toBeInstanceOf(Person::class)
        ->and($event->person)->toBe($person)
        ->and($person->toArray())->toBe(['v' => 'foo', 'i' => 42, 'va' => ['foo', 'bar'], 'd' => 42.21]);

    $persons = $event->persons;
    expect($persons[0])->toBeInstanceOf(Person::class)
        ->and($event->persons)->toBe($persons)
        ->and($persons[0]->toArray())->toBe(['v' => 'foo', 'i' => 42, 'va' => ['foo', 'bar'], 'd' => 42.21]);
});

it('verifies parquet read and write', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $tmpFileParquet = sys_get_temp_dir() . '/table1.parquet';

    $connection->getSchemaBuilder()->create('table1', function (Blueprint $table) {
        $table->id();
        $table->string('text');
        $table->json('data');
    });
    $connection->table('table1')->insert([[
        'text' => 'Hello DuckDB 🦆',
        'data' => ['foo' => 'bar', 'baz' => 42],
    ]]);
    $connection->statement("COPY (SELECT * FROM table1) TO '{$tmpFileParquet}' (COMPRESSION zstd)");

    $result = $connection->query()
        ->from($tmpFileParquet)
        ->get()
        ->toArray();
    expect((array) $result[0])->toBe(['id' => 1, 'text' => 'Hello DuckDB 🦆', 'data' => ['foo' => 'bar', 'baz' => 42]]);
});

it('verifies last insert id', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));

    $connection->getSchemaBuilder()->create('table1', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    expect($connection->table('table1')->insertGetId(['name' => 'Foo']))->toBe(1);
    expect($connection->table('table1')->insertGetId(['name' => 'Bar']))->toBe(2);
});
