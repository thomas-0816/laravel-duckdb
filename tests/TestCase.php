<?php

namespace DuckDb\Tests;

use DuckDb\DuckDBConnection;
use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Release the resources a test may have created.
     *
     * Every DuckDB connection spins up its own pool of worker threads (~80 on
     * a 16 core machine) and reserves a few megabytes. A connection is also
     * part of a reference cycle, because Laravel's `Connection` hands itself
     * to its query and schema grammars (`new DuckDBQueryGrammar($this)`).
     *
     * Pest keeps the test closures alive for the entire run, and PHP's cycle
     * collector does not trigger on its own here: the root buffer never grows
     * past its threshold during a test run. The cycles (and with them the
     * DuckDB databases, threads and memory) therefore pile up until the very
     * end of the process, which is what makes a full run balloon to tens of
     * thousands of threads and gigabytes of RSS.
     *
     * Forcing a collection once each test is done lets the connection (and its
     * PDO, and the DuckDB instance behind it) be freed immediately.
     */
    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        $connection = new DuckDBConnection(static fn() => new PDO('duckdb::memory:conn1')); // shared memory connection

        $tables = $connection->select('select schema_name, table_name from duckdb_tables() where internal = false');
        foreach ($tables as $table) {
            $connection->unprepared('drop table if exists ' . $table->schema_name . '.' . $table->table_name);
        }
        $views = $connection->select('select schema_name, view_name from duckdb_views() where internal = false');
        foreach ($views as $view) {
            $connection->unprepared('drop view if exists ' . $view->schema_name . '.' . $view->view_name);
        }
        $sequences = $connection->select('select schema_name, sequence_name from duckdb_sequences()');
        foreach ($sequences as $sequence) {
            $connection->unprepared('drop sequence if exists ' . $sequence->schema_name . '.' . $sequence->sequence_name);
        }
        $schemas = $connection->select('select schema_name from duckdb_schemas() where internal = false');
        foreach ($schemas as $schema) {
            $connection->unprepared('drop schema if exists ' . $schema->schema_name);
        }

        gc_collect_cycles();
    }
}
