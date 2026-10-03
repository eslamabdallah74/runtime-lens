<?php

namespace RuntimeLens\Support;

final class SourceLines
{
    private const SNIPPET_LENGTH = 200;

    private array $linesByFile = [];

    private array $bladeSourceByCompiledFile = [];

    public function __construct(private readonly float $codeLoadedAt)
    {
    }

    public function snippetAt(string $absoluteFile, int $line): ?string
    {
        $lines = $this->linesOf($absoluteFile);

        if ($lines === null || ! isset($lines[$line - 1])) {
            return null;
        }

        return mb_substr(trim($lines[$line - 1]), 0, self::SNIPPET_LENGTH);
    }

    public function bladeSourceFor(string $compiledFile): ?string
    {
        if (! array_key_exists($compiledFile, $this->bladeSourceByCompiledFile)) {
            $this->bladeSourceByCompiledFile[$compiledFile] = $this->bladePathMarkerIn($compiledFile);
        }

        return $this->bladeSourceByCompiledFile[$compiledFile];
    }

    public function forget(): void
    {
        $this->linesByFile = [];
    }

    private function linesOf(string $absoluteFile): ?array
    {
        if (! array_key_exists($absoluteFile, $this->linesByFile)) {
            $this->linesByFile[$absoluteFile] = $this->linesUnchangedSinceCodeLoaded($absoluteFile);
        }

        return $this->linesByFile[$absoluteFile];
    }

    private function linesUnchangedSinceCodeLoaded(string $absoluteFile): ?array
    {
        if (! is_file($absoluteFile) || $this->changedSinceCodeLoaded($absoluteFile)) {
            return null;
        }

        $lines = file($absoluteFile, FILE_IGNORE_NEW_LINES);

        return $lines === false ? null : $lines;
    }

    private function changedSinceCodeLoaded(string $absoluteFile): bool
    {
        clearstatcache(true, $absoluteFile);
        $modifiedAt = filemtime($absoluteFile);

        return $modifiedAt !== false && $modifiedAt > $this->codeLoadedAt;
    }

    private function bladePathMarkerIn(string $compiledFile): ?string
    {
        $contents = is_file($compiledFile) ? file_get_contents($compiledFile) : false;

        if ($contents === false || ! preg_match('/\/\*\*PATH\s+(.+?)\s+ENDPATH\*\*\//', $contents, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
