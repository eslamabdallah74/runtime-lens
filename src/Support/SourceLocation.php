<?php

namespace RuntimeLens\Support;

final readonly class SourceLocation
{
    public function __construct(
        public ?string $file,
        public ?int $line,
        public ?string $snippet,
    ) {
    }

    public static function unknown(): self
    {
        return new self(null, null, null);
    }

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'snippet' => $this->snippet,
        ];
    }
}
