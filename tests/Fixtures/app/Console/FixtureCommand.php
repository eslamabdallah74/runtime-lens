<?php

namespace RuntimeLens\Tests\Fixtures\App\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

class FixtureCommand extends Command
{
    protected $signature = 'fixture:touch';

    protected $description = 'Runs one query';

    public function handle(ConnectionInterface $db): int
    {
        $db->select('select 1 as fixture_command');

        return self::SUCCESS;
    }
}
