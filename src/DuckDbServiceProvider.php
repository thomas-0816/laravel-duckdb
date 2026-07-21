<?php

namespace DuckDb;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class DuckDbServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Connection::resolverFor('duckdb', function ($connection, $database, $prefix, $config) {
            return new DuckDbConnection($connection, $database, $prefix, $config);
        });
    }

    /** {@inheritdoc} */
    public function register()
    {
        //
    }
}
