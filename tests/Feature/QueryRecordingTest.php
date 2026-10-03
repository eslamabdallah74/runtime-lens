<?php

it('attributes each query to the app line that ran it, with the code snippet', function (): void {
    $this->get('/clean');

    $query = $this->appQueries($this->lastBatch())[0];

    expect($query)
        ->sql->toBe('select 1')
        ->connection->toBeString()
        ->fp->toMatch('/^[0-9a-f]{12}$/')
        ->file->toBe('app/Http/Controllers/FixtureController.php')
        ->line->toBe($this->fixtureLine('app/Http/Controllers/FixtureController.php', "select('select 1');"))
        ->snippet->toBe("\$this->db->select('select 1');")
        ->and($query['ms'])->toBeFloat();
});

it('gives repeated queries from one line the same fingerprint and line', function (): void {
    $this->get('/repeated');

    $queries = $this->appQueries($this->lastBatch());

    expect($queries)->toHaveCount(5)
        ->and(array_unique(array_column($queries, 'fp')))->toHaveCount(1)
        ->and(array_unique(array_column($queries, 'line')))->toHaveCount(1)
        ->and(array_column($queries, 'bindings'))->toBe([[1], [2], [3], [4], [5]]);
});

it('gives IN lists of different lengths the same fingerprint', function (): void {
    $this->get('/in-lists');

    $queries = $this->appQueries($this->lastBatch());

    expect($queries[0]['fp'])->toBe($queries[1]['fp']);
});

it('stops storing queries at the cap and counts the rest', function (): void {
    config(['runtime-lens.max_queries_per_batch' => 5]);

    $this->get('/many-queries');

    $batch = $this->lastBatch();

    expect($batch['queries'])->toHaveCount(5)
        ->and($batch['dropped_queries'])->toBeGreaterThanOrEqual(7);
});

it('caps huge IN lists without errors and keeps a stable fingerprint', function (): void {
    $this->get('/huge-in-list')->assertOk();
    $this->get('/in-lists');

    $huge = $this->appQueries($this->batches()[0])[0];
    $small = $this->appQueries($this->batches()[1])[0];

    expect(mb_strlen($huge['sql']))->toBeLessThanOrEqual(10001)
        ->and($huge['sql'])->toEndWith('…')
        ->and($huge['bindings'])->toHaveCount(101)
        ->and(end($huge['bindings']))->toBe('(+29900 more)')
        ->and($huge['fp'])->toBe($small['fp']);
});

it('still writes the batch when a binding is not valid UTF-8', function (): void {
    $this->get('/binary-binding')->assertOk();

    $query = $this->appQueries($this->lastBatch())[0];

    expect($query['bindings'][0])->toBe("\u{FFFD}1");
});

it('leaves bindings out entirely when capture_bindings is off', function (): void {
    config(['runtime-lens.capture_bindings' => false]);

    $this->get('/clean');

    expect($this->appQueries($this->lastBatch())[0])->not->toHaveKey('bindings');
});

it('maps queries from a Blade view to the view file without a line', function (): void {
    $this->get('/view')->assertOk();

    $queries = $this->appQueries($this->lastBatch());

    expect($queries)->toHaveCount(3)
        ->and(array_unique(array_column($queries, 'file')))->toBe(['resources/views/loop.blade.php'])
        ->and(array_unique(array_column($queries, 'line')))->toBe([null]);
});

it('does not blame the middleware pass-through line for queries started outside app code', function (): void {
    $this->get('/outside-app')->assertOk();

    $query = collect($this->lastBatch()['queries'])->firstWhere('sql', 'select 1 as outside_app');

    expect($query['file'])->toBeNull()->and($query['line'])->toBeNull();
});

it('does not record queries that run outside any request, job or command', function (): void {
    app('db')->select('select 1');

    expect($this->batches())->toBe([]);
});
