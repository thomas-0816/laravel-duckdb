<?php

namespace DuckDb\Tests;

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

        gc_collect_cycles();
    }
}
