<?php

namespace RuntimeLens\Listeners;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use RuntimeLens\Batch\UnitOfWork;
use RuntimeLens\Enums\BatchKind;
use RuntimeLens\Enums\JobOutcome;
use RuntimeLens\Recorders\ExceptionRecorder;
use RuntimeLens\Support\EntryPointResolver;
use RuntimeLens\Support\FailureReporter;

final class JobLifecycle
{
    private const SYNC_CONNECTION = 'sync';

    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        private readonly EntryPointResolver $entryPointResolver,
        private readonly ExceptionRecorder $exceptionRecorder,
        private readonly FailureReporter $failureReporter,
    ) {
    }

    public function processing(JobProcessing $event): void
    {
        $this->failureReporter->guard(function () use ($event): void {
            $this->openJobBatch($event);
        });
    }

    public function processed(JobProcessed $event): void
    {
        if ($this->isSyncConnection($event->connectionName)) {
            return;
        }

        $this->failureReporter->guard(function (): void {
            $this->unitOfWork->close(BatchKind::Job, JobOutcome::Processed->value);
        });
    }

    public function failed(JobFailed|JobExceptionOccurred $event): void
    {
        if ($this->isSyncConnection($event->connectionName)) {
            return;
        }

        $this->failureReporter->guard(function () use ($event): void {
            $this->closeFailedJobBatch($event);
        });
    }

    private function openJobBatch(JobProcessing $event): void
    {
        if ($this->isSyncConnection($event->connectionName)) {
            return;
        }

        $batch = $this->unitOfWork->open(BatchKind::Job, $event->job->resolveName(), microtime(true));
        $jobClass = $event->job->payload()['data']['commandName'] ?? null;

        if (is_string($jobClass)) {
            $batch->entry = $this->entryPointResolver->forClassMethod($jobClass, 'handle')?->toArray();
        }
    }

    private function isSyncConnection(string $connectionName): bool
    {
        return $connectionName === self::SYNC_CONNECTION;
    }

    private function closeFailedJobBatch(JobFailed|JobExceptionOccurred $event): void
    {
        if ($this->unitOfWork->current()?->kind !== BatchKind::Job) {
            return;
        }

        $this->exceptionRecorder->record($event->exception);
        $this->unitOfWork->close(BatchKind::Job, JobOutcome::Failed->value);
    }
}
