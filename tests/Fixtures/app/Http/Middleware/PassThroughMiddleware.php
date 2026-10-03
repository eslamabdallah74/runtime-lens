<?php

namespace RuntimeLens\Tests\Fixtures\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PassThroughMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
