<?php

namespace RuntimeLens\Profiling;

interface Profiler
{
    public function start(): void;

    public function stop(): ?array;
}
