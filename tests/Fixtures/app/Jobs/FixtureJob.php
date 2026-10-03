<?php

namespace RuntimeLens\Tests\Fixtures\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Queue\Queueable;

class FixtureJob implements ShouldQueue
{
    use Queueable;

    public function handle(ConnectionInterface $db): void
    {
        $db->select('select 1 as fixture_job');
    }
}
