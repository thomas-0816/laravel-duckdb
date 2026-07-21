<?php

namespace DuckDb;

use Exception;
use Illuminate\Database\Connection;
use DuckDb\Query\Grammars\DuckDBGrammar as QueryGrammar;
use DuckDb\Query\Processors\DuckDbProcessor;
use DuckDb\Schema\Grammars\DuckDBGrammar as SchemaGrammar;
use DuckDb\Schema\DuckDBBuilder;
use DuckDb\Schema\DuckDBSchemaState;
use Illuminate\Filesystem\Filesystem;

class DuckDbConnection extends Connection
{
    /** {@inheritdoc} */
    public function getDriverTitle()
    {
        return 'DuckDB';
    }

    /** {@inheritdoc} */
    protected function executeBeginTransactionStatement()
    {
        $this->getPdo()->beginTransaction();
    }

    /** {@inheritdoc} */
    protected function escapeBinary($value)
    {
        $hex = bin2hex($value);

        return "x'{$hex}'";
    }

    /** {@inheritdoc} */
    protected function isUniqueConstraintError(Exception $exception)
    {
        return (bool) preg_match('#(Unique constraint violation|UNIQUE constraint failed: |Duplicate key .* violates unique constraint)#i', $exception->getMessage());
    }

    /** {@inheritdoc} */
    protected function parseUniqueConstraintViolation(Exception $exception): array
    {
        preg_match('#Duplicate key "(.+)" violates unique constraint#i', $exception->getMessage(), $matches);

        $columns = [];

        if (isset($matches[1])) {
            $columns = array_map(
                static fn($col) => trim(explode(':', $col)[0]),
                explode(',', $matches[1])
            );
        }

        return ['columns' => $columns, 'index' => null];
    }

    /** {@inheritdoc} */
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    /** {@inheritdoc} */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new DuckDBBuilder($this);
    }

    /** {@inheritdoc} */
    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar($this);
    }

    /**
     * Get a schema state instance for the connection.
     *
     * @param  \Illuminate\Filesystem\Filesystem|null  $files
     * @param  callable|null  $processFactory
     * @return \DuckDb\Schema\DuckDBSchemaState
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): DuckDBSchemaState
    {
        return new DuckDBSchemaState($this, $files, $processFactory);
    }

    /** {@inheritdoc} */
    protected function getDefaultPostProcessor()
    {
        return new DuckDbProcessor();
    }
}
