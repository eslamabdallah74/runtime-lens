<?php

namespace RuntimeLens\Batch;

use RuntimeLens\Activation;
use RuntimeLens\Enums\BatchKind;
use RuntimeLens\Profiling\Profiler;
use RuntimeLens\Support\SourceLines;

final class UnitOfWork
{
    private ?Batch $current = null;

    public function __construct(
        private readonly Activation $activation,
        private readonly BatchWriter $writer,
        private readonly SourceLines $sourceLines,
        private readonly Profiler $profiler,
    ) {
    }

    public function open(BatchKind $kind, string $name, float $startedAt): Batch
    {
        $this->writeCurrent();
        $this->sourceLines->forget();

        $this->current = new Batch(bin2hex(random_bytes(5)), $this->activation->mode(), $kind, $name, $startedAt);
        $this->profiler->start();

        return $this->current;
    }

    public function current(): ?Batch
    {
        return $this->current;
    }

    public function close(BatchKind $kind, int|string|null $status = null): void
    {
        if ($this->current?->kind !== $kind) {
            return;
        }

        if ($status !== null) {
            $this->current->status = $status;
        }

        $this->writeCurrent();
    }

    private function writeCurrent(): void
    {
        if ($this->current === null) {
            return;
        }

        $batch = $this->current;
        $this->current = null;
        $finishedAt = microtime(true);
        $batch->profile = $this->profiler->stop();

        $this->writer->write($batch->toArray($finishedAt));
    }
}
