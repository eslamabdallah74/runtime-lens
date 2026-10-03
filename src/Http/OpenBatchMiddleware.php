<?php

namespace RuntimeLens\Http;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use RuntimeLens\Activation;
use RuntimeLens\Batch\UnitOfWork;
use RuntimeLens\Enums\BatchKind;
use RuntimeLens\Enums\RecordingMode;
use RuntimeLens\Support\EntryPointResolver;
use RuntimeLens\Support\FailureReporter;
use Symfony\Component\HttpFoundation\Response;

final class OpenBatchMiddleware
{
    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        private readonly RequestNamer $requestNamer,
        private readonly EntryPointResolver $entryPointResolver,
        private readonly Activation $activation,
        private readonly Repository $config,
        private readonly FailureReporter $failureReporter,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->failureReporter->guard(function () use ($request): void {
            $this->openBatchFor($request);
        });

        $response = $next($request);

        $this->failureReporter->guard(function () use ($request, $response): void {
            $this->recordResponse($request, $response);
        });

        return $response;
    }

    private function openBatchFor(Request $request): void
    {
        if ($request->is(...$this->config->get('runtime-lens.ignore_paths', []))) {
            return;
        }

        $this->unitOfWork->open(BatchKind::Request, $this->requestNamer->nameFor($request), $this->requestStartedAt());
    }

    private function requestStartedAt(): float
    {
        if ($this->activation->mode() === RecordingMode::App && defined('LARAVEL_START')) {
            return LARAVEL_START;
        }

        return microtime(true);
    }

    private function recordResponse(Request $request, Response $response): void
    {
        $batch = $this->unitOfWork->current();

        if ($batch?->kind !== BatchKind::Request) {
            return;
        }

        $batch->status = $response->getStatusCode();

        $route = $request->route();

        if ($route instanceof Route) {
            $batch->entry = $this->entryPointResolver->forRoute($route)?->toArray();
        }
    }
}
