<?php

namespace DuckDb\Schema;

use Closure;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\File;

class DuckDBBuilder extends Builder
{
    /** {@inheritdoc} */
    public function createDatabase($name)
    {
        $pdo = new \PDO("duckdb:{$name}");
        $pdo = null;

        return File::exists($name);
    }

    /** {@inheritdoc} */
    public function dropDatabaseIfExists($name)
    {
        return ! File::exists($name) || File::delete($name);
    }

    public function dropAllTables(): void
    {
        foreach ($this->getCurrentSchemaListing() ?: [] as $schema) {
            $indexes = $this->connection->selectFromWriteConnection(
                sprintf("select index_name, schema_name from duckdb_indexes() where schema_name = %s", $this->connection->getPdo()->quote($schema))
            );

            foreach ($indexes as $index) {
                $this->connection->statement("drop index if exists " . $this->connection->getQueryGrammar()->wrap($index->schema_name . '.' . $index->index_name));
            }

            $tables = $this->connection->selectFromWriteConnection(
                $this->grammar->compileTables($schema)
            );

            foreach ($tables as $table) {
                $this->connection->statement("drop table if exists " . $this->connection->getQueryGrammar()->wrapTable($table->schema . '.' . $table->name));
            }
        }
    }

    public function dropAllViews(): void
    {
        foreach ($this->getCurrentSchemaListing() ?: [] as $schema) {
            $views = $this->connection->selectFromWriteConnection(
                $this->grammar->compileViews($schema)
            );

            foreach ($views as $view) {
                $this->connection->statement("drop view if exists " . $this->connection->getQueryGrammar()->wrapTable($view->name));
            }
        }
    }

    public function pragma(string $key, ?string $value = null): string
    {
        if (is_null($value)) {
            return $this->connection->scalar("pragma {$key}");
        }

        $this->connection->statement("set {$key} = " . $this->connection->getPdo()->quote($value));

        return '';
    }

    public function createSequence(string $name, int $start, int $increment): void
    {
        $this->connection->statement(
            "CREATE SEQUENCE IF NOT EXISTS {$name} START WITH {$start} INCREMENT BY {$increment}"
        );
    }

    public function dropSequence(string $name): void
    {
        $this->connection->statement("DROP SEQUENCE IF EXISTS {$name}");
    }

    /** {@inheritdoc} */
    protected function createBlueprint($table, ?Closure $callback = null)
    {
        return new DuckDBBlueprint($this->connection, $table, $callback);
    }

    /** {@inheritdoc} */
    public function getCurrentSchemaListing()
    {
        return array_column($this->getSchemas(), 'name');
    }
}
