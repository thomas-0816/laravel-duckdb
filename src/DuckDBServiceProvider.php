<?php

namespace DuckDb;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class DuckDBServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Connection::resolverFor('duckdb', function ($connection, $database, $prefix, $config) {
            return new DuckDBConnection($connection, $database, $prefix, $config);
        });
    }

    /** {@inheritdoc} */
    public function register()
    {
        $this->app->bind('db.connector.duckdb', static fn() => new DuckDBConnector());
    }
}
