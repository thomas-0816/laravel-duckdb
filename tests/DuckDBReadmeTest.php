<?php

use DuckDb\DuckDbConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

class Event extends Model
{
    protected $connection = 'duckdb';
    protected $table = 'events';
}

it('verifies examples from readme', function () {
    $connection = new DuckDbConnection(fn() => new PDO('duckdb::memory:'));
    Model::setConnectionResolver(new class($connection) implements \Illuminate\Database\ConnectionResolverInterface {
        public function __construct(private $connection) {}
        public function connection($connection = null) { return $this->connection; }
        public function getDefaultConnection() { return 'duckdb'; }
        public function setDefaultConnection($name) {}
        public function supportsExtending() { return false; }
        public function extend($name, $resolver) {}
    });

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

    $event = new Event();
    $event->category = 'conference';
    $event->amount = 42.21;
    $event->tags = ['Hello', 'DuckDB'];
    $event->save();

    expect($event->id)->not->toBeNull()
        ->and($event->category)->toBe('conference')
        ->and($event->amount)->toBe(42.21);

    $events = Event::where('created_at', '>=', \Carbon\Carbon::now()->subWeek())->get();
    expect($events)->toHaveCount(1);

    $connection->getSchemaBuilder()->dropIfExists('events');
    $connection->getSchemaBuilder()->dropSequence('seq_events_id');
    Model::unsetConnectionResolver();
});
