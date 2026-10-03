<?php

namespace RuntimeLens\Tests\Fixtures\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Queue\Queueable;

class OuterJob implements ShouldQueue
{
    use Queueable;

    public function handle(ConnectionInterface $db): void
    {
        dispatch_sync(new FixtureJob());

        $db->select('select 2 as after_inner_job');
    }
}
