<?php

namespace RuntimeLens\Profiling;

use ExcimerProfiler;
use RuntimeLens\Support\CallerResolver;
use RuntimeLens\Support\SourceLines;

final class ExcimerSampler implements Profiler
{
    private const MAX_DEPTH = 250;

    private const MAX_FUNCTIONS = 100;

    private const MAX_LINES = 200;

    private ?ExcimerProfiler $profiler = null;

    private array $functionSamples = [];

    private array $lineSamples = [];

    public function __construct(
        private readonly CallerResolver $callerResolver,
        private readonly SourceLines $sourceLines,
        private readonly float $periodMs,
    ) {
    }

    public function start(): void
    {
        $this->profiler = new ExcimerProfiler();
        $this->profiler->setPeriod($this->periodMs / 1000);
        $this->profiler->setEventType(EXCIMER_REAL);
        $this->profiler->setMaxDepth(self::MAX_DEPTH);
        $this->profiler->start();
    }

    public function stop(): ?array
    {
        if ($this->profiler === null) {
            return null;
        }

        $profiler = $this->profiler;
        $this->profiler = null;
        $profiler->stop();

        $this->aggregateSamples($profiler->getLog());

        return [
            'period_ms' => $this->periodMs,
            'functions' => $this->topFunctions(),
            'lines' => $this->topLines(),
        ];
    }

    private function aggregateSamples(iterable $log): void
    {
        $this->functionSamples = [];
        $this->lineSamples = [];

        foreach ($log as $entry) {
            $this->aggregateTrace($entry->getTrace(), $entry->getEventCount());
        }
    }

    private function aggregateTrace(array $frames, int $samples): void
    {
        $countedFunctions = [];
        $countedLines = [];
        $innermostAppFrameSeen = false;

        foreach ($frames as $frame) {
            $relativeFile = isset($frame['file']) ? $this->callerResolver->relativeAppPath($frame['file']) : null;

            if ($relativeFile === null) {
                continue;
            }

            $functionKey = $relativeFile.'|'.$this->functionNameOf($frame);

            if (! isset($countedFunctions[$functionKey])) {
                $countedFunctions[$functionKey] = true;
                $this->addFunctionSamples($functionKey, $relativeFile, $this->functionNameOf($frame), $samples, ! $innermostAppFrameSeen);
            }

            $innermostAppFrameSeen = true;

            if (! isset($frame['line'])) {
                continue;
            }

            $lineKey = $relativeFile.':'.$frame['line'];

            if (! isset($countedLines[$lineKey])) {
                $countedLines[$lineKey] = true;
                $this->addLineSamples($lineKey, $relativeFile, $frame['file'], $frame['line'], $samples);
            }
        }
    }

    private function functionNameOf(array $frame): string
    {
        $function = $frame['function'] ?? '{main}';

        if (str_starts_with($function, '{closure')) {
            $function = '{closure}';
        }

        return isset($frame['class']) ? $frame['class'].'::'.$function : $function;
    }

    private function addFunctionSamples(string $key, string $file, string $function, int $samples, bool $isSelf): void
    {
        $this->functionSamples[$key] ??= ['file' => $file, 'function' => $function, 'total' => 0, 'self' => 0];
        $this->functionSamples[$key]['total'] += $samples;

        if ($isSelf) {
            $this->functionSamples[$key]['self'] += $samples;
        }
    }

    private function addLineSamples(string $key, string $file, string $absoluteFile, int $line, int $samples): void
    {
        $this->lineSamples[$key] ??= ['file' => $file, 'absolute_file' => $absoluteFile, 'line' => $line, 'total' => 0];
        $this->lineSamples[$key]['total'] += $samples;
    }

    private function topFunctions(): array
    {
        return array_map(fn (array $function): array => [
            'file' => $function['file'],
            'function' => $function['function'],
            'total_ms' => $this->milliseconds($function['total']),
            'self_ms' => $this->milliseconds($function['self']),
        ], $this->largestByTotal($this->functionSamples, self::MAX_FUNCTIONS));
    }

    private function topLines(): array
    {
        return array_map(fn (array $line): array => [
            'file' => $line['file'],
            'line' => $line['line'],
            'snippet' => $this->sourceLines->snippetAt($line['absolute_file'], $line['line']),
            'total_ms' => $this->milliseconds($line['total']),
        ], $this->largestByTotal($this->lineSamples, self::MAX_LINES));
    }

    private function largestByTotal(array $samplesByKey, int $limit): array
    {
        $samples = array_values($samplesByKey);

        usort($samples, fn (array $left, array $right): int => $right['total'] <=> $left['total']);

        return array_slice($samples, 0, $limit);
    }

    private function milliseconds(int $samples): float
    {
        return round($samples * $this->periodMs, 1);
    }
}
