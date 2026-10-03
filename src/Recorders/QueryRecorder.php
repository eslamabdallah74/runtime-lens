<?php

namespace RuntimeLens\Recorders;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Events\QueryExecuted;
use RuntimeLens\Batch\UnitOfWork;
use RuntimeLens\Support\CallerResolver;
use RuntimeLens\Support\FailureReporter;
use RuntimeLens\Support\QueryFingerprint;

final class QueryRecorder
{
    private const SQL_LENGTH = 10000;

    private const BINDING_COUNT = 100;

    private const BINDING_LENGTH = 200;

    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        private readonly CallerResolver $callerResolver,
        private readonly Repository $config,
        private readonly FailureReporter $failureReporter,
    ) {
    }

    public function record(QueryExecuted $event): void
    {
        $this->failureReporter->guard(function () use ($event): void {
            $this->recordInOpenBatch($event);
        });
    }

    private function recordInOpenBatch(QueryExecuted $event): void
    {
        $batch = $this->unitOfWork->current();

        if ($batch === null) {
            return;
        }

        if (! $batch->hasRoomForQuery((int) $this->config->get('runtime-lens.max_queries_per_batch'))) {
            $batch->countDroppedQuery();

            return;
        }

        $batch->recordQuery($this->queryEntry($event));
    }

    private function queryEntry(QueryExecuted $event): array
    {
        $entry = ['sql' => $this->truncated($event->sql, self::SQL_LENGTH)];

        if ($this->config->get('runtime-lens.capture_bindings')) {
            $entry['bindings'] = $this->normalizedBindings($event->bindings);
        }

        $entry['ms'] = round($event->time, 2);
        $entry['connection'] = $event->connectionName;
        $entry['fp'] = QueryFingerprint::of($event->sql);

        return $entry + $this->callerResolver->fromBacktrace()->toArray();
    }

    private function truncated(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        $cut = mb_check_encoding($value, 'UTF-8') ? mb_substr($value, 0, $length) : substr($value, 0, $length);

        return $cut === $value ? $value : $cut.'…';
    }

    private function normalizedBindings(array $bindings): array
    {
        $kept = array_map(fn (mixed $binding): mixed => $this->normalizedBinding($binding), array_slice($bindings, 0, self::BINDING_COUNT));
        $omitted = count($bindings) - count($kept);

        return $omitted > 0 ? [...$kept, "(+{$omitted} more)"] : $kept;
    }

    private function normalizedBinding(mixed $binding): mixed
    {
        return match (true) {
            is_string($binding) => $this->truncatedBinding($binding),
            $binding instanceof DateTimeInterface => $binding->format('Y-m-d H:i:s'),
            is_bool($binding) => (int) $binding,
            is_float($binding) && ! is_finite($binding) => (string) $binding,
            is_resource($binding) || gettype($binding) === 'resource (closed)' => 'resource',
            is_object($binding) => $binding::class,
            default => $binding,
        };
    }

    private function truncatedBinding(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8')
            ? mb_substr($value, 0, self::BINDING_LENGTH)
            : substr($value, 0, self::BINDING_LENGTH);
    }
}
