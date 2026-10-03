<?php

namespace RuntimeLens\Support;

final readonly class EntryPoint
{
    public function __construct(
        public string $file,
        public int $line,
        public string $function,
    ) {
    }

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'function' => $this->function,
        ];
    }
}
