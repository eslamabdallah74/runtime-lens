<?php

namespace RuntimeLens\Support;

use Illuminate\Console\Application;

final class ArtisanCommands
{
    private ?Application $artisan = null;

    public function capture(Application $artisan): void
    {
        $this->artisan = $artisan;
    }

    public function classFor(string $commandName): ?string
    {
        if ($this->artisan === null || ! $this->artisan->has($commandName)) {
            return null;
        }

        return $this->artisan->find($commandName)::class;
    }
}
