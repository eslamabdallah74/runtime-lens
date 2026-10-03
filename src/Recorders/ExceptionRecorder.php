<?php

namespace RuntimeLens\Recorders;

use RuntimeLens\Batch\UnitOfWork;
use RuntimeLens\Support\CallerResolver;
use RuntimeLens\Support\FailureReporter;
use Throwable;

final class ExceptionRecorder
{
    private const MESSAGE_LENGTH = 500;

    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        private readonly CallerResolver $callerResolver,
        private readonly FailureReporter $failureReporter,
    ) {
    }

    public function record(Throwable $exception): void
    {
        $this->failureReporter->guard(function () use ($exception): void {
            $this->recordInOpenBatch($exception);
        });
    }

    private function recordInOpenBatch(Throwable $exception): void
    {
        $batch = $this->unitOfWork->current();

        if ($batch === null) {
            return;
        }

        $location = $this->callerResolver->locate($exception->getFile(), $exception->getLine())
            ?? $this->callerResolver->firstAppLocation($exception->getTrace());

        $batch->recordException([
            'class' => $exception::class,
            'message' => mb_strimwidth($exception->getMessage(), 0, self::MESSAGE_LENGTH),
        ] + $location->toArray());
    }
}
