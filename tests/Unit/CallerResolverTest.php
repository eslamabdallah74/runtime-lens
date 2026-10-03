<?php

use RuntimeLens\Support\CallerResolver;
use RuntimeLens\Support\SourceLines;

beforeEach(function (): void {
    $this->project = $this->storageDirectory.'/resolver-project';
    foreach (['app', 'tests', 'vendor/acme', 'public', 'storage/framework/views', 'resources/views'] as $directory) {
        mkdir($this->project.'/'.$directory, 0777, true);
    }
    foreach (['app/Service.php', 'tests/ServiceTest.php', 'vendor/acme/Lib.php', 'public/index.php', 'artisan'] as $file) {
        file_put_contents($this->project.'/'.$file, "<?php\n\$value = 1;\n");
    }
    file_put_contents($this->project.'/resources/views/page.blade.php', '{{ 1 }}');
    file_put_contents($this->project.'/storage/framework/views/abc123.php', "<?php echo 1; ?>\n<?php /**PATH {$this->project}/resources/views/page.blade.php ENDPATH**/ ?>");
});

function resolverFor(string $project, string $compiledViews = 'storage/framework/views'): CallerResolver
{
    return new CallerResolver($project, $compiledViews === '' ? '' : $project.'/'.$compiledViews, new SourceLines(microtime(true) + 60));
}

it('treats app code as app code and everything else as not', function (string $file, ?string $expected): void {
    expect(resolverFor($this->project)->relativeAppPath($this->project.'/'.$file))->toBe($expected);
})->with([
    'app file' => ['app/Service.php', 'app/Service.php'],
    'tests are not app code' => ['tests/ServiceTest.php', null],
    'vendor is not app code' => ['vendor/acme/Lib.php', null],
    'front controller' => ['public/index.php', null],
    'artisan' => ['artisan', null],
]);

it('maps a compiled Blade view back to its source file without a line', function (): void {
    $location = resolverFor($this->project)->locate($this->project.'/storage/framework/views/abc123.php', 1);

    expect($location?->toArray())->toBe(['file' => 'resources/views/page.blade.php', 'line' => null, 'snippet' => null]);
});

it('still resolves app files when the compiled view path is empty', function (): void {
    expect(resolverFor($this->project, '')->relativeAppPath($this->project.'/app/Service.php'))->toBe('app/Service.php');
});

it('resolves app files reached through a symlinked project path', function (): void {
    $link = $this->storageDirectory.'/linked-project';
    symlink($this->project, $link);

    expect(resolverFor($link)->relativeAppPath($link.'/app/Service.php'))->toBe('app/Service.php');
});

it('skips frames that only pass the request on through a middleware pipeline', function (): void {
    $frames = [
        ['file' => $this->project.'/vendor/acme/Lib.php', 'line' => 2],
        ['file' => $this->project.'/app/Service.php', 'line' => 2, 'class' => 'Illuminate\Pipeline\Pipeline', 'function' => '{closure}'],
        ['file' => $this->project.'/app/Service.php', 'line' => 2, 'class' => 'App\Other', 'function' => 'run'],
    ];

    expect(resolverFor($this->project)->firstAppLocation(array_slice($frames, 0, 2))->file)->toBeNull()
        ->and(resolverFor($this->project)->firstAppLocation($frames)->file)->toBe('app/Service.php');
});
