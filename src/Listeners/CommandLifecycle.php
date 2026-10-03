<?php

namespace RuntimeLens\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use RuntimeLens\Batch\UnitOfWork;
use RuntimeLens\Enums\BatchKind;
use RuntimeLens\Support\ArtisanCommands;
use RuntimeLens\Support\EntryPointResolver;
use RuntimeLens\Support\FailureReporter;

final class CommandLifecycle
{
    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        private readonly EntryPointResolver $entryPointResolver,
        private readonly Repository $config,
        private readonly ArtisanCommands $artisanCommands,
        private readonly FailureReporter $failureReporter,
    ) {
    }

    public function starting(CommandStarting $event): void
    {
        $this->failureReporter->guard(function () use ($event): void {
            $this->openCommandBatch($event);
        });
    }

    public function finished(CommandFinished $event): void
    {
        $this->failureReporter->guard(function () use ($event): void {
            $this->unitOfWork->close(BatchKind::Command, $event->exitCode);
        });
    }

    private function openCommandBatch(CommandStarting $event): void
    {
        if (! $this->shouldRecord($event->command)) {
            return;
        }

        $batch = $this->unitOfWork->open(BatchKind::Command, $event->command, microtime(true));
        $commandClass = $this->artisanCommands->classFor($event->command);

        if ($commandClass !== null) {
            $batch->entry = $this->entryPointResolver->forClassMethod($commandClass, 'handle')?->toArray();
        }
    }

    private function shouldRecord(?string $command): bool
    {
        return $command !== null
            && (bool) $this->config->get('runtime-lens.record_commands')
            && ! in_array($command, $this->config->get('runtime-lens.ignore_commands', []), true);
    }
}
