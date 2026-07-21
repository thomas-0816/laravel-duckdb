<?php

use DuckDb\DuckDbConnection;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you return from this function will be used as the test case
| when running Pest tests. You may configure your test case with test case
| specific options and overrides in the `uses()` call as needed.
|
*/

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet
| certain conditions. The `expect()` function gives you access to a
| set of "expectations" to make assertions more fluent.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-box, you may have some testing code
| specific to your project that you don't want to repeat in every file.
|
*/

function getTableNames(DuckDbConnection $connection, string $schema): array
{
    return $connection->table('information_schema.tables')
        ->select('table_name')
        ->where('table_type', 'BASE TABLE')
        ->where('table_schema', $schema)
        ->get()
        ->toArray();
}

function getKeyColumnUsage(DuckDbConnection $connection, ?string $tableName, ?string $schema, bool $fkColumnsOnly): array
{
    $query = $connection->table('information_schema.key_column_usage')
        ->select('constraint_name', 'table_name', 'column_name');

    if ($tableName !== null && $schema !== null) {
        $query->where('table_name', $tableName)
            ->where('table_schema', $schema);
    }

    if ($fkColumnsOnly) {
        $query->whereNotNull('position_in_unique_constraint');
    }

    return $query->get()->toArray();
}

function getReferentialConstraints(DuckDbConnection $connection): array
{
    return $connection->table('information_schema.referential_constraints')
        ->select('constraint_name', 'unique_constraint_name', 'update_rule', 'delete_rule')
        ->get()
        ->toArray();
}

function getTableConstraints(DuckDbConnection $connection, string $tableName, string $schema, array $constraintTypes): array
{
    $constraints = $connection->table('information_schema.table_constraints')
        ->select('constraint_name', 'constraint_type')
        ->where('table_name', $tableName)
        ->where('table_schema', $schema)
        ->whereIn('constraint_type', $constraintTypes)
        ->get()
        ->toArray();

    $cols = getKeyColumnUsage($connection, $tableName, $schema, false);

    $lookup = [];
    foreach ($cols as $c) {
        $lookup[$c->constraint_name][] = $c->column_name;
    }

    $results = [];
    foreach ($constraints as $c) {
        $name = strtolower($c->constraint_name);
        $results[] = (object) [
            'name' => str_contains($name, 'pkey') ? 'primary' : $name,
            'columns' => implode(',', $lookup[$c->constraint_name] ?? []),
            'unique' => 1,
            'primary' => $c->constraint_type === 'PRIMARY KEY' ? 1 : 0,
        ];
    }

    return $results;
}
