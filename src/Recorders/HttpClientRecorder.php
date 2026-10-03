<?php

namespace RuntimeLens\Recorders;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use RuntimeLens\Batch\UnitOfWork;
use RuntimeLens\Support\CallerResolver;
use RuntimeLens\Support\FailureReporter;

final class HttpClientRecorder
{
    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        private readonly CallerResolver $callerResolver,
        private readonly FailureReporter $failureReporter,
    ) {
    }

    public function recordResponse(ResponseReceived $event): void
    {
        $this->failureReporter->guard(function () use ($event): void {
            $this->recordCall($event->request, $event->response->status(), $this->durationOf($event->response), false);
        });
    }

    public function recordFailure(ConnectionFailed $event): void
    {
        $this->failureReporter->guard(function () use ($event): void {
            $this->recordCall($event->request, null, null, true);
        });
    }

    private function recordCall(Request $request, ?int $status, ?float $milliseconds, bool $failed): void
    {
        $batch = $this->unitOfWork->current();

        if ($batch === null) {
            return;
        }

        $batch->recordHttpCall([
            'method' => $request->method(),
            'url' => $this->urlWithoutQuery($request->url()),
            'status' => $status,
            'ms' => $milliseconds,
            'failed' => $failed,
        ] + $this->callerResolver->fromBacktrace()->toArray());
    }

    private function durationOf(Response $response): ?float
    {
        $totalSeconds = $response->handlerStats()['total_time'] ?? null;

        return is_numeric($totalSeconds) ? round($totalSeconds * 1000, 1) : null;
    }

    private function urlWithoutQuery(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return explode('?', $url, 2)[0];
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'http').'://'.$parts['host'].$port.($parts['path'] ?? '');
    }
}
