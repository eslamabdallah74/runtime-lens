<?php

namespace RuntimeLens\Batch;

use RuntimeException;
use Throwable;

final class BatchWriter
{
    private const CURRENT_FILE = 'batches.jsonl';

    private const ROTATED_FILE = 'batches.1.jsonl';

    private const LOCK_FILE = '.lock';

    private const GITIGNORE = "*\n";

    public function __construct(
        private readonly string $directory,
        private readonly int $maxFileBytes,
    ) {
    }

    public function write(array $batch): void
    {
        $line = $this->encode($batch)."\n";

        $this->ensureDirectory();

        $lock = $this->acquireLock();

        try {
            $this->rotateWhenFull();
            $this->append($line);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function encode(array $batch): string
    {
        return json_encode(
            $batch,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory) && ! $this->createDirectory()) {
            throw new RuntimeException('Runtime Lens could not create '.$this->directory);
        }

        $gitignore = $this->pathTo('.gitignore');

        if (! file_exists($gitignore) || file_get_contents($gitignore) !== self::GITIGNORE) {
            file_put_contents($gitignore, self::GITIGNORE);
        }
    }

    private function createDirectory(): bool
    {
        try {
            $created = mkdir($this->directory, 0775, true);
        } catch (Throwable) {
            $created = false;
        }

        return $created || is_dir($this->directory);
    }

    private function acquireLock(): mixed
    {
        $lock = fopen($this->pathTo(self::LOCK_FILE), 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Runtime Lens could not lock '.$this->pathTo(self::LOCK_FILE));
        }

        return $lock;
    }

    private function rotateWhenFull(): void
    {
        $current = $this->pathTo(self::CURRENT_FILE);

        clearstatcache(true, $current);

        if (! is_file($current) || filesize($current) <= $this->maxFileBytes) {
            return;
        }

        if (! rename($current, $this->pathTo(self::ROTATED_FILE))) {
            throw new RuntimeException('Runtime Lens could not rotate '.$current);
        }
    }

    private function append(string $line): void
    {
        if (file_put_contents($this->pathTo(self::CURRENT_FILE), $line, FILE_APPEND) === false) {
            throw new RuntimeException('Runtime Lens could not append to '.$this->pathTo(self::CURRENT_FILE));
        }
    }

    private function pathTo(string $file): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$file;
    }
}
