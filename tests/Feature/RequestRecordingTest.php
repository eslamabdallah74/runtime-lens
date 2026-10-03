<?php

it('writes one version-1 batch per request with timing, status and entry point', function (): void {
    $this->get('/clean')->assertOk();

    $batch = $this->lastBatch();

    expect($batch)
        ->v->toBe(1)
        ->source->toBe('tests')
        ->kind->toBe('request')
        ->name->toBe('GET /clean')
        ->status->toBe(200)
        ->dropped_queries->toBe(0)
        ->and($batch['id'])->toMatch('/^[0-9a-f]{10}$/')
        ->and($batch['started_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/')
        ->and($batch['duration_ms'])->toBeGreaterThan(0)
        ->and($batch['entry'])->toBe([
            'file' => 'app/Http/Controllers/FixtureController.php',
            'line' => $this->fixtureLine('app/Http/Controllers/FixtureController.php', 'function clean'),
            'function' => 'RuntimeLens\Tests\Fixtures\App\Http\Controllers\FixtureController::clean',
        ]);
});

it('writes a separate batch for every request made in one test', function (): void {
    $this->get('/clean');
    $this->get('/clean');

    expect($this->batches())->toHaveCount(2);
});

it('does not record ignored paths', function (string $path): void {
    $this->get($path);

    expect($this->batches())->toBe([]);
})->with(['/up', '/telescope/requests']);

it('names GraphQL requests after their operation', function (): void {
    $this->postJson('/graphql', ['operationName' => 'studentSubjects', 'query' => '{ x }']);

    expect($this->lastBatch()['name'])->toBe('POST /graphql (studentSubjects)');
});

it('keeps the plain name for batched, non-JSON and broken GraphQL bodies', function (string $contentType, string $body): void {
    $this->call('POST', '/graphql', server: ['CONTENT_TYPE' => $contentType], content: $body)->assertOk();

    expect($this->lastBatch()['name'])->toBe('POST /graphql');
})->with([
    'batched operations' => ['application/json', '[{"operationName":"a"}]'],
    'plain text' => ['text/plain', 'not json'],
    'broken json' => ['application/json', '{broken'],
]);

it('records the entry point of a route closure', function (): void {
    $this->get('/closure');

    expect($this->lastBatch()['entry'])
        ->file->toBe('routes/fixture-routes.php')
        ->function->toBe('{closure}');
});

it('has no entry point when the route is handled by vendor code', function (): void {
    $this->get('/vendor-redirect')->assertRedirect();

    expect($this->lastBatch())->entry->toBeNull()->status->toBe(302);
});
