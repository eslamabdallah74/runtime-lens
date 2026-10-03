<?php

namespace RuntimeLens\Batch;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeLens\Enums\BatchKind;
use RuntimeLens\Enums\RecordingMode;

final class Batch
{
    private const MAX_HTTP_CALLS = 200;

    public int|string|null $status = null;

    public ?array $entry = null;

    public array $queries = [];

    public array $http = [];

    public array $exceptions = [];

    public int $droppedQueries = 0;

    public ?array $profile = null;

    public function __construct(
        public readonly string $id,
        public readonly RecordingMode $source,
        public readonly BatchKind $kind,
        public readonly string $name,
        public readonly float $startedAt,
    ) {
    }

    public function hasRoomForQuery(int $limit): bool
    {
        return count($this->queries) < $limit;
    }

    public function recordQuery(array $query): void
    {
        $this->queries[] = $query;
    }

    public function countDroppedQuery(): void
    {
        $this->droppedQueries++;
    }

    public function recordHttpCall(array $call): void
    {
        if (count($this->http) >= self::MAX_HTTP_CALLS) {
            return;
        }

        $this->http[] = $call;
    }

    public function recordException(array $exception): void
    {
        $this->exceptions[] = $exception;
    }

    public function toArray(float $finishedAt): array
    {
        $batch = [
            'v' => 1,
            'id' => $this->id,
            'source' => $this->source->value,
            'kind' => $this->kind->value,
            'name' => $this->name,
            'status' => $this->status,
            'started_at' => $this->formattedStart(),
            'duration_ms' => round(($finishedAt - $this->startedAt) * 1000, 1),
            'entry' => $this->entry,
            'dropped_queries' => $this->droppedQueries,
            'queries' => $this->queries,
            'http' => $this->http,
            'exceptions' => $this->exceptions,
        ];

        if ($this->profile !== null) {
            $batch['profile'] = $this->profile;
        }

        return $batch;
    }

    private function formattedStart(): string
    {
        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $this->startedAt))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
