<?php

namespace DuckDb\Schema\Grammars;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\IndexDefinition;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use RuntimeException;

class DuckDBGrammar extends Grammar
{
    /** @inheritDoc */
    protected $modifiers = ['Increment', 'VirtualAs', 'StoredAs', 'Nullable', 'Default', 'Collate'];

    /** @var string[] */
    protected $serials = ['bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger'];

    /** @inheritDoc */
    public function compileSchemas()
    {
        return "select schema_name as name, schema_name = 'main' as \"default\" from duckdb_schemas() order by schema_name";
    }

    /** @inheritDoc */
    public function compileTableExists($schema, $table)
    {
        return sprintf(
            "select exists (select 1 from duckdb_tables() where table_name = %s and schema_name = %s) as \"exists\"",
            $this->quoteString($table),
            $this->quoteString($schema ?? 'main')
        );
    }

    /** @inheritDoc */
    public function compileTables($schema)
    {
        $sql = 'select table_name as name, schema_name as schema'
            . ' from duckdb_tables() as t where'
            . (match (true) {
                ! empty($schema) && is_array($schema) => ' t.schema_name in (' . $this->quoteString($schema) . ') and',
                ! empty($schema) => ' t.schema_name = ' . $this->quoteString($schema) . ' and',
                default => '',
            })
            . ' not t.internal '
            . 'order by t.schema_name, t.table_name';

        return $sql;
    }

    /** @inheritDoc */
    public function compileViews($schema)
    {
        return sprintf(
            "select view_name as name, schema_name as schema, sql as definition from duckdb_views() where schema_name = %s and not internal order by view_name",
            $this->quoteString($schema ?: '')
        );
    }

    /** @inheritDoc */
    public function compileColumns($schema, $table)
    {
        return sprintf(
            "select column_name as name, data_type as type, is_nullable = 1 as \"nullable\", column_default as \"default\", column_index as \"cid\" "
            . "from duckdb_columns() where table_name = %s and schema_name = %s order by column_index asc",
            $this->quoteString($table),
            $this->quoteString($schema ?? 'main')
        );
    }

    /** @inheritDoc */
    public function compileIndexes($schema, $table)
    {
        return sprintf(
            "select name, group_concat(\"column\") as columns, \"unique\", \"primary\" from ("
            . "select i.index_name as name, list_aggregate(list_transform(string_split(i.expressions, ','), x -> replace(replace(replace(replace(trim(x), '[', ''), ']', ''), chr(39), ''), chr(34), '')), 'string_agg', ',') as \"column\", i.is_unique as \"unique\", i.is_primary as \"primary\""
            . " from duckdb_indexes() i"
            . " where i.table_name = %s and i.schema_name = %s and i.is_primary = false"
            . " union all"
            . " select tc.constraint_name as name, kcu.column_name as \"column\", 1 as \"unique\", 1 as \"primary\""
            . " from information_schema.table_constraints tc"
            . " join information_schema.key_column_usage kcu on tc.constraint_name = kcu.constraint_name and tc.table_name = kcu.table_name and tc.table_schema = kcu.table_schema"
            . " where tc.table_name = %s and tc.table_schema = %s and tc.constraint_type = 'PRIMARY KEY'"
            . ") group by name, \"unique\", \"primary\"",
            $this->quoteString($table),
            $this->quoteString($schema ?? 'main'),
            $this->quoteString($table),
            $this->quoteString($schema ?? 'main')
        );
    }

    /** @inheritDoc */
    public function compileForeignKeys($schema, $table)
    {
        return sprintf(
            "select list_aggregate(constraint_column_names, 'string_agg', ',') as columns, %s as foreign_schema, referenced_table as foreign_table, "
            . "list_aggregate(referenced_column_names, 'string_agg', ',') as foreign_columns, null as on_update, null as on_delete from duckdb_constraints() "
            . "where table_name = %s and schema_name = %s and constraint_type = 'FOREIGN KEY'",
            $this->quoteString($schema ?? 'main'),
            $this->quoteString($table),
            $this->quoteString($schema ?? 'main')
        );
    }

    public function compileCreate(Blueprint $blueprint, Fluent $command): array
    {
        $statements = [];
        $statements[] = sprintf(
            '%s table %s (%s%s%s%s)',
            $blueprint->temporary ? 'create temporary' : 'create',
            $this->wrapTable($blueprint),
            implode(', ', $this->getColumns($blueprint)),
            $this->addForeignKeys($this->getCommandsByName($blueprint, 'foreign')),
            $this->addUniqueConstraints($this->getCommandsByName($blueprint, 'unique')),
            $this->addPrimaryKeys($this->getCommandByName($blueprint, 'primary'))
        );

        return $statements;
    }

