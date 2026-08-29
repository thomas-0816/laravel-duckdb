<?php

namespace DuckDb;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use PDO;

class DuckDBConnector extends Connector implements ConnectorInterface
{
    public function connect(array $config): PDO
    {
        return $this->createConnection("duckdb:{$config['database']}", $config, $this->getOptions($config));
    }
}
