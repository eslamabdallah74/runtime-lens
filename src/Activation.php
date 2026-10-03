<?php

namespace RuntimeLens;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use RuntimeLens\Enums\RecordingMode;

final class Activation
{
    private const WORKER_MODE_MARKERS = ['LARAVEL_OCTANE', 'FRANKENPHP_WORKER'];

    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
    ) {
    }

    public function mode(): RecordingMode
    {
        if ($this->offReason() !== null) {
            return RecordingMode::Off;
        }

        return $this->app->runningUnitTests() ? RecordingMode::Tests : RecordingMode::App;
    }

    public function offReason(): ?string
    {
        return match (true) {
            $this->app->environment('production') => 'APP_ENV is production, which is never recorded.',
            $this->runsInWorkerMode() => 'Octane / FrankenPHP worker mode is not supported yet.',
            $this->app->runningUnitTests() => $this->testRecordingOffReason(),
            ! $this->config->get('runtime-lens.enabled') => 'RUNTIME_LENS_ENABLED is false.',
            ! $this->app->environment($this->allowedEnvironments()) => $this->environmentOffReason(),
            default => null,
        };
    }

    public function allowedEnvironments(): array
    {
        return (array) $this->config->get('runtime-lens.environments', ['local']);
    }

    private function runsInWorkerMode(): bool
    {
        foreach (self::WORKER_MODE_MARKERS as $marker) {
            if (! empty($_SERVER[$marker]) || ! empty($_ENV[$marker])) {
                return true;
            }
        }

        return false;
    }

    private function testRecordingOffReason(): ?string
    {
        return $this->config->get('runtime-lens.record_tests') ? null : 'Running tests without RUNTIME_LENS_RECORD_TESTS=true.';
    }

    private function environmentOffReason(): string
    {
        return sprintf(
            'APP_ENV "%s" is not in RUNTIME_LENS_ENVIRONMENTS (%s).',
            $this->app->environment(),
            implode(',', $this->allowedEnvironments()),
        );
    }
}