    /** {@inheritdoc} */
    protected function addForeignKeys(array $foreignKeys): ?string
    {
        return array_reduce($foreignKeys, function ($sql, $foreign) {
            return $sql . $this->getForeignKey($foreign);
        }, '');
    }

    protected function getForeignKey(Fluent $foreign): string
    {
        $name = $foreign->index ?: 'fk_' . implode('_', (array) $foreign->columns);

        $sql = sprintf(
            ', constraint %s foreign key(%s) references %s(%s)',
            $this->wrap($name),
            $this->columnize($foreign->columns),
            $this->wrapTable($foreign->on),
            $this->columnize((array) $foreign->references)
        );

        if (! empty($foreign->onDelete)) {
            $sql .= " on delete {$foreign->onDelete}";
        }

        if (! empty($foreign->onUpdate)) {
            $sql .= " on update {$foreign->onUpdate}";
        }

        return $sql;
    }

    protected function addPrimaryKeys(?Fluent $primary): ?string
    {
        if (! is_null($primary)) {
            return ", primary key ({$this->columnize($primary->columns)})";
        }

        return null;
    }

    protected function addUniqueConstraints(array $uniques): string
    {
        return array_reduce($uniques, function ($sql, $unique) {
            return $sql . sprintf(', unique (%s)', $this->columnize($unique->columns));
        }, '');
    }

    public function compileAdd(Blueprint $blueprint, Fluent $command): array
    {
        $column = $command->column;
        $table = $this->wrapTable($blueprint);
        $wrapped = $this->wrap($column);

        $columnSql = $wrapped . ' ' . ($column->full_type_definition ?? $this->getType($column));

        if (! is_null($column->collation)) {
            $columnSql .= " collate \"{$column->collation}\"";
        }

        if (! is_null($column->default) && is_null($column->virtualAs) && is_null($column->virtualAsJson)) {
            $columnSql .= ' default ' . $this->getDefaultValue($column->default);
        }

        $statements = [
            sprintf('alter table %s add column %s', $table, $columnSql),
        ];

        if (! $column->nullable
            && is_null($column->virtualAs)
            && is_null($column->virtualAsJson)) {
            $statements[] = sprintf('alter table %s alter column %s set not null', $table, $wrapped);
        }

        return $statements;
    }

    /** @inheritDoc */
    public function getAlterCommands(): array
    {
        return ['change', 'primary', 'dropPrimary', 'foreign', 'dropForeign'];
    }

    public function compileChange(Blueprint $blueprint, Fluent $command): ?string
    {
        // Handled on table alteration...
        return null;
    }

    /** @inheritDoc */
    public function compileAlter(Blueprint $blueprint, Fluent $command): array
    {
        $columnNames = [];
        $autoIncrementColumn = null;

        $columns = (new Collection($blueprint->getState()->getColumns()))
            ->map(function ($column) use ($blueprint, &$columnNames, &$autoIncrementColumn) {
                $name = $this->wrap($column);

                $autoIncrementColumn = $column->autoIncrement ? $column->name : $autoIncrementColumn;

                if (is_null($column->virtualAs) && is_null($column->virtualAsJson)) {
                    $columnNames[] = $name;
                }

                return $this->addModifiers(
                    $this->wrap($column) . ' ' . ($column->full_type_definition ?? $this->getType($column)),
                    $blueprint,
                    $column
                );
            })->all();

        $indexes = (new Collection($blueprint->getState()->getIndexes()))
            ->reject(fn($index) => str_starts_with('duckdb_', $index->index))
            ->map(fn($index) => $this->{'compile' . ucfirst($index->name)}($blueprint, $index))
            ->all();

        [, $tableName] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($blueprint->getTable());
        $tempTable = $this->wrapTable($blueprint, '__temp__' . $this->connection->getTablePrefix());
        $table = $this->wrapTable($blueprint);
        $columnNames = implode(', ', $columnNames);

        $statements = [];
        $statements = array_merge($statements, array_filter([
            sprintf(
                'create table %s (%s%s%s)',
                $tempTable,
                implode(', ', $columns),
                $this->addForeignKeys($blueprint->getState()->getForeignKeys()),
                $autoIncrementColumn ? '' : $this->addPrimaryKeys($blueprint->getState()->getPrimaryKey())
            ),
            sprintf('insert into %s (%s) select %s from %s', $tempTable, $columnNames, $columnNames, $table),
            sprintf('drop table %s', $table),
            sprintf('alter table %s rename to %s', $tempTable, $this->wrapTable($tableName)),
        ]));

        return array_merge($statements, $indexes);
    }

