<?php

namespace RuntimeLens\Tests\Fixtures\App\Http\Controllers;

class CpuController
{
    public function show(): string
    {
        return $this->busyLoop();
    }

    private function busyLoop(): string
    {
        $hash = 'seed';
        $deadline = microtime(true) + 0.05;

        while (microtime(true) < $deadline) {
            $hash = md5($hash);
        }

        return $hash;
    }
}
