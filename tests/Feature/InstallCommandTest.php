<?php

beforeEach(function (): void {
    $this->projectDirectory = $this->storageDirectory.'/project';
    mkdir($this->projectDirectory, 0777, true);
    file_put_contents($this->projectDirectory.'/.env', "APP_ENV=testing\n");
    $this->app->useEnvironmentPath($this->projectDirectory);
    $this->app->setBasePath($this->projectDirectory);
});

it('only reports and changes nothing when run without interaction', function (): void {
    $this->artisan('runtime-lens:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Recording')
        ->expectsOutputToContain('Next steps')
        ->assertExitCode(0);

    expect(file_get_contents($this->projectDirectory.'/.env'))->toBe("APP_ENV=testing\n")
        ->and(file_exists($this->projectDirectory.'/.vscode/extensions.json'))->toBeFalse();
});

it('adds the current environment to RUNTIME_LENS_ENVIRONMENTS when confirmed', function (): void {
    $this->artisan('runtime-lens:install')
        ->expectsConfirmation('Record this project while APP_ENV is "testing"? This adds RUNTIME_LENS_ENVIRONMENTS=local,testing to .env', 'yes')
        ->expectsConfirmation('Recommend the Runtime Lens VS Code extension to your team in .vscode/extensions.json?', 'no')
        ->assertExitCode(0);

    expect(file_get_contents($this->projectDirectory.'/.env'))->toBe("APP_ENV=testing\n\nRUNTIME_LENS_ENVIRONMENTS=local,testing\n");
});

it('replaces an existing RUNTIME_LENS_ENVIRONMENTS line instead of adding a second one', function (): void {
    file_put_contents($this->projectDirectory.'/.env', "APP_ENV=testing\nRUNTIME_LENS_ENVIRONMENTS=dev\nOTHER=1\n");
    config(['runtime-lens.environments' => ['dev']]);

    $this->artisan('runtime-lens:install')
        ->expectsConfirmation('Record this project while APP_ENV is "testing"? This adds RUNTIME_LENS_ENVIRONMENTS=dev,testing to .env', 'yes')
        ->expectsConfirmation('Recommend the Runtime Lens VS Code extension to your team in .vscode/extensions.json?', 'no');

    expect(file_get_contents($this->projectDirectory.'/.env'))->toBe("APP_ENV=testing\nRUNTIME_LENS_ENVIRONMENTS=dev,testing\nOTHER=1\n");
});

it('adds the extension to existing VS Code recommendations', function (): void {
    config(['runtime-lens.environments' => ['testing']]);
    mkdir($this->projectDirectory.'/.vscode');
    file_put_contents($this->projectDirectory.'/.vscode/extensions.json', '{"recommendations": ["bmewburn.vscode-intelephense-client"]}');

    $this->artisan('runtime-lens:install')
        ->expectsConfirmation('Recommend the Runtime Lens VS Code extension to your team in .vscode/extensions.json?', 'yes')
        ->assertExitCode(0);

    expect(json_decode(file_get_contents($this->projectDirectory.'/.vscode/extensions.json'), true))
        ->toBe(['recommendations' => ['bmewburn.vscode-intelephense-client', 'runtime-lens.runtime-lens']]);
});

it('leaves a commented extensions.json alone', function (): void {
    config(['runtime-lens.environments' => ['testing']]);
    mkdir($this->projectDirectory.'/.vscode');
    file_put_contents($this->projectDirectory.'/.vscode/extensions.json', "{\n  // team picks\n  \"recommendations\": []\n}");

    $this->artisan('runtime-lens:install')
        ->expectsOutputToContain('not plain JSON')
        ->assertExitCode(0);

    expect(file_get_contents($this->projectDirectory.'/.vscode/extensions.json'))->toContain('// team picks');
});
