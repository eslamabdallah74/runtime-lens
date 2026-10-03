<?php

use Psr\Log\AbstractLogger;
use RuntimeLens\Support\FailureReporter;

function recordingLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        public array $messages = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }
    };
}

it('swallows the failure and logs it once', function (): void {
    $logger = recordingLogger();
    $reporter = new FailureReporter($logger, $this->storageDirectory.'/marker');

    $reporter->guard(fn () => throw new RuntimeException('disk full'));
    $reporter->guard(fn () => throw new RuntimeException('disk full'));

    expect($logger->messages)->toBe(['Runtime Lens recorder failure: disk full']);
});

it('never throws, even when the marker file cannot be checked', function (): void {
    $reporter = new FailureReporter(recordingLogger(), "/tmp/runtime-lens\0invalid");

    $reporter->guard(fn () => throw new RuntimeException('boom'));

    expect(true)->toBeTrue();
});

it('logs before marking, so an unwritable marker does not hide the failure', function (): void {
    $logger = recordingLogger();
    $reporter = new FailureReporter($logger, $this->storageDirectory.'/missing-directory/marker');

    $reporter->guard(fn () => throw new RuntimeException('boom'));

    expect($logger->messages)->toBe(['Runtime Lens recorder failure: boom']);
});
