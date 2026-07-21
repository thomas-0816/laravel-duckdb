<?php

namespace DuckDb\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\SchemaState;

class DuckDBSchemaState extends SchemaState
{
    /** {@inheritdoc} */
    public function dump(Connection $connection, $path)
    {
        $tempDir = sys_get_temp_dir() . '/duckdb_export_' . uniqid();

        try {
            $connection->getPdo()->exec(
                'EXPORT DATABASE ' . $connection->getPdo()->quote($tempDir)
            );

            $schemaFile = $tempDir . '/schema.sql';

            if ($this->files->exists($schemaFile)) {
                $this->files->put($path, $this->files->get($schemaFile) . PHP_EOL);
            }
        } finally {
            if ($this->files->exists($tempDir)) {
                $this->files->deleteDirectory($tempDir);
            }
        }

        if ($this->hasMigrationTable()) {
            $this->appendMigrationData($path);
        }
    }

    /**
     * Append the migration data to the schema dump.
     */
    protected function appendMigrationData(string $path): void
    {
        $migrationTable = $this->getMigrationTable();

        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->connection->getQueryGrammar()->wrapTable($migrationTable)
        );
        if (empty($rows)) {
            return;
        }

        $migrations = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $columns = array_keys($row);
            $values = array_map(function ($v) {
                return is_null($v) ? 'NULL' : $this->connection->getPdo()->quote((string) $v);
            }, $row);

            $migrations[] = sprintf(
                'INSERT INTO %s (%s) VALUES (%s);',
                $this->connection->getQueryGrammar()->wrapTable($migrationTable),
                implode(', ', array_map(fn($c) => $this->connection->getQueryGrammar()->wrap($c), $columns)),
                implode(', ', $values)
            );
        }

        $this->files->append($path, implode(PHP_EOL, $migrations) . PHP_EOL);
    }

    /** {@inheritdoc} */
    public function load($path)
    {
        $this->connection->getPdo()->exec($this->files->get($path));
    }
}
