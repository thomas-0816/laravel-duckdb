# DuckDB driver for Laravel

A [DuckDB](https://duckdb.org) database driver for [Laravel](https://laravel.com) powered by the DuckDB PDO Driver.

Integrates DuckDB's analytical database engine into Laravel's Eloquent ORM and Schema Builder, enabling fast analytical queries directly from your Laravel application.

<img width="500" height="273" alt="logo" src="logo.jpg?3" />

## Requirements

- PHP 8.2+
- Laravel 12+
- pdo_duckdb PHP extension

## Install and setup DuckDB driver for Laravel

Install and setup [pdo_duckdb](https://github.com/thomas-0816/pdo-duckdb-php) database driver with [PIE](https://github.com/php/pie):

```bash
pie install thomas-0816/pdo-duckdb-php
```

Install and setup DuckDB driver for Laravel:

```bash
composer require thomas-0816/laravel-pdo-duckdb

php artisan package:discover
```

`pdo_duckdb` is a native DuckDB database driver for the PHP Data Objects (PDO) interface.\
As a native PHP extension, it is implemented in C/C++ and does not require PHP FFI or preloading.\
It is also thread safe and fully tested with FrankenPHP (PHP-ZTS).\
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

DB::connection('duckdb')->table('events')->insert([[
    'category' => 'conference',
    'amount' => 42.21,
    'tags' => ['Hello', 'DuckDB'],
    'created_at' => now(),
    'updated_at' => now(),
]]);
```

## Query Builder Select

```php
use Illuminate\Support\Facades\DB;

$result = DB::connection('duckdb')->query()
    ->selectExpression("date_trunc('week', created_at)", 'week')
    ->selectExpression('sum(amount)', 'revenue')
    ->selectExpression('histogram(tags)', 'tags')
    ->from('events')
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
print_r($result->toArray());

# Array
#     [0] => stdClass Object
#         [aaa] => 123
#     [1] => stdClass Object
#         [aaa] => aaa
```

## Read CSV files with Eloquent Models:

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

## Read JSON files with SQL Query Builder

```php
use Illuminate\Support\Facades\DB;

file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text']) . PHP_EOL, FILE_APPEND);
file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text 2']) . PHP_EOL, FILE_APPEND);

$result = DB::connection('duckdb')->query()
    ->select('log')
    ->from('/tmp/logs.json') // or multiple files using '/tmp/*.json'
    ->get();
print_r($result->toArray());

# Array
#     [0] => stdClass Object
#         [log] => log text
#     [1] => stdClass Object
#         [log] => log text 2

// Convert JSON file to PARQUET file
DB::connection('duckdb')->statement("COPY (SELECT * FROM '/tmp/logs.json') TO '/tmp/logs.parquet'");

$result = DB::connection('duckdb')->query()
    ->select('log')
    ->from('/tmp/logs.parquet')
    ->get();
print_r($result->toArray());

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

## Read PARQUET files with Eloquent Models

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogsParquet extends Model
{
    protected $connection = 'duckdb';
    protected $table = '/tmp/logs.parquet'; // or multiple files using '/tmp/*.parquet'
}

file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text']) . PHP_EOL, FILE_APPEND);
file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text 2']) . PHP_EOL, FILE_APPEND);

// Convert JSON file to PARQUET file
DB::connection('duckdb')->statement("COPY (SELECT * FROM '/tmp/logs.json') TO '/tmp/logs.parquet'");

$rows = LogsParquet::select('log')->get();
dump($rows->toArray());

# Array
#     [0] => Array
#         [log] => log text
#     [1] => Array
#         [log] => log text 2
```

## Read and write PARQUET files with SQL Query Builder

```php
Schema::connection('duckdb')->create('table1', function (Blueprint $table) {
    $table->id();
    $table->string('text');
    $table->json('data');
});
DB::connection('duckdb')->table('table1')->insert([[
    'text' => 'Hello DuckDB 🦆',
    'data' => ['foo' => 'bar', 'baz' => 42],
]]);
DB::connection('duckdb')->statement("COPY (SELECT * FROM table1) TO '/tmp/table1.parquet' (COMPRESSION zstd)");

$result = DB::connection('duckdb')->query()
    ->from('/tmp/table1.parquet')
    ->get();
print_r($result->toArray());

# Array
#     [0] => stdClass Object
#         [id] => 1
#         [text] => Hello DuckDB 🦆
#         [data] => Array
#             [foo] => bar
#             [baz] => 42
```

## Schema Builder for special types

Special types can be defined by using rawColumn():

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Schema::connection('duckdb')->create('employees', function (Blueprint $table) {
    $table->id();
    $table->rawColumn('categories', 'varchar[]');
    $table->rawColumn('numbers', 'integer[]');
    $table->rawColumn('person', 'STRUCT(v VARCHAR, i INTEGER, va VARCHAR[], d DECIMAL)');
});

class Person {
    public string $v;
    public int $i;
    public array $va;
    public float $d;
}

$person = new Person();
$person->v = 'foo';
$person->i = 42;
$person->va = ['foo', 'bar'];
$person->d = 42.21;

DB::connection('duckdb')->table('employees')->insert([[
    'categories' => ['foo', 'bar'],
    'numbers' => [42, 21],
    'person' => $person,
]]);
$employees = $DB::connection('duckdb')->query()
    ->from('employees')
    ->get();
print_r($employees->toArray());

# Array
#     [0] => stdClass Object
#         [id] => 1
#         [categories] => Array
#             [0] => foo
#             [1] => bar
#         [numbers] => Array
#             [0] => 42
#             [1] => 21
#         [person] => Array
#             [v] => foo
#             [i] => 42
#             [va] => Array
#                 [0] => foo
#                 [1] => bar
#             [d] => 42.21
```

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
```

A complete list is available in the DuckDB documentation: [Securing DuckDB](https://duckdb.org/docs/lts/operations_manual/securing_duckdb/overview).

## Development

```bash
# testing
composer test
composer test_fix
./vendor/bin/pest --coverage
```

## Why DuckDB?

In-Process Architecture: Like SQLite, DuckDB embeds directly into host applications, eliminating the need for a separate server setup.

Extreme Analytical Speed: It uses columnar storage and vectorized (batch) processing, running analytics 10–100x faster than traditional row-oriented databases.

"Larger-than-Memory" Processing: DuckDB gracefully spills data to disk, allowing you to process massive datasets (e.g., 50GB+) on a machine with minimal RAM (e.g., 1GB).

File-Format Agnostic: It can query flat files (JSON, CSV, and Parquet) directly via SQL without needing to import or load the data into a database first.

No Infrastructure Cost: It brings data warehouse-level performance to your local laptop or local server.

DuckDB achieves blazing-fast analytical performance through its embedded, serverless multi-core architecture combined with columnar storage and vectorized execution.
By executing queries directly within the host application, it eliminates serialization and network overhead, processing data in batches (vectors) rather
than row-by-row for unparalleled speed.

https://duckdb.org/why_duckdb

Key Performance Advantages:

Vectorized Query Execution: Unlike row-oriented engines, DuckDB processes data in cache-friendly batches (vectors). This allows modern hardware to operate on
entire arrays of data simultaneously, drastically reducing CPU cycles per query.

Columnar Storage: Data is stored by column rather than by row. For analytical queries that only require a few metrics,
DuckDB only reads the relevant columns from disk/memory, saving massive amounts of I/O.

Zero-Copy In-Process Engine: As an in-process database, DuckDB runs directly in the memory space of your application.

Advanced Query Optimizer: DuckDB features an advanced query optimizer that handles filter pushdowns, unnesting of subqueries, and dynamic runtime filters.
This ensures queries only scan necessary data and avoids full-table sorting when possible.

Direct File Querying: You can query large datasets in open formats like Parquet and CSV directly on disk or in cloud storage (like AWS S3) without needing to import or convert the data first.

## AI Disclosure

The code is written by AI, reviewed and tested without AI.

## License

MIT License
