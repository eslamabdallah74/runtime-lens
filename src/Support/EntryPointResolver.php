<?php

namespace RuntimeLens\Support;

use Closure;
use Illuminate\Routing\Route;
use ReflectionFunction;
use ReflectionMethod;

final class EntryPointResolver
{
    public function __construct(private readonly CallerResolver $callerResolver)
    {
    }

    public function forRoute(Route $route): ?EntryPoint
    {
        $uses = $route->getAction('uses');

        if ($uses instanceof Closure) {
            return $this->forClosure($uses);
        }

        if (! is_string($uses) || ! str_contains($uses, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $uses, 2);

        return $this->forClassMethod($class, $method);
    }

    public function forClassMethod(string $class, string $method): ?EntryPoint
    {
        if (! method_exists($class, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($class, $method);

        return $this->entryPointAt($reflection->getFileName(), $reflection->getStartLine(), $reflection->getDeclaringClass()->getName().'::'.$method);
    }

    private function forClosure(Closure $closure): ?EntryPoint
    {
        $reflection = new ReflectionFunction($closure);

        return $this->entryPointAt($reflection->getFileName(), $reflection->getStartLine(), '{closure}');
    }

    private function entryPointAt(string|false $file, int|false $line, string $function): ?EntryPoint
    {
        $relativeFile = $file === false ? null : $this->callerResolver->relativeAppPath($file);

        if ($relativeFile === null || $line === false) {
            return null;
        }

        return new EntryPoint($relativeFile, $line, $function);
    }
}
