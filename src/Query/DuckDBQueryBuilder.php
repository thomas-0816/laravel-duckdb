<?php

namespace DuckDb\Query;

use Illuminate\Database\Query\Builder;
use RuntimeException;

class DuckDBQueryBuilder extends Builder
{
    /** {@inheritdoc} */
    protected function ensureConnectionSupportsVectors()
    {
        throw_if(! $this->getGrammar()->supportsVectorDistance(), RuntimeException::class, 'Vector distance queries are not supported.');
    }
}
