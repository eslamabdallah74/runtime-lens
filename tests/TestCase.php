<?php

namespace RuntimeLens\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionProperty;
use RuntimeLens\RuntimeLensServiceProvider;
use RuntimeLens\Support\CallerResolver;
use RuntimeLens\Support\FailureReporter;
use RuntimeLens\Support\SourceLines;
use RuntimeLens\Tests\Fixtures\App\Http\Middleware\PassThroughMiddleware;

abstract class TestCase extends Orchestra
{
    protected string $storageDirectory = '';

    public static function fixturesPath(string $path = ''): string
    {
        return __DIR__.'/Fixtures'.($path === '' ? '' : '/'.$path);
    }

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir().'/runtime-lens-tests/'.bin2hex(random_bytes(6));
        mkdir($this->storageDirectory.'/framework/views', 0777, true);
        mkdir($this->storageDirectory.'/logs', 0777, true);
        (new ReflectionProperty(FailureReporter::class, 'failureReported'))->setValue(null, false);

        parent::setUp();

        @unlink($this->failureMarkerFile());
    }

    protected function getPackageProviders($app): array
    {
        return [RuntimeLensServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->useStoragePath($this->storageDirectory);

        $config = $app['config'];
        $config->set('runtime-lens.record_tests', true);
        $config->set('view.paths', [self::fixturesPath('resources/views')]);
        $config->set('view.compiled', $this->storageDirectory.'/framework/views');
        $config->set('queue.failed.driver', 'null');
        $config->set('logging.default', 'single');
        $config->set('logging.channels.single.path', $this->storageDirectory.'/logs/laravel.log');
        $this->useMySqlWhenRequested($config);

        $app->extend(CallerResolver::class, fn (CallerResolver $resolver, Application $app): CallerResolver => new CallerResolver(
            self::fixturesPath(),
            $this->storageDirectory.'/framework/views',
            $app->make(SourceLines::class),
        ));
    }

    protected function defineRoutes($router): void
    {
        require self::fixturesPath('routes/fixture-routes.php');

        $router->middleware(PassThroughMiddleware::class)->get('/outside-app', function (ConnectionInterface $db): string {
            $db->select('select 1 as outside_app');

            return 'ok';
        });
    }

    protected function batches(): array
    {
        $file = $this->storageDirectory.'/runtime-lens/batches.jsonl';

        if (! is_file($file)) {
            return [];
        }

        return array_map(
            fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", file_get_contents($file)))),
        );
    }

    protected function lastBatch(): array
    {
        $batches = $this->batches();

        return end($batches) ?: [];
    }

    protected function appQueries(array $batch): array
    {
        return array_values(array_filter($batch['queries'], fn (array $query): bool => $query['file'] !== null));
    }

    protected function fixtureLine(string $relativeFile, string $needle): int
    {
        foreach (file(self::fixturesPath($relativeFile)) as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index + 1;
            }
        }

        $this->fail("'{$needle}' not found in {$relativeFile}");
    }

    protected function failureMarkerFile(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'runtime-lens-'.md5($this->app->basePath()).'.failure';
    }

    private function useMySqlWhenRequested(Repository $config): void
    {
        if (getenv('DB_CONNECTION') !== 'mysql') {
            return;
        }

        $config->set('database.default', 'mysql');
        $config->set('database.connections.mysql.database', getenv('DB_DATABASE') ?: 'runtime_lens_package_testing');
        $config->set('database.connections.mysql.username', getenv('DB_USERNAME') ?: 'root');
        $config->set('database.connections.mysql.password', getenv('DB_PASSWORD') ?: '');
        $config->set('database.connections.mysql.host', getenv('DB_HOST') ?: '127.0.0.1');
    }
}
