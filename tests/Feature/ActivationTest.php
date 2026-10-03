<?php

use RuntimeLens\Activation;
use RuntimeLens\Enums\RecordingMode;

afterEach(function (): void {
    unset($_SERVER['LARAVEL_OCTANE'], $_SERVER['FRANKENPHP_WORKER']);
});

it('records the test suite only when record_tests is on', function (bool $recordTests, RecordingMode $expected): void {
    config(['runtime-lens.record_tests' => $recordTests]);

    expect(app(Activation::class)->mode())->toBe($expected);
})->with([
    'on' => [true, RecordingMode::Tests],
    'off' => [false, RecordingMode::Off],
]);

it('never records in production, whatever the config says', function (): void {
    $this->app['env'] = 'production';
    config(['runtime-lens.enabled' => true, 'runtime-lens.record_tests' => true, 'runtime-lens.environments' => ['production']]);

    expect(app(Activation::class))
        ->mode()->toBe(RecordingMode::Off)
        ->offReason()->toContain('production');
});

it('records the app only in allowed environments', function (string $environment, array $allowed, RecordingMode $expected): void {
    $this->app['env'] = $environment;
    config(['runtime-lens.environments' => $allowed]);

    expect(app(Activation::class)->mode())->toBe($expected);
})->with([
    'local by default' => ['local', ['local'], RecordingMode::App],
    'custom dev environment' => ['test', ['local', 'test'], RecordingMode::App],
    'not allowed' => ['staging', ['local'], RecordingMode::Off],
]);

it('explains why recording is off for a disallowed environment', function (): void {
    $this->app['env'] = 'staging';

    expect(app(Activation::class)->offReason())->toBe('APP_ENV "staging" is not in RUNTIME_LENS_ENVIRONMENTS (local).');
});

it('switches off under Octane and FrankenPHP worker mode', function (string $marker): void {
    $_SERVER[$marker] = '1';

    expect(app(Activation::class))
        ->mode()->toBe(RecordingMode::Off)
        ->offReason()->toContain('worker mode');
})->with(['LARAVEL_OCTANE', 'FRANKENPHP_WORKER']);

it('respects RUNTIME_LENS_ENABLED=false', function (): void {
    $this->app['env'] = 'local';
    config(['runtime-lens.enabled' => false]);

    expect(app(Activation::class)->mode())->toBe(RecordingMode::Off);
});

it('parses RUNTIME_LENS_ENVIRONMENTS as a comma-separated list', function (): void {
    putenv('RUNTIME_LENS_ENVIRONMENTS=local, test ,dev');

    $config = require __DIR__.'/../../config/runtime-lens.php';

    putenv('RUNTIME_LENS_ENVIRONMENTS');

    expect($config['environments'])->toBe(['local', 'test', 'dev']);
});
