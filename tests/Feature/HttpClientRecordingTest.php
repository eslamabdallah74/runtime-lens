<?php

use Illuminate\Support\Facades\Http;

it('records outgoing HTTP calls without the query string', function (): void {
    Http::fake(['api.example.test/*' => Http::response(['ok' => true], 201)]);

    $this->get('/http-call')->assertOk();

    expect($this->lastBatch()['http'])->toHaveCount(1)
        ->and($this->lastBatch()['http'][0])
        ->method->toBe('GET')
        ->url->toBe('https://api.example.test/items')
        ->status->toBe(201)
        ->failed->toBeFalse()
        ->file->toBe('app/Http/Controllers/FixtureController.php')
        ->line->toBe($this->fixtureLine('app/Http/Controllers/FixtureController.php', 'api.example.test/items'));
});

it('never writes the query string token to disk', function (): void {
    Http::fake(['api.example.test/*' => Http::response()]);

    $this->get('/http-call');

    expect(file_get_contents($this->storageDirectory.'/runtime-lens/batches.jsonl'))->not->toContain('token=secret-token');
});

it('records connection failures as failed calls without a status', function (): void {
    $this->get('/http-failure')->assertOk()->assertSee('down');

    expect($this->lastBatch()['http'][0])
        ->failed->toBeTrue()
        ->status->toBeNull()
        ->ms->toBeNull()
        ->url->toBe('http://127.0.0.1:9/down');
});