    public function compileUnique(Blueprint $blueprint, Fluent $command): string
    {
        [$schema, $table] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($blueprint->getTable());

        return sprintf(
            'create unique index %s%s on %s (%s)',
            $schema ? $this->wrapValue($schema) . '.' : '',
            $this->wrap($command->index),
            $this->wrapTable($table),
            $this->columnize($command->columns)
        );
    }

    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        [$schema, $table] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($blueprint->getTable());

        return sprintf(
            'create index %s%s on %s (%s)',
            $schema ? $this->wrapValue($schema) . '.' : '',
            $this->wrap($command->index),
            $this->wrapTable($table),
            $this->columnize($command->columns)
        );
    }

    public function compilePrimary(Blueprint $blueprint, Fluent $command): ?string
    {
        // Handled on table creation or alteration...
        return null;
    }

    public function compileForeign(Blueprint $blueprint, Fluent $command): ?string
    {
        // Handled on table creation or alteration...
        return null;
    }

    public function compileDropPrimary(Blueprint $blueprint, Fluent $command): ?string
    {
        // Handled on table alteration...
        return null;
    }

    public function compileDropForeign(Blueprint $blueprint, Fluent $command): ?string
    {
        // Handled on table alteration...
        return null;
    }

    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table ' . $this->wrapTable($blueprint);
    }

    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table if exists ' . $this->wrapTable($blueprint);
    }

    public function compileDropAllTables(?string $schema = null): string
    {
        return sprintf(
            "select 'drop table if exists ' || '\"' || schema_name || '\".\"' || table_name || '\"' || ';' from duckdb_tables() where schema_name = %s and not internal",
            $this->quoteString($schema ?? 'main')
        );
    }

    public function compileDropAllViews(?string $schema = null): string
    {
        return sprintf(
            "select 'drop view if exists ' || view_name from duckdb_views() where schema_name = %s",
            $this->quoteString($schema ?? 'main')
        );
    }

    public function compileDropColumn(Blueprint $blueprint, Fluent $command): array
    {
        $table = $this->wrapTable($blueprint);

        $columns = $this->prefixArray('drop column', $this->wrapArray($command->columns));

        /** @var list<string> */
        return array_map(fn($column) => 'alter table ' . $table . ' ' . $column, $columns);
    }

    public function compileDropUnique(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDropIndex($blueprint, $command);
    }

    public function compileDropIndex(Blueprint $blueprint, Fluent $command): string
    {
        [$schema] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($blueprint->getTable());

        return sprintf(
            'drop index %s%s',
            $schema ? $this->wrapValue($schema) . '.' : '',
            $this->wrap($command->index)
        );
    }

    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        return "alter table {$this->wrapTable($blueprint)} rename to {$this->wrapTable($command->to)}";
    }

    public function compileRenameIndex(Blueprint $blueprint, Fluent $command): array
    {
        $indexes = $this->connection->getSchemaBuilder()->getIndexes($blueprint->getTable());

        $index = Arr::first($indexes, fn($index) => $index['name'] === $command->from);

        if (! $index) {
            throw new RuntimeException("Index [{$command->from}] does not exist.");
        }

        if ($index['primary']) {
            throw new RuntimeException('DuckDB does not support altering primary keys.');
        }

        if ($index['unique']) {
            return [
                $this->compileDropUnique($blueprint, new IndexDefinition(['index' => $index['name']])),
                $this->compileUnique(
                    $blueprint,
                    new IndexDefinition(['index' => $command->to, 'columns' => $index['columns']])
                ),
            ];
        }

        return [
            $this->compileDropIndex($blueprint, new IndexDefinition(['index' => $index['name']])),
            $this->compileIndex(
                $blueprint,
                new IndexDefinition(['index' => $command->to, 'columns' => $index['columns']])
            ),
        ];
    }

    protected function typeChar(Fluent $column): string
    {
        return 'varchar';
    }

    protected function typeString(Fluent $column): string
    {
        return 'varchar';
    }

    protected function typeTinyText(Fluent $column): string
    {
        return 'varchar';
    }

    protected function typeText(Fluent $column): string
    {
        return 'varchar';
    }

    protected function typeMediumText(Fluent $column): string
    {
        return 'varchar';
    }

    protected function typeLongText(Fluent $column): string
    {
        return 'varchar';
    }

    protected function typeInteger(Fluent $column): string
    {
        return 'integer';
    }

    protected function typeBigInteger(Fluent $column): string
    {
        return 'bigint';
    }

    protected function typeMediumInteger(Fluent $column): string
    {
        return 'integer';
    }

    protected function typeTinyInteger(Fluent $column): string
    {
        return 'tinyint';
    }

    protected function typeSmallInteger(Fluent $column): string
    {
        return 'smallint';
    }

    protected function typeFloat(Fluent $column): string
    {
        return 'float';
    }

    protected function typeDouble(Fluent $column): string
    {
        return 'double';
    }

    protected function typeDecimal(Fluent $column): string
    {
        return 'decimal';
    }

    protected function typeBoolean(Fluent $column): string
    {
        return 'boolean';
    }

    protected function typeEnum(Fluent $column): string
    {
        return sprintf(
            'varchar check ("%s" in (%s))',
            $column->name,
            $this->quoteString($column->allowed)
        );
    }

    protected function typeJson(Fluent $column): string
    {
        return 'json';
    }

    protected function typeJsonb(Fluent $column): string
    {
        return 'json';
    }

    protected function typeDate(Fluent $column): string
    {
        if ($column->useCurrent) {
            $column->default(new Expression('current_date'));
        }

        return 'date';
    }

    protected function typeDateTime(Fluent $column): string
    {
        return 'timestamp';
    }

    protected function typeDateTimeTz(Fluent $column): string
    {
        return 'timestamptz';
    }

    protected function typeTime(Fluent $column): string
    {
        return 'time';
    }

    protected function typeTimeTz(Fluent $column): string
    {
        return 'timetz';
    }

    protected function typeTimestamp(Fluent $column): string
    {
        if ($column->useCurrent) {
            $column->default(new Expression('current_timestamp'));
        }

        return 'timestamp';
    }

    protected function typeTimestampTz(Fluent $column): string
    {
        return 'timestamptz';
    }

    protected function typeYear(Fluent $column): string
    {
        if ($column->useCurrent) {
            $column->default(new Expression("date_part('year', current_date)"));
        }

        return 'integer';
    }

    protected function typeBinary(Fluent $column): string
    {
        return 'blob';
    }

    protected function typeUuid(Fluent $column): string
    {
        return 'uuid';
    }

    protected function typeIpAddress(Fluent $column): string
    {
        return 'varchar';
    }

    protected function typeMacAddress(Fluent $column): string
    {
        return 'varchar';
    }

    protected function typeGeometry(Fluent $column): string
    {
        return 'geometry';
    }

    protected function typeGeography(Fluent $column): string
    {
        return 'geometry';
    }

    protected function modifyVirtualAs(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($virtualAs = $column->virtualAsJson)) {
            if ($this->isJsonSelector($virtualAs)) {
                $virtualAs = $this->wrapJsonSelector($virtualAs);
            }

            return " as ({$virtualAs}) virtual";
        }

        if (! is_null($virtualAs = $column->virtualAs)) {
            return " as ({$this->getValue($virtualAs)}) virtual";
        }

        return null;
    }

    protected function modifyNullable(Blueprint $blueprint, Fluent $column): ?string
    {
        if (is_null($column->virtualAs)
            && is_null($column->virtualAsJson)) {
            return $column->nullable ? '' : ' not null';
        }

        if ($column->nullable === false) {
            return ' not null';
        }

        return null;
    }

    protected function modifyDefault(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->default) && is_null($column->virtualAs) && is_null($column->virtualAsJson)) {
            return ' default ' . $this->getDefaultValue($column->default);
        }

        return null;
    }

    protected function modifyIncrement(Blueprint $blueprint, Fluent $column): ?string
    {
        if (in_array($column->type, $this->serials) && $column->autoIncrement) {
            throw new RuntimeException('DuckDB does not support auto_increment');
        }

        return null;
    }

    protected function modifyStoredAs(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->storedAs) || ! is_null($column->storedAsJson)) {
            throw new RuntimeException('DuckDB does not support stored generated columns.');
        }

        return null;
    }

    protected function modifyCollate(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->collation)) {
            return " collate \"{$column->collation}\"";
        }

        return null;
    }

    public function compileComment(Blueprint $blueprint, Fluent $command): ?string
    {
        if (! is_null($comment = $command->column->comment) || $command->column->change) {
            return sprintf(
                'comment on column %s.%s is %s',
                $this->wrapTable($blueprint),
                $this->wrap($command->column->name),
                is_null($comment) ? 'NULL' : $this->quoteString($comment)
            );
        }

        return null;
    }

    public function compileTableComment(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'comment on table %s is %s',
            $this->wrapTable($blueprint),
            is_null($command->comment) ? 'NULL' : $this->quoteString($command->comment)
        );
    }

    protected function wrapJsonSelector($value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_extract(' . $field . $path . ')';
    }
}
