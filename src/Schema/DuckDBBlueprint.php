<?php

namespace DuckDb\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\BlueprintState;
use DuckDb\Schema\Grammars\DuckDBGrammar;

class DuckDBBlueprint extends Blueprint
{
    /** {@inheritdoc} */
    public function build()
    {
        try {
            parent::build();
        } catch (\Exception $e) {
            $this->connection->getPdo()->exec('ROLLBACK');
            throw $e;
        }
    }

    public function addAlterCommands(): void
    {
        if (! $this->grammar instanceof DuckDBGrammar) {
            parent::addAlterCommands();

            return;
        }

        $alterCommands = $this->grammar->getAlterCommands();

        [$commands, $lastCommandWasAlter, $hasAlterCommand] = [
            [], false, false,
        ];

        foreach ($this->commands as $command) {
            if (in_array($command->name, $alterCommands)) {
                $hasAlterCommand = true;
                $lastCommandWasAlter = true;
            } elseif ($lastCommandWasAlter) {
                $commands[] = $this->createCommand('alter');
                $lastCommandWasAlter = false;
            }

            $commands[] = $command;
        }

        if ($lastCommandWasAlter) {
            $commands[] = $this->createCommand('alter');
        }

        if ($hasAlterCommand) {
            $this->state = new BlueprintState($this, $this->connection);
        }

        $this->commands = $commands;
    }
}
