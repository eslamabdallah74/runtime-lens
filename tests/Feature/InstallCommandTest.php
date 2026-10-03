<?php

beforeEach(function (): void {
    $this->projectDirectory = $this->storageDirectory.'/project';
    mkdir($this->projectDirectory, 0777, true);
    file_put_contents($this->projectDirectory.'/.env', "APP_ENV=dev\n");
    $this->app->useEnvironmentPath($this->projectDirectory);
    $this->app->setBasePath($this->projectDirectory);
});

afterEach(function (): void {
    putenv('OCTANE_SERVER');
});

it('only reports and changes nothing when run without interaction', function (): void {
    $this->app['env'] = 'dev';

    $this->artisan('runtime-lens:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Recording')
        ->expectsOutputToContain('RUNTIME_LENS_ENVIRONMENTS=local,dev')
        ->expectsOutputToContain('Next steps')
        ->assertExitCode(0);

    expect(file_get_contents($this->projectDirectory.'/.env'))->toBe("APP_ENV=dev\n")
        ->and(file_exists($this->projectDirectory.'/.vscode/extensions.json'))->toBeFalse();
});

it('adds the current environment to RUNTIME_LENS_ENVIRONMENTS when confirmed', function (): void {
    $this->app['env'] = 'dev';

    $this->artisan('runtime-lens:install')
        ->expectsConfirmation('Record this project while APP_ENV is "dev"? This adds RUNTIME_LENS_ENVIRONMENTS=local,dev to .env', 'yes')
        ->expectsConfirmation('Recommend the Runtime Lens VS Code extension to your team in .vscode/extensions.json?', 'no')
        ->assertExitCode(0);

    expect(file_get_contents($this->projectDirectory.'/.env'))->toBe("APP_ENV=dev\n\nRUNTIME_LENS_ENVIRONMENTS=local,dev\n");
});

it('replaces an existing RUNTIME_LENS_ENVIRONMENTS line instead of adding a second one', function (): void {
    $this->app['env'] = 'dev';
    file_put_contents($this->projectDirectory.'/.env', "APP_ENV=dev\nRUNTIME_LENS_ENVIRONMENTS=qa\nOTHER=1\n");
    config(['runtime-lens.environments' => ['qa']]);

    $this->artisan('runtime-lens:install')
        ->expectsConfirmation('Record this project while APP_ENV is "dev"? This adds RUNTIME_LENS_ENVIRONMENTS=qa,dev to .env', 'yes')
        ->expectsConfirmation('Recommend the Runtime Lens VS Code extension to your team in .vscode/extensions.json?', 'no');

    expect(file_get_contents($this->projectDirectory.'/.env'))->toBe("APP_ENV=dev\nRUNTIME_LENS_ENVIRONMENTS=qa,dev\nOTHER=1\n");
});

it('points to RUNTIME_LENS_RECORD_TESTS instead of offering to allow the testing environment', function (): void {
    $this->artisan('runtime-lens:install', ['--no-interaction' => true])
        ->expectsOutputToContain('RUNTIME_LENS_RECORD_TESTS=true php artisan test')
        ->assertExitCode(0);

    expect(file_get_contents($this->projectDirectory.'/.env'))->toBe("APP_ENV=dev\n");
});

it('adds the extension to existing VS Code recommendations', function (): void {
    mkdir($this->projectDirectory.'/.vscode');
    file_put_contents($this->projectDirectory.'/.vscode/extensions.json', '{"recommendations": ["bmewburn.vscode-intelephense-client"]}');

    $this->artisan('runtime-lens:install')
        ->expectsConfirmation('Recommend the Runtime Lens VS Code extension to your team in .vscode/extensions.json?', 'yes')
        ->assertExitCode(0);

    expect(json_decode(file_get_contents($this->projectDirectory.'/.vscode/extensions.json'), true))
        ->toBe(['recommendations' => ['bmewburn.vscode-intelephense-client', 'runtime-lens.runtime-lens']]);
});

it('leaves extensions.json alone when it is not plain JSON with a list of recommendations', function (string $contents): void {
    mkdir($this->projectDirectory.'/.vscode');
    file_put_contents($this->projectDirectory.'/.vscode/extensions.json', $contents);

    $this->artisan('runtime-lens:install', ['--no-interaction' => true])
        ->expectsOutputToContain('not plain JSON')
        ->assertExitCode(0);

    expect(file_get_contents($this->projectDirectory.'/.vscode/extensions.json'))->toBe($contents);
})->with([
    'comments' => "{\n  // team picks\n  \"recommendations\": []\n}",
    'recommendations is a string' => '{"recommendations": "x"}',
    'top level list' => '["x"]',
]);

it('warns that Octane workers are not recorded', function (): void {
    putenv('OCTANE_SERVER=frankenphp');

    $this->artisan('runtime-lens:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Octane')
        ->assertExitCode(0);
});
