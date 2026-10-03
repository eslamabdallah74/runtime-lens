<?php

it('records reported exceptions at the line that threw them', function (): void {
    $this->get('/boom')->assertStatus(500);

    expect($this->lastBatch())
        ->status->toBe(500)
        ->and($this->lastBatch()['exceptions'])->toHaveCount(1)
        ->and($this->lastBatch()['exceptions'][0])
        ->class->toBe(RuntimeException::class)
        ->message->toBe('Fixture boom')
        ->file->toBe('app/Http/Controllers/FixtureController.php')
        ->line->toBe($this->fixtureLine('app/Http/Controllers/FixtureController.php', "Fixture boom"));
});

it('does not record exceptions Laravel does not report, such as 404s', function (): void {
    $this->get('/not-found')->assertNotFound();

    expect($this->lastBatch())->status->toBe(404)->exceptions->toBe([]);
});
