<?php

namespace RuntimeLens\Profiling;

final class NullProfiler implements Profiler
{
    public function start(): void
    {
    }

    public function stop(): ?array
    {
        return null;
    }
}
