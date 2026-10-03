<?php

namespace RuntimeLens\Http;

use Illuminate\Http\Request;

final class RequestNamer
{
    public function nameFor(Request $request): string
    {
        $name = $request->getMethod().' /'.ltrim($request->path(), '/');
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
