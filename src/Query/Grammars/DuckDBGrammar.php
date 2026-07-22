<?php

namespace DuckDb\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DuckDBGrammar extends Grammar
{
    /** {@inheritdoc} */
    public function supportsSavepoints()
    {
        return false;
    }

    /** {@inheritdoc} */
    protected $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'like', 'not like', 'ilike',
        '&', '|', '<<', '>>',
    ];

    /** {@inheritdoc} */
    protected function whereLike(Builder $query, $where)
    {
        $where['operator'] = $where['not']
            ? ($where['caseSensitive'] ? 'not like' : 'not ilike')
            : ($where['caseSensitive'] ? 'like' : 'ilike');

        return $this->whereBasic($query, $where);
    }

    /** {@inheritdoc} */
    protected function whereDate(Builder $query, $where)
    {
        return $this->dateBasedWhere('date', $query, $where);
    }

    /** {@inheritdoc} */
    protected function whereDay(Builder $query, $where)
    {
        return $this->dateBasedWhere('day', $query, $where);
    }

    /** {@inheritdoc} */
    protected function whereMonth(Builder $query, $where)
    {
        return $this->dateBasedWhere('month', $query, $where);
    }

    /** {@inheritdoc} */
    protected function whereYear(Builder $query, $where)
    {
        return $this->dateBasedWhere('year', $query, $where);
    }

    /** {@inheritdoc} */
    protected function whereTime(Builder $query, $where)
    {
        $value = $this->parameter($where['value']);

        return $this->wrap($where['column']) . ' ' . $where['operator'] . ' ' . $value;
    }

    /** {@inheritdoc} */
    protected function dateBasedWhere($type, Builder $query, $where)
    {
        $value = $this->parameter($where['value']);

        return "{$type}({$this->wrap($where['column'])}) {$where['operator']} {$value}";
    }

    /** {@inheritdoc} */
    protected function compileJsonLength($column, $operator, $value)
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_array_length(' . $field . $path . ') ' . $operator . ' ' . $value;
    }

    /** {@inheritdoc} */
    protected function compileJsonContains($column, $value)
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_contains(' . $field . $path . ', ' . $value . ')';
    }

    /** {@inheritdoc} */
    protected function compileJsonContainsKey($column)
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_type(' . $field . $path . ') is not null';
    }

    /** {@inheritdoc} */
    public function compileUpdate(Builder $query, array $values)
    {
        if (isset($query->joins) || isset($query->limit)) {
            return $this->compileUpdateWithJoinsOrLimit($query, $values);
        }

        return parent::compileUpdate($query, $values);
    }

    /** {@inheritdoc} */
    public function compileInsertOrIgnore(Builder $query, array $values)
    {
        return $this->compileInsert($query, $values) . ' on conflict do nothing';
    }

    /** {@inheritdoc} */
    public function compileInsertOrIgnoreReturning(Builder $query, array $values, array $returning, ?array $uniqueBy)
    {
        $insert = $this->compileInsert($query, $values);

        return match ($uniqueBy) {
            null => "{$insert} on conflict do nothing returning {$this->columnize($returning)}",
            default => "{$insert} on conflict ({$this->columnize($uniqueBy)}) do nothing returning {$this->columnize($returning)}",
        };
    }

    /** {@inheritdoc} */
    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql)
    {
        return $this->compileInsertUsing($query, $columns, $sql) . ' on conflict do nothing';
    }

    /** {@inheritdoc} */
    protected function compileUpdateColumns(Builder $query, array $values)
    {
        $jsonGroups = $this->groupJsonColumnsForUpdate($values);

        return (new Collection($values))
            ->reject(fn($value, $key) => $this->isJsonSelector($key))
            ->merge($jsonGroups)
            ->map(function ($value, $key) use ($jsonGroups) {
                $column = last(explode('.', $key));

                $value = isset($jsonGroups[$key]) ? $this->compileJsonPatch($column, $value) : $this->parameter($value);

                return $this->wrap($column) . ' = ' . $value;
            })
            ->implode(', ');
    }

    /** {@inheritdoc} */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update)
    {
        $sql = $this->compileInsert($query, $values);

        $sql .= ' on conflict (' . $this->columnize($uniqueBy) . ') do update set ';

        $columns = (new Collection($update))->map(function ($value, $key) {
            return is_numeric($key)
                ? $this->wrap($value) . ' = ' . $this->wrapValue('excluded') . '.' . $this->wrap($value)
                : $this->wrap($key) . ' = ' . $this->parameter($value);
        })->implode(', ');

        return $sql . $columns;
    }

    protected function groupJsonColumnsForUpdate(array $values): array
    {
        $groups = [];

        foreach ($values as $key => $value) {
            if ($this->isJsonSelector($key)) {
                Arr::set($groups, str_replace('->', '.', Str::after($key, '.')), $value);
            }
        }

        return $groups;
    }

    protected function compileJsonPatch(string $column, mixed $value): string
    {
        return "json_merge_patch(ifnull({$this->wrap($column)}, json('{}')), json({$this->parameter($value)}))";
    }

    protected function compileUpdateWithJoinsOrLimit(Builder $query, array $values): string
    {
        $table = $this->wrapTable($query->from);

        $columns = $this->compileUpdateColumns($query, $values);

        $alias = last(preg_split('/\s+as\s+/i', $query->from));

        $selectSql = $this->compileSelect((clone $query)->select($alias.'.rowid'));

        return "update {$table} set {$columns} where {$this->wrap('rowid')} in ({$selectSql})";
    }

    /** {@inheritdoc} */
    #[\Override]
    public function prepareBindingsForUpdate(array $bindings, array $values)
    {
        $groups = $this->groupJsonColumnsForUpdate($values);

        $values = (new Collection($values))
            ->reject(fn($value, $key) => $this->isJsonSelector($key))
            ->merge($groups)
            ->map(fn($value) => is_array($value) ? json_encode($value) : $value)
            ->all();

        $cleanBindings = Arr::except($bindings, 'select');

        $values = Arr::flatten(array_map(fn($value) => value($value), $values));

        return array_values(
            array_merge($values, Arr::flatten($cleanBindings))
        );
    }

    /** {@inheritdoc} */
    public function compileDelete(Builder $query)
    {
        if (isset($query->joins) || isset($query->limit)) {
            return $this->compileDeleteWithJoinsOrLimit($query);
        }

        return parent::compileDelete($query);
    }

    /** {@inheritdoc} */
    protected function compileDeleteWithJoinsOrLimit(Builder $query): string
    {
        $table = $this->wrapTable($query->from);

        $alias = last(preg_split('/\s+as\s+/i', $query->from));

        $selectSql = $this->compileSelect((clone $query)->select($alias.'.rowid'));

        return "delete from {$table} where {$this->wrap('rowid')} in ({$selectSql})";
    }

    /** {@inheritdoc} */
    public function compileTruncate(Builder $query)
    {
        return [
            'delete from ' . $this->wrapTable($query->from) => [],
        ];
    }

    /** {@inheritdoc} */
    protected function wrapJsonSelector($value)
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_extract(' . $field . $path . ')';
    }
}
