<?php

use RuntimeLens\Batch\BatchWriter;

it('creates its folder with a .gitignore that ignores everything', function (): void {
    $this->get('/clean');

    expect(file_get_contents($this->storageDirectory.'/runtime-lens/.gitignore'))->toBe("*\n!.gitignore\n");
});

it('rotates the data file when it grows past the size limit', function (): void {
    $directory = $this->storageDirectory.'/rotation';
    $writer = new BatchWriter($directory, 10);

    $writer->write(['n' => 1]);
    $writer->write(['n' => 2]);
    $writer->write(['n' => 3]);

    expect(file_get_contents($directory.'/batches.1.jsonl'))->toBe("{\"n\":1}\n{\"n\":2}\n")
        ->and(file_get_contents($directory.'/batches.jsonl'))->toBe("{\"n\":3}\n");
});

it('keeps serving the request when the data file cannot be written, and logs once', function (): void {
    $this->get('/clean');
    chmod($this->storageDirectory.'/runtime-lens/batches.jsonl', 0444);

    $this->get('/clean')->assertOk();
    $this->get('/clean')->assertOk();

    chmod($this->storageDirectory.'/runtime-lens/batches.jsonl', 0644);

    $log = (string) @file_get_contents($this->storageDirectory.'/logs/laravel.log');

    expect(substr_count($log, 'Runtime Lens recorder failure'))->toBe(1)
        ->and(file_exists($this->failureMarkerFile()))->toBeTrue();
})->skip(fn (): bool => function_exists('posix_getuid') && posix_getuid() === 0, 'root ignores file permissions');
