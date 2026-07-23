<?php

namespace DuckDb\Schema;

use Illuminate\Database\Schema\Blueprint;

class DuckDBBlueprint extends Blueprint
{
    /** {@inheritdoc} */
    public function build()
    {
        $this->connection->transaction(function () {
            foreach ($this->toSql() as $statement) {
                $this->connection->statement($statement);
            }
        });
    }
}
