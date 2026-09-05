# Laravel Eloquent driver for DuckDB

A [DuckDB](https://duckdb.org) database driver for Laravel Eloquent powered by the DuckDB PDO Driver.

Integrates DuckDB's analytical database engine into Laravel's Eloquent ORM and Schema Builder, enabling fast analytical queries directly from your Laravel application.

<img width="500" height="273" alt="logo" src="logo.jpg?3" />

## Requirements

- PHP 8.2+
- Laravel 12+
- pdo_duckdb PHP extension

## Install and setup

Install and setup [pdo_duckdb](https://github.com/thomas-0816/pdo-duckdb-php) database driver with [PIE](https://github.com/php/pie):

```bash
pie install thomas-0816/pdo-duckdb-php
```

Install and setup Laravel Eloquent driver for DuckDB:

```bash
composer require thomas-0816/laravel-duckdb

php artisan package:discover
```

`pdo_duckdb` is a native DuckDB database driver for the PHP Data Objects (PDO) interface.\
As a native PHP extension, it is implemented in C/C++ and does not require PHP FFI or preloading.\
It is also thread safe and fully tested with FrankenPHP (PHP-ZTS) and Swoole.\
The release packages contain pre-compiled binaries for all supported platforms and DuckDB is directly included.\
DuckDB extensions work the same way as they do in DuckDB CLI.

## Configuration

Add a `duckdb` connection to your `config/database.php`:

```php
'connections' => [
    'duckdb' => [
        'driver'   => 'duckdb',
        'database' => env('DB_DATABASE', database_path('analytics.duckdb')),
        'options' => [
            PDO::DUCKDB_ATTR_CONFIG => [
                'TimeZone' => 'Europe/Berlin',
                // 'threads' => 4, # max. number of threads
                // 'memory_limit' => '4GB', # max. memory usage
                // 'access_mode' => 'read_only', # open database file read-only
            ],
        ],
    ],
],
```

## In-Memory Database

For testing or reading external files, use the special in-memory database in `config/database.php`:

```php
'connections' => [
    'duckdb' => [
        'driver'   => 'duckdb',
        'database' => ':memory:',
        'options' => [
            PDO::DUCKDB_ATTR_CONFIG => [
                'TimeZone' => 'Europe/Berlin',
                // 'threads' => 4, # max. number of threads
                // 'memory_limit' => '4GB', # max. memory usage
            ],
        ],
    ],
],
```

## Schema Builder

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// up
Schema::connection('duckdb')->create('events', function (Blueprint $table) {
    $table->id(); // creates sequence "seq_events_id" as auto-increment
    $table->string('category');
    $table->decimal('amount', 12, 2);
    $table->json('tags')->nullable();
    $table->timestamps();
});

// php artisan migrate --pretend
// php artisan migrate

// down
// Schema::connection('duckdb')->createSequence('seq_events_id', 1, 1);
Schema::connection('duckdb')->dropSequence('seq_events_id');
Schema::connection('duckdb')->dropIfExists('events');
```

## Query Builder Insert

```php
use Illuminate\Support\Facades\DB;

dump(DB::connection('duckdb')->table('events')->insertGetId([[
    'category' => 'conference',
    'amount' => 42.21,
    'tags' => ['Hello', 'DuckDB'],
    'created_at' => now(),
    'updated_at' => now(),
]]));

# 1
```

## Query Builder Select

```php
use Illuminate\Support\Facades\DB;

$result = DB::connection('duckdb')->query()
    ->selectExpression("date_trunc('week', created_at)", 'week')
    ->selectExpression('sum(amount)', 'revenue')
    ->selectExpression('histogram(tags)', 'tags')
    ->from('events')
    ->where('amount', '>', 100)
    ->groupBy('week')
    ->orderBy('week')
    ->get();

dump($result->toArray());
```

## Eloquent Models

Models can be directly used by specifying the connection "duckdb":

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $connection = 'duckdb';
    protected $table = 'events';
}
```

```php
use App\Models\Event;

$event = new Event();
$event->category = 'conference';
$event->amount = 42.21;
$event->tags = ['Hello', 'DuckDB'];
$event->save();
dump($event->toArray());

$events = Event::where('created_at', '>=', now()->subWeek())->get();
dump($events->toArray());
```

## Read CSV files with SQL Query Builder

```php
use Illuminate\Support\Facades\DB;

$list = [
    ['aaa', 'bbb', 'ccc'],
    ['123', '456', '789'],
    ['ddd', 'eee', 'fff'],
];
$fp = fopen('/tmp/test.csv', 'w');
foreach ($list as $fields) {
    fputcsv($fp, $fields, ',', '"', "");
}
fclose($fp);

$result = DB::connection('duckdb')->query()
    ->select('aaa')
    ->from('/tmp/test.csv') // or multiple files using '/tmp/*.csv'
    ->get();
dump($result->toArray());

# Array
#     [0] => stdClass Object
#         [aaa] => 123
#     [1] => stdClass Object
#         [aaa] => aaa
```

## Read CSV files with Eloquent Models

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TestCsv extends Model
{
    protected $connection = 'duckdb';
    protected $table = '/tmp/test.csv'; // or multiple files using '/tmp/*.csv'
}
```

```php
use App\Models\TestCsv;

$list = [
    ['aaa', 'bbb', 'ccc'],
    ['123', '456', '789'],
];
$fp = fopen('/tmp/test.csv', 'w');
foreach ($list as $fields) {
    fputcsv($fp, $fields, ',', '"', "");
}
fclose($fp);

$rows = TestCsv::select('aaa')->get();
dump($rows->toArray());

# Array
#     [0] => Array
#         [aaa] => 123
#     [1] => Array
#         [aaa] => ddd
```

## CSV data import with SQL Query Builder

```php
use Illuminate\Support\Facades\DB;

$list = [
    ['aaa', 'bbb'],
    ['123', '456'],
    ['aaa', 'bbb']
];
$fp = fopen('/tmp/test.csv', 'w');
foreach ($list as $fields) {
    fputcsv($fp, $fields, ',', '"', "");
}
fclose($fp);

$db = DB::connection('duckdb');
$db->statement("CREATE TABLE test_csv AS SELECT * FROM '/tmp/test.csv'"); // schema + data import
$db->statement("INSERT INTO test_csv SELECT * FROM '/tmp/test.csv'"); // only import data
dump($db->select('SHOW test_csv'));

# Array
#     [0] => stdClass Object
#         [column_name] => aaa
#         [column_type] => VARCHAR
#         [null] => YES
#     [1] => stdClass Object
#         [column_name] => bbb
#         [column_type] => VARCHAR
#         [null] => YES
```

## Read JSON files with SQL Query Builder

```php
use Illuminate\Support\Facades\DB;

file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text']) . PHP_EOL, FILE_APPEND);
file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text 2']) . PHP_EOL, FILE_APPEND);

$result = DB::connection('duckdb')->query()
    ->select('log')
    ->from('/tmp/logs.json') // or multiple files using '/tmp/*.json'
    ->get();
dump($result->toArray());

# Array
#     [0] => stdClass Object
#         [log] => log text
#     [1] => stdClass Object
#         [log] => log text 2

// Convert JSON file to Parquet file
DB::connection('duckdb')->statement("COPY (SELECT * FROM '/tmp/logs.json') TO '/tmp/logs.parquet'");

$result = DB::connection('duckdb')->query()
    ->select('log')
    ->from('/tmp/logs.parquet')
    ->get();
dump($result->toArray());

# Array
#     [0] => stdClass Object
#         [log] => log text
#     [1] => stdClass Object
#         [log] => log text 2
```

## Read JSON files with Eloquent Models

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogsJson extends Model
{
    protected $connection = 'duckdb';
    protected $table = '/tmp/logs.json'; // or multiple files using '/tmp/*.json'
}

file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text']) . PHP_EOL, FILE_APPEND);
file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text 2']) . PHP_EOL, FILE_APPEND);

$rows = LogsJson::select('log')->get();
dump($rows->toArray());

# Array
#     [0] => Array
#         [log] => log text
#     [1] => Array
#         [log] => log text 2
```

## Read Parquet files with Eloquent Models

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LogsParquet extends Model
{
    protected $connection = 'duckdb';
    protected $table = '/tmp/logs.parquet'; // or multiple files using '/tmp/*.parquet'
}

file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text']) . PHP_EOL, FILE_APPEND);
file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text 2']) . PHP_EOL, FILE_APPEND);

// Convert JSON file to Parquet file
DB::connection('duckdb')->statement("COPY (SELECT * FROM '/tmp/logs.json') TO '/tmp/logs.parquet'");

$rows = LogsParquet::select('log')->get();
dump($rows->toArray());

# Array
#     [0] => Array
#         [log] => log text
#     [1] => Array
#         [log] => log text 2
```

__Apache Parquet__: very fast and efficient column based storage file format containing one table of data.\
Each column is split into several column groups. Depending on the query, the file can be read partially by certain columns groups.\
Different compression or dictionary algorithms can be applied to each column. Also supports encryption.

Note: You can read and save Parquet files on local file systems or directly on [S3 object storage](https://duckdb.org/docs/lts/core_extensions/httpfs/s3api).

## Read and write Parquet files with SQL Query Builder

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Schema::connection('duckdb')->create('table1', function (Blueprint $table) {
    $table->id();
    $table->string('text');
    $table->json('data');
});
DB::connection('duckdb')->table('table1')->insert([[
    'text' => 'Hello DuckDB 🦆',
    'data' => ['foo' => 'bar', 'baz' => 42],
]]);
DB::connection('duckdb')->statement("COPY (SELECT * FROM table1) TO '/tmp/table1.parquet'");

$result = DB::connection('duckdb')->query()
    ->from('/tmp/table1.parquet')
    ->get();
dump($result->toArray());

# Array
#     [0] => stdClass Object
#         [id] => 1
#         [text] => Hello DuckDB 🦆
#         [data] => Array
#             [foo] => bar
#             [baz] => 42
```

## Read and write Excel files

```php
DB::connection('duckdb')->unprepared('INSTALL excel; LOAD excel');

DB::connection('duckdb')->unprepared('CREATE TABLE table1 (text VARCHAR, amount DECIMAL(10, 2))');
DB::connection('duckdb')->table('table1')->insert([['text' => 'Hello Excel 🦆', 'amount' => 42.21]]);

DB::connection('duckdb')->unprepared("COPY (SELECT * FROM table1) TO '/tmp/table1.xlsx'");

DB::connection('duckdb')->query()
    ->from('/tmp/table1.xlsx')
    ->get()
dump($result->toArray());

# Array
#     [A1] => Hello DuckDB 🦆
#     [B1] => 42.21
```

## Read public data using HTTPs, JSON, CSV and Parquet

Query weather data:

```php
use Illuminate\Support\Facades\DB;

$result = DB::connection('duckdb')->query()
    ->select(['id', 'name.en'])
    ->fromRaw("read_json('https://bulk.meteostat.net/v2/stations/lite.json.gz')")
    ->whereLike('name.en', '%Berlin%')
    ->limit(2);
echo json_encode($result->get()->toArray()), PHP_EOL;

# [{"id":"10381","en":"Berlin \/ Dahlem"},{"id":"10382","en":"Berlin \/ Tegel"}]

$result = DB::connection('duckdb')->query()
    ->select(['hour', 'temp'])
    ->fromRaw("read_csv('https://data.meteostat.net/hourly/2026/10381.csv.gz')")
    ->where('year', 2026)->where('month', 7)->where('day', 25)->where('hour', '>', 9)
    ->limit(3);
echo json_encode($result->get()->toArray()), PHP_EOL;

# [{"hour":10,"temp":24.1},{"hour":11,"temp":25.5},{"hour":12,"temp":26.4}]
```

Download and query historical data from Deutsche Bahn:

```php
# wget https://huggingface.co/datasets/piebro/deutsche-bahn-data/resolve/main/monthly_processed_data/data-2026-07.parquet

$rows = DB::connection('duckdb')->select("
    SELECT train_type, train_number, round(avg(delay_in_min)) as delay_avg, count(*) as count
    FROM 'data-2026-07.parquet' WHERE train_type = 'ICE'
    GROUP BY train_number, train_type
    ORDER BY delay_avg DESC
    LIMIT 10
");
dump(array_map('json_encode', $rows));

$rows = DB::connection('duckdb')->select("
    SELECT train_number, station_name, delay_in_min, hour(time) as hour, departure_is_canceled
    FROM 'data-2026-07.parquet'
    WHERE train_number = 647 AND time::date = '2026-07-11'
");
dump(array_map('json_encode', $rows));

# array
#     {"train_type":"ICE","train_number":"647","delay_avg":70,"count":35}
#     {"train_type":"ICE","train_number":"1541","delay_avg":66,"count":198}
#     {"train_type":"ICE","train_number":"79152","delay_avg":44,"count":2}
#     {"train_type":"ICE","train_number":"2587","delay_avg":44,"count":72}
#     {"train_type":"ICE","train_number":"2214","delay_avg":43,"count":159}
#     {"train_type":"ICE","train_number":"2311","delay_avg":42,"count":289}
#     {"train_type":"ICE","train_number":"953","delay_avg":42,"count":79}
#     {"train_type":"ICE","train_number":"859","delay_avg":41,"count":80}
#     {"train_type":"ICE","train_number":"526","delay_avg":41,"count":337}
#     {"train_type":"ICE","train_number":"640","delay_avg":39,"count":372}
# array
#     {"train_number":"647","station_name":"Dortmund Hbf","delay_in_min":82,"hour":0,"departure_is_canceled":false}
#     {"train_number":"647","station_name":"Hamm (Westf) Hbf","delay_in_min":120,"hour":1,"departure_is_canceled":false}
#     {"train_number":"647","station_name":"Bielefeld Hbf","delay_in_min":120,"hour":1,"departure_is_canceled":false}
#     {"train_number":"647","station_name":"Minden (Westf)","delay_in_min":123,"hour":2,"departure_is_canceled":false}
#     {"train_number":"647","station_name":"Hannover Hbf","delay_in_min":138,"hour":2,"departure_is_canceled":false}
#     {"train_number":"647","station_name":"Wolfsburg Hbf","delay_in_min":135,"hour":3,"departure_is_canceled":false}
#     {"train_number":"647","station_name":"Berlin Hauptbahnhof","delay_in_min":121,"hour":4,"departure_is_canceled":true}
#     {"train_number":"647","station_name":"Berlin S\u00fcdkreuz","delay_in_min":120,"hour":4,"departure_is_canceled":false}
#     {"train_number":"647","station_name":"Berlin-Spandau","delay_in_min":146,"hour":4,"departure_is_canceled":false}
```

## Copy data from MariaDB to a Parquet file

Start a MariaDB container, create and fill "orders" table:

```bash
docker run --rm -it -p 3306:3306 -e MARIADB_ROOT_PASSWORD=secret -e MARIADB_DATABASE=testdb mariadb:12
mysql -h 127.0.0.1 -u root -psecret testdb -e "
    CREATE TABLE orders (id integer primary key, customer integer, amount decimal(12, 2), origin varchar(255));
    INSERT INTO orders VALUES (1, 42, 123.42, 'shop');
    INSERT INTO orders VALUES (2, 21, 12.21, 'offline');
"
```

Use DuckDB [MySQL extension](https://duckdb.org/docs/lts/core_extensions/mysql) to copy "orders" table from MariaDB to a Parquet file:

```php
use Illuminate\Support\Facades\DB;

DB::connection('duckdb')->unprepared("
    INSTALL mysql;
    ATTACH 'host=127.0.0.1 port=3306 user=root password=secret database=testdb' AS testdb (TYPE mysql);
    COPY (select * from testdb.orders) TO '/tmp/orders.parquet' (FORMAT parquet);
");
$rows = DB::connection('duckdb')->query()
    ->from('/tmp/orders.parquet')
    ->get();
dump($rows->toArray());

# Array
#     [0] => stdClass Object
#         [id] => 1
#         [customerId] => 42
#         [amount] => 123.42
#         [origin] => shop
#     [1] => stdClass Object
#         [id] => 2
#         [customerId] => 21
#         [amount] => 12.21
#         [origin] => offline
```

## Copy data from PostgreSQL to a parquet file

Start PostgreSQL container, create and fill "orders" table:

```bash
docker run --rm -it -p 5432:5432 -e POSTGRES_PASSWORD=secret postgres:18
PGPASSWORD=secret psql -h 127.0.0.1 -U postgres -c "
    CREATE TABLE orders (id integer primary key, customer integer, amount decimal(12, 2), origin varchar(255));
    INSERT INTO orders VALUES (1, 42, 123.42, 'shop');
    INSERT INTO orders VALUES (2, 21, 12.21, 'offline');
"
```

Use DuckDB [PostgreSQL extension](https://duckdb.org/docs/lts/core_extensions/postgres) to copy "orders" table from PostgreSQL to a Parquet file:

```php
use Illuminate\Support\Facades\DB;

DB::connection('duckdb')->unprepared("
    INSTALL postgres;
    ATTACH 'host=127.0.0.1 port=5432 user=postgres password=secret' AS testdb (TYPE postgres);
    COPY (select * from testdb.orders) TO '/tmp/orders.parquet' (FORMAT parquet);
");
$rows = DB::connection('duckdb')->query()
    ->from('/tmp/orders.parquet')
    ->get();
dump($rows->toArray());

# Array
#     [0] => Array
#         [id] => 1
#         [customerId] => 42
#         [amount] => 123.42
#         [origin] => shop
#     [1] => Array
#         [id] => 2
#         [customerId] => 21
#         [amount] => 12.21
#         [origin] => offline
```

## Schema and Query Builder for special types

Special types can be defined by using rawColumn():

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Schema::connection('duckdb')->create('events', function (Blueprint $table) {
    $table->id();
    $table->rawColumn('numbers', 'integer[]');
    $table->rawColumn('categories', 'varchar[]');
    $table->rawColumn('person', 'STRUCT(v VARCHAR, va VARCHAR[], d DECIMAL)');
});

class Person {
    public string $v;
    public array $va;
    public float $d;
}

$person = new Person();
$person->v = 'foo';
$person->va = ['bar', 'baz'];
$person->d = 12.34;

DB::connection('duckdb')->table('events')->insert([[
    'numbers' => [21, 42],
    'categories' => ['cat1', 'cat2'],
    'person' => $person,
]]);
$events = DB::connection('duckdb')->query()
    ->from('events')
    ->get();
dump($events->toArray());

# Array
#     [0] => stdClass Object
#         [id] => 1
#         [numbers] => Array
#             [0] => 21
#             [1] => 42
#         [categories] => Array
#             [0] => cat1
#             [1] => cat2
#         [person] => Array
#             [v] => foo
#             [va] => Array
#                 [0] => bar
#                 [1] => baz
#             [d] => 12.34
```

## Eloquent Models for special types

Special types can be defined by using rawColumn():

```php
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::connection('duckdb')->create('events', function (Blueprint $table) {
    $table->id();
    $table->rawColumn('numbers', 'integer[]');
    $table->rawColumn('categories', 'varchar[]');
    $table->rawColumn('person', 'STRUCT(v VARCHAR, va VARCHAR[], d DECIMAL)');
    $table->rawColumn('persons', 'STRUCT(v VARCHAR, va VARCHAR[], d DECIMAL)[]');
    $table->timestamps();
});

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
        return Attribute::get(fn ($person) => new Person($person));
    }
    protected function persons(): Attribute
    {
        return Attribute::get(fn ($persons) => collect($persons)->map(fn ($values) => new Person($values)));
    }
}

$person = new Person();
$person->v = 'foo';
$person->va = ['bar', 'baz'];
$person->d = 12.34;

$event = new Event();
$event->numbers = [21, 42];
$event->categories = ['cat1', 'cat2'];
$event->person = $person;
$event->persons = [$person];
$event->save();

dump(Event::first()->toArray());

# Array
#     [id] => 1
#     [numbers] => Array
#         [0] => 42
#         [1] => 21
#     [categories] => Array
#         [0] => cat1
#         [1] => cat2
#     [person] => Person Object
#         [v] => foo
#         [va] => Array
#             [0] => bar
#             [1] => baz
#         [d] => 42.21
#     [persons] => Array
#         [0] => Array
#             [v] => foo
#             [va] => Array
#                 [0] => bar
#                 [1] => baz
#             [d] => 42.21
```

## Vector Similarity Search (HNSW)

```php
DB::connection('duckdb')->unprepared('INSTALL vss; LOAD vss');

Schema::connection('duckdb')->create('events', function (Blueprint $table) {
    $table->id();
    $table->vector('embeddings', 3);
    $table->vectorIndex('embeddings');
});
DB::connection('duckdb')->table('events')->insert([
    ['id' => 1, 'embeddings' => [1, 2, 3]],
    ['id' => 2, 'embeddings' => [4, 5, 6]],
]);

$results = DB::connection('duckdb')->table('events')
    ->select('id')
    ->selectVectorDistance('embeddings', [1.1, 2.1, 3.1], 'distance')
    ->where('distance', '<=', 0.01)
    ->orderBy('distance')
    ->get();
dump($results->toArray());

# Array
#     [0] => stdClass Object
#         [id] => 1
#         [distance] => 0.0001407862\n
```

## Views

```php
use Illuminate\Support\Facades\Schema;

// create or drop views
Schema::connection('duckdb')->createView('view1', 'SELECT * FROM events');
Schema::connection('duckdb')->dropView('view1');
```

## Transactions

```php
use Illuminate\Support\Facades\DB;

$list = [
    ['aaa', 'bbb', 'ccc'],
    ['123', '456', '789'],
    ['ddd', 'eee', 'fff'],
];
$fp = fopen('/tmp/test.csv', 'w');
foreach ($list as $fields) {
    fputcsv($fp, $fields, ',', '"', "");
}
fclose($fp);

DB::connection('duckdb')->transaction(function ($db) {
    $db->statement("CREATE TABLE test_csv AS SELECT * FROM '/tmp/test.csv'");
    $db->statement("INSERT INTO test_csv SELECT * FROM '/tmp/test.csv'");
});
```

## Community extensions

open_prompt integrates LLMs into your SQL queries:

```php
# start llama.cpp at 127.0.0.1:8080
# ./llama-server -hf JetBrains/Mellum2-12B-A2.5B-Thinking-GGUF-Q4_K_M --parallel 1 --ctx-size 16384 --temp 0.6 --top-k 20 --reasoning off

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

DB::connection('duckdb')->unprepared("
    INSTALL open_prompt FROM community;
    LOAD open_prompt;
    SET VARIABLE openprompt_api_url = 'http://127.0.0.1:8080/v1/chat/completions';
");
Schema::connection('duckdb')->create('customers', function (Blueprint $table) {
    $table->id();
    $table->string('first_name');
    $table->string('last_name');
    $table->date('birth_date');
});
$result = DB::connection('duckdb')->query()
    ->selectExpression("open_prompt('write duckdb sql, no markdown, find customers older than 30, schema: ' || group_concat(sql))", 'llm')
    ->fromRaw('duckdb_tables()')
    ->first();

# SELECT * FROM customers WHERE birth_date < now() - INTERVAL '30 years';
```

More extensions: [List of Core Extensions](https://duckdb.org/docs/lts/core_extensions/overview), [List of Community Extensions](https://duckdb.org/community_extensions/list_of_extensions)

__Note__: Community extensions are third party projects, NOT maintained or reviewed by the DuckDB team.

## Schema Dump

The package supports `schema:dump` Artisan command using DuckDB's `EXPORT DATABASE` SQL statement via PDO:

```bash
php artisan schema:dump --database=duckdb # creates ./database/schema/duckdb-schema.sql
```

## Query Debugging

You can add this line at the beginning of your script for local query debugging:

```bash
\Illuminate\Support\Facades\DB::listen(fn ($query) => dump($query));
```

## Performance

DuckDB is extremely fast when it comes to analytic queries.\
Here is an example with 10M rows, performing in __170ms on 4 threads with 128M ram__:

```sql
.timer on
/* generate 10M rows with random data */
COPY (
    SELECT i,
        (random()*1_000)::decimal(11,2) as d1,
        (random()*1_000)::int as i1,
        to_hex((random()*100000)::int) as h1,
        to_timestamp((i+1_0000_000) * random() * 100)::timestamp as created
    FROM generate_series(10_000_000) s(i)
) TO '/tmp/test.parquet' (format parquet, compression zstd);
/* Run Time (s): real 4.158 user 4.002094 sys 0.154674 */

SET threads = 4;
SET memory_limit = '128M';
SELECT count(*), sum(i), avg(d1), stddev(i1), avg(length(h1)), avg(date_diff('day', current_date, created))
FROM '/tmp/test.parquet';
/* Run Time (s): real 0.170 user 0.616465 sys 0.051658 */
```

## Security

Use SQL `SET variable = value;` or put the settings inside the PDO::DUCKDB_ATTR_CONFIG connection [options array](#Configuration):

```sql
# Disable extension loading
SET autoload_known_extensions = false;
SET autoinstall_known_extensions = false;
SET allow_community_extensions = false;

# Disable external file access, directory white listing
SET allowed_directories = ['/tmp'];
SET enable_external_access = false;

# Resource limits
SET threads = 4;
SET memory_limit = '4GB';
SET max_temp_directory_size = '4GB';

# Lock configuration
SET lock_configuration = true;
```

A complete list is available in the DuckDB documentation: [Securing DuckDB](https://duckdb.org/docs/lts/operations_manual/securing_duckdb/overview).

## Laravel Octane and Swoole

Run queries concurrently:

```php
$result = Octane::concurrently([
    fn () => DB::connection('duckdb')->selectOne("select sleep_ms(1000)"),
    fn () => DB::connection('duckdb')->selectOne("select sleep_ms(1000)"),
    fn () => DB::connection('duckdb')->selectOne("select sleep_ms(1000)"),
]); // takes 1s
```

Run queries every x seconds:

```php
class AppServiceProvider extends ServiceProvider {
    public function boot(): void {
        Octane::tick('my-ticker', function () {
            $result = DB::connection('duckdb')->selectOne("select sleep_ms(1000)");
            dump($result);
        })->seconds(10);
```

DuckDB only allows a [single writer](https://duckdb.org/docs/current/connect/concurrency). So you can use multiple in-memory databases or\
open an existing database multiple times with read-only access mode in `config/database.php`:

```php
'connections' => [
    'duckdb' => [
        'driver' => 'duckdb',
        'database' => ':memory:',
# or
'connections' => [
    'duckdb' => [
        'driver'   => 'duckdb',
        'database' => env('DB_DATABASE', database_path('analytics.duckdb')),
        'options' => [PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only'],
```

Disconnect from databases after each request in `config/octane.php` to avoid side effects between requests:

```php
'listeners' => [
    OperationTerminated::class => [
        DisconnectFromDatabases::class,
```

## Why DuckDB?

https://duckdb.org/why_duckdb

Like SQLite, DuckDB embeds directly into host applications as a library, eliminating the need for network serialization and separate server setups.
It uses columnar storage and vectorized processing, running analytics 10–100x faster than traditional row-oriented databases.
DuckDB spills data to disk if needed, allowing to process datasets much larger than available system RAM.
It includes an advanced query optimizer that handles joins, subqueries, expressions and filters.\
DuckDB can directly query flat files (JSON, CSV, and Parquet) directly via SQL without needing to import the data first.
Flat files can be read directly from disk, network attached storage or S3 comatible cloud storage.\
Data is processed in cache-friendly batches on a multi-core architecture, allowing modern hardware to operate on arrays of data simultaneously.
For analytical queries that only require a few metrics, DuckDB reads only the relevant columns from disk/memory, saving I/O and CPU cycles.
This brings data warehouse-level performance to any laptop or server.

## FAQ

> Do I need an extra server for DuckDB?

No. DuckDB runs completely embedded inside of PHP as an extension, just like SQLite.

> How much RAM and CPU do I need for DuckDB?

DuckDB normally runs good with 1-4 GB RAM and 2-4 CPU cores.

> How good is the compression with Parquet and zstd?

For logs you normally achieve compression rates of 50-100x.

> Who is maintaining DuckDB?

The DuckDB project is owned and maintained by the [DuckDB Foundation](https://duckdb.foundation), a non-profit organization from Amsterdam.

> Can I get support for DuckDB?

Yes. Support is available on GitHub, see the [community support](https://duckdb.org/community_support) page for details.

> Is the Laravel Eloquent driver for DuckDB developed by the DuckDB project?

No. This is a third-party open-source community project.

> Is DuckDB fully open-source?

Yes. DuckDB and all components are fully open-source under the MIT license.

## Development

```bash
# testing
composer test
composer test_fix
./vendor/bin/pest --coverage
```

## AI Disclosure

The code is written by AI, reviewed and tested without AI.

## License

MIT License
