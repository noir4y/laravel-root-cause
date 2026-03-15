<?php

namespace LaravelRootCause\Support;

class QueryFingerprint
{
    /**
     * @return array{fingerprint: string, tables: array<int, string>}
     */
    public static function fromSql(string $sql): array
    {
        $normalized = strtolower($sql);
        $normalized = preg_replace("/'[^']*'/", '?', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b\d+\b/', '?', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?? trim($normalized);

        preg_match_all('/(?:from|join|update|into)\s+[`"]?([a-zA-Z0-9_\.]+)[`"]?/i', $sql, $matches);

        return [
            'fingerprint' => $normalized,
            'tables' => array_values(array_unique($matches[1])),
        ];
    }
}
