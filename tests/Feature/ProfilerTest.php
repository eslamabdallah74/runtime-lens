<?php

it('adds a profile with app-only functions and lines when Excimer is loaded', function (): void {
    $this->app['router']->get('/cpu', [RuntimeLens\Tests\Fixtures\App\Http\Controllers\CpuController::class, 'show']);

    $this->get('/cpu')->assertOk();

    $profile = $this->lastBatch()['profile'];
    $functions = array_column($profile['functions'], 'function');
    $files = [...array_column($profile['functions'], 'file'), ...array_column($profile['lines'], 'file')];
    $totals = array_column($profile['functions'], 'total_ms');
    $sorted = $totals;
    rsort($sorted);

    expect($profile['period_ms'])->toEqual(1)
        ->and($functions)->toContain('RuntimeLens\Tests\Fixtures\App\Http\Controllers\CpuController::busyLoop')
        ->and(array_filter($files, fn (string $file): bool => str_starts_with($file, 'vendor/')))->toBe([])
        ->and($totals)->toBe($sorted);
})->skip(! extension_loaded('excimer'), 'Excimer is not loaded');

it('adds no profile when Excimer is not loaded', function (): void {
    $this->get('/clean');

    expect($this->lastBatch())->not->toHaveKey('profile');
})->skip(extension_loaded('excimer'), 'Excimer is loaded');
