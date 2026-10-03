<?php

use Illuminate\Contracts\Console\Kernel;
use RuntimeLens\Tests\Fixtures\App\Console\FixtureCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    $kernel = $this->app->make(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->registerCommand($this->app->make(FixtureCommand::class));
});

function runLikeTheArtisanBinary(string $command): int
{
    return app(Kernel::class)->handle(new ArrayInput(['command' => $command]), new BufferedOutput());
}

it('does not record artisan commands by default', function (): void {
    expect(runLikeTheArtisanBinary('fixture:touch'))->toBe(0);

    expect($this->batches())->toBe([]);
});

it('records artisan commands when enabled, with the handle method as entry point', function (): void {
    config(['runtime-lens.record_commands' => true]);

    expect(runLikeTheArtisanBinary('fixture:touch'))->toBe(0);

    expect($this->lastBatch())
        ->kind->toBe('command')
        ->name->toBe('fixture:touch')
        ->status->toBe(0)
        ->and($this->lastBatch()['entry']['function'])->toBe(FixtureCommand::class.'::handle')
        ->and($this->appQueries($this->lastBatch())[0]['file'])->toBe('app/Console/FixtureCommand.php');
});

it('never records ignored commands', function (): void {
    config(['runtime-lens.record_commands' => true, 'runtime-lens.ignore_commands' => ['fixture:touch']]);

    runLikeTheArtisanBinary('fixture:touch');

    expect($this->batches())->toBe([]);
});
