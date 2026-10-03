<?php

namespace RuntimeLens\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

final class RequestNamer
{
    public function nameFor(Request $request): string
    {
        return $this->withOperationName($request, $request->getMethod().' /'.ltrim($request->path(), '/'));
    }

    public function groupFor(Request $request, Route $route): string
    {
        return $this->withOperationName($request, $request->getMethod().' /'.ltrim($route->uri(), '/'));
    }

    private function withOperationName(Request $request, string $name): string
    {
        $operationName = $this->graphQlOperationName($request);

        return $operationName === null ? $name : "{$name} ({$operationName})";
    }

    private function graphQlOperationName(Request $request): ?string
    {
        if (! $request->isJson()) {
            return null;
        }

        $operationName = $request->json('operationName');

        return is_string($operationName) && $operationName !== '' ? $operationName : null;
    }
}
