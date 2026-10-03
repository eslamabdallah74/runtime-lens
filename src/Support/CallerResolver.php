<?php

namespace RuntimeLens\Support;

final class CallerResolver
{
    private const BACKTRACE_DEPTH = 50;

    private const NON_APP_DIRECTORIES = ['vendor/', 'tests/'];

    private const NON_APP_ENTRY_FILES = ['public/index.php', 'artisan'];

    private const PIPELINE_CLASS_SUFFIX = '\\Pipeline';

    private readonly string $basePath;

    private readonly string $compiledViewPath;

    private readonly string $packagePath;

    private array $realPathByFile = [];

    private array $relativePathByFile = [];

    public function __construct(
        string $basePath,
        string $compiledViewPath,
        private readonly SourceLines $sourceLines,
    ) {
        $this->basePath = $this->directoryPrefix($basePath);
        $this->compiledViewPath = $this->directoryPrefix($compiledViewPath);
        $this->packagePath = $this->directoryPrefix(dirname(__DIR__));
    }

    public function fromBacktrace(): SourceLocation
    {
        return $this->firstAppLocation(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_DEPTH));
    }

    public function firstAppLocation(array $frames): SourceLocation
    {
        foreach ($frames as $frame) {
            if ($this->callsIntoPipeline($frame)) {
                continue;
            }

            $location = $this->locate($frame['file'] ?? '', $frame['line'] ?? 0);

            if ($location !== null) {
                return $location;
            }
        }

        return SourceLocation::unknown();
    }

    public function locate(string $absoluteFile, int $line): ?SourceLocation
    {
        if ($absoluteFile === '') {
            return null;
        }

        if ($this->isCompiledView($absoluteFile)) {
            return $this->bladeSourceLocation($absoluteFile);
        }

        $relativeFile = $this->relativeAppPath($absoluteFile);

        if ($relativeFile === null) {
            return null;
        }

        return new SourceLocation($relativeFile, $line, $this->sourceLines->snippetAt($absoluteFile, $line));
    }

    public function isAppFile(string $absoluteFile): bool
    {
        return $this->relativeAppPath($absoluteFile) !== null;
    }

    public function relativeAppPath(string $absoluteFile): ?string
    {
        if (! array_key_exists($absoluteFile, $this->relativePathByFile)) {
            $this->relativePathByFile[$absoluteFile] = $this->appRelativePathOf($this->realPathOf($absoluteFile));
        }

        return $this->relativePathByFile[$absoluteFile];
    }

    private function directoryPrefix(string $directory): string
    {
        if ($directory === '') {
            return '';
        }

        return rtrim(realpath($directory) ?: $directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    }

    private function callsIntoPipeline(array $frame): bool
    {
        return str_ends_with($frame['class'] ?? '', self::PIPELINE_CLASS_SUFFIX);
    }

    private function isCompiledView(string $absoluteFile): bool
    {
        return $this->startsWithDirectory($this->realPathOf($absoluteFile), $this->compiledViewPath);
    }

    private function startsWithDirectory(string $file, string $directory): bool
    {
        return $directory !== '' && str_starts_with($file, $directory);
    }

    private function realPathOf(string $absoluteFile): string
    {
        if (! array_key_exists($absoluteFile, $this->realPathByFile)) {
            $this->realPathByFile[$absoluteFile] = realpath($absoluteFile) ?: $absoluteFile;
        }

        return $this->realPathByFile[$absoluteFile];
    }

    private function bladeSourceLocation(string $compiledFile): ?SourceLocation
    {
        $bladeFile = $this->sourceLines->bladeSourceFor($compiledFile);
        $relativeBladeFile = $bladeFile === null ? null : $this->relativeAppPath($bladeFile);

        if ($relativeBladeFile === null) {
            return null;
        }

        return new SourceLocation($relativeBladeFile, null, null);
    }

    private function appRelativePathOf(string $realFile): ?string
    {
        if (! $this->startsWithDirectory($realFile, $this->basePath)
            || $this->startsWithDirectory($realFile, $this->packagePath)
            || $this->startsWithDirectory($realFile, $this->compiledViewPath)) {
            return null;
        }

        $relativeFile = str_replace(DIRECTORY_SEPARATOR, '/', substr($realFile, strlen($this->basePath)));

        return $this->isNonAppPath($relativeFile) ? null : $relativeFile;
    }

    private function isNonAppPath(string $relativeFile): bool
    {
        foreach (self::NON_APP_DIRECTORIES as $directory) {
            if (str_starts_with($relativeFile, $directory)) {
                return true;
            }
        }

        return in_array($relativeFile, self::NON_APP_ENTRY_FILES, true);
    }
}
