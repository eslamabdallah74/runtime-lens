<?php

use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use RuntimeLens\Batch\UnitOfWork;
use RuntimeLens\Enums\BatchKind;
use RuntimeLens\Tests\Fixtures\App\Jobs\FailingJob;
use RuntimeLens\Tests\Fixtures\App\Jobs\FixtureJob;
use RuntimeLens\Tests\Fixtures\App\Jobs\OuterJob;

function processOnQueueWorker(object $job): void
{
    $payload = [
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'displayName' => $job::class,
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'maxTries' => $job->tries ?? null,
        'data' => ['commandName' => $job::class, 'command' => serialize($job)],
    ];

    $queueJob = new SyncJob(app(), json_encode($payload), 'redis', 'default');

    try {
        app(Worker::class)->process('redis', $queueJob, new WorkerOptions(maxTries: 1));
    } catch (Throwable $exception) {
        app(Illuminate\Contracts\Debug\ExceptionHandler::class)->report($exception);
    }
}

beforeEach(function (): void {
    $this->app->bind(Worker::class, fn ($app) => new Worker(
        $app['queue'],
        $app['events'],
        $app[Illuminate\Contracts\Debug\ExceptionHandler::class],
        fn (): bool => false,
    ));
});

it('records a queued job as its own batch with its handle method as entry point', function (): void {
    processOnQueueWorker(new FixtureJob());

    expect($this->lastBatch())
        ->kind->toBe('job')
        ->name->toBe(FixtureJob::class)
        ->status->toBe('processed')
        ->and($this->lastBatch()['entry']['function'])->toBe(FixtureJob::class.'::handle')
        ->and($this->appQueries($this->lastBatch())[0]['file'])->toBe('app/Jobs/FixtureJob.php');
});

it('records a failing job once, as failed, with its exception', function (): void {
    processOnQueueWorker(new FailingJob());

    $jobBatches = array_values(array_filter($this->batches(), fn (array $batch): bool => $batch['kind'] === 'job'));

    expect($jobBatches)->toHaveCount(1)
        ->and($jobBatches[0]['status'])->toBe('failed')
        ->and($jobBatches[0]['exceptions'][0])
        ->class->toBe(RuntimeException::class)
        ->file->toBe('app/Jobs/FailingJob.php');
});

it('keeps a job that runs another job synchronously in one batch', function (): void {
    processOnQueueWorker(new OuterJob());

    $jobBatches = array_values(array_filter($this->batches(), fn (array $batch): bool => $batch['kind'] === 'job'));
    $sqls = array_column($jobBatches[0]['queries'], 'sql');

    expect($jobBatches)->toHaveCount(1)
        ->and($jobBatches[0]['name'])->toBe(OuterJob::class)
        ->and($jobBatches[0]['status'])->toBe('processed')
        ->and($sqls)->toContain('select 1 as fixture_job')
        ->and($sqls)->toContain('select 2 as after_inner_job');
});

it('folds sync-connection jobs dispatched in a request into that request', function (): void {
    $this->app['router']->get('/dispatch-sync', function (): string {
        dispatch_sync(new FixtureJob());

        return 'ok';
    });

    $this->get('/dispatch-sync');

    expect($this->batches())->toHaveCount(1)
        ->and($this->lastBatch()['kind'])->toBe('request')
        ->and(array_column($this->lastBatch()['queries'], 'sql'))->toContain('select 1 as fixture_job');
});

it('writes a batch that never got its end event before opening the next one', function (): void {
    $unitOfWork = app(UnitOfWork::class);

    $unitOfWork->open(BatchKind::Job, 'stale', microtime(true));
    $unitOfWork->open(BatchKind::Job, 'next', microtime(true));
    $unitOfWork->close(BatchKind::Job, 'processed');

    expect(array_map(fn (array $batch): array => [$batch['name'], $batch['status']], $this->batches()))
        ->toBe([['stale', null], ['next', 'processed']]);
});
