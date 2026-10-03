<?php

namespace RuntimeLens\Support;

use Psr\Log\LoggerInterface;
use Throwable;

final class FailureReporter
{
    private const REPORT_INTERVAL_SECONDS = 3600;

    private static bool $failureReported = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $markerFile,
    ) {
    }

    public function guard(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            $this->reportFailure($exception);
        }
    }

    private function reportFailure(Throwable $exception): void
    {
        if (self::$failureReported || $this->reportedRecently()) {
            return;
        }

        self::$failureReported = true;

        try {
            touch($this->markerFile);
            $this->logger->debug('Runtime Lens recorder failure: '.$exception->getMessage(), ['exception' => $exception]);
        } catch (Throwable) {
        }
    }

    private function reportedRecently(): bool
    {
        clearstatcache(true, $this->markerFile);

        return is_file($this->markerFile) && filemtime($this->markerFile) > time() - self::REPORT_INTERVAL_SECONDS;
    }
}
