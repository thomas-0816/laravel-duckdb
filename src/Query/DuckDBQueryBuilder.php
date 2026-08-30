<?php

namespace DuckDb\Query;

use DuckDb\Query\Grammars\DuckDBQueryGrammar;
use Illuminate\Database\Query\Builder;
use RuntimeException;

class DuckDBQueryBuilder extends Builder
{
    /** {@inheritdoc} */
    protected function ensureConnectionSupportsVectors()
    {
        throw_if(! $this->getGrammar() instanceof DuckDBQueryGrammar, RuntimeException::class, 'Vector distance queries are not supported.');
    }
}
