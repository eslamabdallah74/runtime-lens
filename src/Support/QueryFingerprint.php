<?php

namespace RuntimeLens\Support;

final class QueryFingerprint
{
    public static function of(string $sql): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
        $normalized = str_replace([' ,', ', '], ',', $normalized);

        while (str_contains($normalized, '?,?')) {
            $normalized = str_replace('?,?', '?', $normalized);
        }

        return substr(sha1($normalized), 0, 12);
    }
}
