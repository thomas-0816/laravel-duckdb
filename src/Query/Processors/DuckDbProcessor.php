<?php

namespace DuckDb\Query\Processors;

use Illuminate\Database\Query\Processors\Processor;

class DuckDbProcessor extends Processor
{
    /** @inheritDoc */
    public function processColumns($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;

            $type = strtolower($result->type);

            return [
                'name' => $result->name,
                'type_name' => strtok($type, '(') ?: '',
                'type' => $type,
                'collation' => null,
                'nullable' => (bool) $result->nullable,
                'default' => $result->default ?? null,
                'auto_increment' => false,
                'comment' => null,
                'generation' => null,
            ];
        }, $results);
    }

    /** @inheritDoc */
    public function processIndexes($results)
    {
        $primaryCount = 0;

        $indexes = array_map(function ($result) use (&$primaryCount) {
            $result = (object) $result;

            if ($isPrimary = (bool) ($result->primary ?? false)) {
                $primaryCount += 1;
            }

            return [
                'name' => strtolower($result->name),
                'columns' => $result->columns ? explode(',', $result->columns) : [],
                'type' => null,
                'unique' => (bool) $result->unique,
                'primary' => $isPrimary,
            ];
        }, $results);

        if ($primaryCount > 1) {
            $indexes = array_filter($indexes, fn($index) => $index['name'] !== 'primary');
        }

        /** @var list<array{name: string, columns: list<string>, type: string|null, unique: bool, primary: bool}> */
        return $indexes;
    }

    /** @inheritDoc */
    public function processForeignKeys($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name ?? null,
                'columns' => explode(',', $result->columns),
                'foreign_schema' => $result->foreign_schema ?? null,
                'foreign_table' => $result->foreign_table,
                'foreign_columns' => explode(',', $result->foreign_columns),
                'on_update' => strtolower($result->on_update),
                'on_delete' => strtolower($result->on_delete),
            ];
        }, $results);
    }
}
