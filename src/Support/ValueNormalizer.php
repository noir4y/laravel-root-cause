<?php

namespace LaravelRootCause\Support;

class ValueNormalizer
{
    public static function string(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    public static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return $default;
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    public static function assoc(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }

    /**
     * @return array<int, mixed>
     */
    public static function mixedList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values($value);
    }

    /**
     * @return array<int, string>
     */
    public static function stringList(mixed $value): array
    {
        $normalized = [];

        foreach (self::mixedList($value) as $item) {
            if (is_string($item)) {
                $normalized[] = $item;

                continue;
            }

            if (is_int($item) || is_float($item)) {
                $normalized[] = (string) $item;
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listOfAssoc(mixed $value): array
    {
        $normalized = [];

        foreach (self::mixedList($value) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized[] = self::assoc($item);
        }

        return $normalized;
    }
}
