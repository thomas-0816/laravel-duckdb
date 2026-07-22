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
    protected $modifiers = ['Increment', 'Nullable', 'Default', 'Collate', 'VirtualAs', 'StoredAs'];

    /** @var string[] */
    protected $serials = ['bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger'];

    /** @inheritDoc */
    public function compileSchemas()
    {
        return "select schema_name as name, schema_name = 'main' as \"default\" from information_schema.schemata order by schema_name";
    }

    /** @inheritDoc */
    public function compileTableExists($schema, $table)
    {
        return sprintf(
            "select exists (select 1 from information_schema.tables where table_name = %s and table_schema = %s and table_type = 'BASE TABLE') as \"exists\"",
            $this->quoteString($table),
            $this->quoteString($schema ?? 'main')
        );
    }

    /** @inheritDoc */
    public function compileTables($schema)
    {
        $sql = 'select table_name as name, table_schema as schema'
            . ' from information_schema.tables as t where'
            . (match (true) {
                ! empty($schema) && is_array($schema) => ' t.table_schema in (' . $this->quoteString($schema) . ') and',
                ! empty($schema) => ' t.table_schema = ' . $this->quoteString($schema) . ' and',
                default => '',
            })
            . " t.table_type = 'BASE TABLE' "
            . 'order by t.table_schema, t.table_name';

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
            "select column_name as name, data_type as type, is_nullable = 'YES' as \"nullable\", column_default as \"default\", ordinal_position as \"cid\" "
            . "from information_schema.columns where table_name = %s and table_schema = %s order by ordinal_position asc",
            $this->quoteString($table),
            $this->quoteString($schema ?? 'main')
        );
    }

    /** @inheritDoc */
    public function compileIndexes($schema, $table)
    {
        return sprintf(
            "select name, group_concat(\"column\") as columns, \"unique\", \"primary\" from ("
            . "select i.index_name as name, ic.column_name as \"column\", i.is_unique as \"unique\", 0 as \"primary\""
            . " from information_schema.index_columns ic"
            . " join information_schema.indexes i on ic.index_name = i.index_name and ic.table_name = i.table_name and ic.schema_name = i.schema_name"
            . " where ic.table_name = %s and ic.schema_name = %s"
            . " union all"
            . " select c.constraint_name as name, kcu.column_name as \"column\", 1 as \"unique\", 1 as \"primary\""
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
            "select group_concat(constraint_column_name) as columns, %s as foreign_schema, foreign_table_name as foreign_table, "
            . "group_concat(foreign_column_name) as foreign_columns, 'cascade' as on_update, 'cascade' as on_delete from information_schema.key_column_usage "
            . "where table_name = %s and table_schema = %s and foreign_table_name is not null group by constraint_name, foreign_table_name",
            $this->quoteString($schema ?? 'main'),
            $this->quoteString($table),
            $this->quoteString($schema ?? 'main')
        );
    }

    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            '%s table %s (%s%s%s)',
            $blueprint->temporary ? 'create temporary' : 'create',
            $this->wrapTable($blueprint),
            implode(', ', $this->getColumns($blueprint)),
            $this->addForeignKeys($this->getCommandsByName($blueprint, 'foreign')),
            $this->addPrimaryKeys($this->getCommandByName($blueprint, 'primary'))
        );
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
        $sql = sprintf(
            ', constraint %s foreign key(%s) references %s(%s)',
            $this->wrap($foreign->index),
            $this->columnize($foreign->columns),
            $this->wrapTable($foreign->on),
            $this->columnize((array) $foreign->references)
        );

        if (! is_null($foreign->onDelete)) {
            $sql .= " on delete {$foreign->onDelete}";
        }

        if (! is_null($foreign->onUpdate)) {
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

    public function compileAdd(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'alter table %s add column %s',
            $this->wrapTable($blueprint),
            $this->getColumn($blueprint, $command->column)
        );
    }

    /** @inheritDoc */
    public function getAlterCommands(): array
    {
        return ['change', 'primary', 'dropPrimary', 'foreign', 'dropForeign'];
    }

    /** @inheritDoc */
    public function compileChange(Blueprint $blueprint, Fluent $command)
    {
        // Handled on table alteration...
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

                if (is_null($column->virtualAs) && is_null($column->virtualAsJson)
                    && is_null($column->storedAs) && is_null($column->storedAsJson)) {
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

        return array_filter(array_merge([
            'begin transaction',
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
            'commit',
        ], $indexes));
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

    /** @inheritDoc */
    public function compilePrimary(Blueprint $blueprint, Fluent $command)
    {
        // Handled on table creation or alteration...
    }

    /** @inheritDoc */
    public function compileForeign(Blueprint $blueprint, Fluent $command)
    {
        // Handled on table creation or alteration...
    }

    /** @inheritDoc */
    public function compileDropPrimary(Blueprint $blueprint, Fluent $command)
    {
        // Handled on table alteration...
    }

    /** @inheritDoc */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command)
    {
        // Handled on table alteration...
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
            "select 'drop table if exists ' || '\"' || table_schema || '\".\"' || table_name || '\"' || ';' from information_schema.tables where table_schema = %s and table_type = 'BASE TABLE'",
            $this->quoteString($schema ?? 'main')
        );
    }

    public function compileDropAllViews(?string $schema = null): string
    {
        return sprintf(
            "select 'drop view if exists ' || table_name from information_schema.views where table_schema = %s",
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

    protected function modifyStoredAs(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($storedAs = $column->storedAsJson)) {
            if ($this->isJsonSelector($storedAs)) {
                $storedAs = $this->wrapJsonSelector($storedAs);
            }

            return " as ({$storedAs}) stored";
        }

        if (! is_null($storedAs = $column->storedAs)) {
            return " as ({$this->getValue($column->storedAs)}) stored";
        }

        return null;
    }

    protected function modifyNullable(Blueprint $blueprint, Fluent $column): ?string
    {
        if (is_null($column->virtualAs)
            && is_null($column->virtualAsJson)
            && is_null($column->storedAs)
            && is_null($column->storedAsJson)) {
            return $column->nullable ? '' : ' not null';
        }

        if ($column->nullable === false) {
            return ' not null';
        }

        return null;
    }

    protected function modifyDefault(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->default) && is_null($column->virtualAs) && is_null($column->virtualAsJson) && is_null($column->storedAs)) {
            return ' default ' . $this->getDefaultValue($column->default);
        }

        return null;
    }

    protected function modifyIncrement(Blueprint $blueprint, Fluent $column): ?string
    {
        if (in_array($column->type, $this->serials) && $column->autoIncrement) {
            return ' primary key autoincrement';
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
