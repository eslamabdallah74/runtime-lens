<?php

namespace RuntimeLens;

use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use RuntimeLens\Batch\BatchWriter;
use RuntimeLens\Batch\UnitOfWork;
use RuntimeLens\Console\InstallCommand;
use RuntimeLens\Enums\BatchKind;
use RuntimeLens\Enums\RecordingMode;
use RuntimeLens\Http\OpenBatchMiddleware;
use RuntimeLens\Listeners\CommandLifecycle;
use RuntimeLens\Listeners\JobLifecycle;
use RuntimeLens\Profiling\ExcimerSampler;
use RuntimeLens\Profiling\NullProfiler;
use RuntimeLens\Profiling\Profiler;
use RuntimeLens\Recorders\ExceptionRecorder;
use RuntimeLens\Recorders\HttpClientRecorder;
use RuntimeLens\Recorders\QueryRecorder;
use RuntimeLens\Support\ArtisanCommands;
use RuntimeLens\Support\CallerResolver;
use RuntimeLens\Support\EntryPointResolver;
use RuntimeLens\Support\FailureReporter;
use RuntimeLens\Support\SourceLines;
use Throwable;
use WeakReference;

class RuntimeLensServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configFile(), 'runtime-lens');
        $this->loadDefaultsWhenConfigIsCached();
        $this->registerServices();
    }

    public function boot(): void
    {
        $this->publishes([$this->configFile() => $this->app->configPath('runtime-lens.php')], 'runtime-lens-config');
        $this->registerInstallCommand();

        if ($this->app->make(Activation::class)->mode() === RecordingMode::Off) {
            return;
        }

        $this->recordRequests();
        $this->recordEvents($this->app->make(Dispatcher::class));
        $this->recordReportedExceptions($this->app->make(ExceptionHandler::class));
        $this->captureArtisan();
    }

    private function configFile(): string
    {
        return __DIR__.'/../config/runtime-lens.php';
    }

    private function loadDefaultsWhenConfigIsCached(): void
    {
        if (! $this->app['config']->has('runtime-lens')) {
            $this->app['config']->set('runtime-lens', require $this->configFile());
        }
    }

    private function registerServices(): void
    {
        foreach ([Activation::class, UnitOfWork::class, QueryRecorder::class, HttpClientRecorder::class, ExceptionRecorder::class,
            EntryPointResolver::class, JobLifecycle::class, CommandLifecycle::class, ArtisanCommands::class] as $service) {
            $this->app->singleton($service);
        }

        $this->app->singleton(SourceLines::class, fn (): SourceLines => $this->sourceLines());
        $this->app->singleton(CallerResolver::class, fn (Application $app): CallerResolver => $this->callerResolver($app));
        $this->app->singleton(Profiler::class, fn (Application $app): Profiler => $this->profiler($app));
        $this->app->singleton(BatchWriter::class, fn (Application $app): BatchWriter => $this->batchWriter($app));
        $this->app->singleton(FailureReporter::class, fn (Application $app): FailureReporter => $this->failureReporter($app));
    }

    private function sourceLines(): SourceLines
    {
        return new SourceLines(defined('LARAVEL_START') ? LARAVEL_START : microtime(true));
    }

    private function callerResolver(Application $app): CallerResolver
    {
        return new CallerResolver($app->basePath(), $this->compiledViewPath($app), $app->make(SourceLines::class));
    }

    private function compiledViewPath(Application $app): string
    {
        $compiledViewPath = $app['config']->get('view.compiled');

        return is_string($compiledViewPath) && $compiledViewPath !== '' ? $compiledViewPath : $app->storagePath('framework/views');
    }

    private function profiler(Application $app): Profiler
    {
        if (! $this->excimerAvailable($app)) {
            return new NullProfiler();
        }

        return new ExcimerSampler(
            $app->make(CallerResolver::class),
            $app->make(SourceLines::class),
            (float) $app['config']->get('runtime-lens.profile_period_ms'),
        );
    }

    private function excimerAvailable(Application $app): bool
    {
        return extension_loaded('excimer') && (bool) $app['config']->get('runtime-lens.profile');
    }

    private function batchWriter(Application $app): BatchWriter
    {
        return new BatchWriter($app->storagePath('runtime-lens'), (int) $app['config']->get('runtime-lens.max_file_mb') * 1024 * 1024);
    }

    private function failureReporter(Application $app): FailureReporter
    {
        return new FailureReporter(
            $app->make(LoggerInterface::class),
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'runtime-lens-'.md5($app->basePath()).'.failure',
        );
    }

    private function registerInstallCommand(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }
    }

    private function recordRequests(): void
    {
        $kernel = $this->app->make(HttpKernel::class);

        if ($kernel instanceof FoundationHttpKernel) {
            $kernel->prependMiddleware(OpenBatchMiddleware::class);
        }

        $this->app->terminating(function (): void {
            $this->closeRequestBatch($this->app);
        });

        if (! $this->app->runningInConsole()) {
            $this->closeRequestBatchOnShutdown();
        }
    }

    private function closeRequestBatch(Application $app): void
    {
        $app->make(FailureReporter::class)->guard(function () use ($app): void {
            $app->make(UnitOfWork::class)->close(BatchKind::Request);
        });
    }

    private function closeRequestBatchOnShutdown(): void
    {
        $application = WeakReference::create($this->app);

        register_shutdown_function(static function () use ($application): void {
            $app = $application->get();

            if ($app === null) {
                return;
            }

            try {
                $app->make(FailureReporter::class)->guard(static function () use ($app): void {
                    $app->make(UnitOfWork::class)->close(BatchKind::Request);
                });
            } catch (Throwable) {
            }
        });
    }

    private function recordEvents(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, [QueryRecorder::class, 'record']);
        $events->listen(ResponseReceived::class, [HttpClientRecorder::class, 'recordResponse']);
        $events->listen(ConnectionFailed::class, [HttpClientRecorder::class, 'recordFailure']);
        $events->listen(JobProcessing::class, [JobLifecycle::class, 'processing']);
        $events->listen(JobProcessed::class, [JobLifecycle::class, 'processed']);
        $events->listen([JobFailed::class, JobExceptionOccurred::class], [JobLifecycle::class, 'failed']);
        $events->listen(CommandStarting::class, [CommandLifecycle::class, 'starting']);
        $events->listen(CommandFinished::class, [CommandLifecycle::class, 'finished']);
    }

    private function recordReportedExceptions(ExceptionHandler $handler): void
    {
        if (! $handler instanceof Handler) {
            return;
        }

        $handler->reportable(function (Throwable $exception): void {
            $this->app->make(ExceptionRecorder::class)->record($exception);
        });
    }

    private function captureArtisan(): void
    {
        Artisan::starting(function (Artisan $artisan): void {
            $this->app->make(ArtisanCommands::class)->capture($artisan);
        });
    }
}
