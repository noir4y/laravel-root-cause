<?php

namespace LaravelRootCause\Redaction;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use LaravelRootCause\Support\ValueNormalizer;
use Throwable;

class Redactor
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config = []) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, string>
     */
    public function sanitizeInputKeys(array $input): array
    {
        $redacted = collect(array_keys($input))
            ->reject(fn (string $key): bool => $this->isRedactedKey($key))
            ->values()
            ->all();

        return $redacted;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function inputShape(array $input): array
    {
        $shape = [];

        foreach ($input as $key => $value) {
            if ($this->isRedactedKey((string) $key)) {
                continue;
            }

            $shape[(string) $key] = $this->normalizeType($value);
        }

        return $shape;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<int, string>
     */
    public function sanitizeHeaderKeys(array $headers): array
    {
        $headerKeys = [];

        foreach (array_keys($headers) as $key) {
            $headerKeys[] = Str::lower($key);
        }

        return array_values(array_filter(
            $headerKeys,
            fn (string $key): bool => ! in_array($key, $this->redactedHeaders(), true)
        ));
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    public function sanitizeBindingsCount(array $bindings): int
    {
        return count($bindings);
    }

    public function sanitizeExceptionMessage(Throwable $throwable): string
    {
        if ($throwable instanceof QueryException && $this->shouldRedactSqlBindings()) {
            $message = 'Database query failed; SQL text redacted';

            $bindingsCount = count($throwable->getBindings());

            if ($bindingsCount > 0) {
                $message .= sprintf(' with %d bindings redacted', $bindingsCount);
            }

            return $message;
        }

        return $this->sanitizePlainExceptionMessage($throwable->getMessage());
    }

    protected function isRedactedKey(string $key): bool
    {
        return in_array(Str::lower($key), $this->redactedRequestKeys(), true);
    }

    protected function normalizeType(mixed $value): string
    {
        if (is_array($value)) {
            return 'array';
        }

        if (is_object($value)) {
            return class_basename($value);
        }

        return gettype($value);
    }

    /**
     * @return array<int, string>
     */
    protected function redactedHeaders(): array
    {
        return array_map(
            static fn (string $header): string => Str::lower($header),
            ValueNormalizer::stringList($this->config['headers'] ?? [])
        );
    }

    /**
     * @return array<int, string>
     */
    protected function redactedRequestKeys(): array
    {
        return array_map(
            static fn (string $key): string => Str::lower($key),
            ValueNormalizer::stringList($this->config['request_keys'] ?? [])
        );
    }

    protected function shouldRedactSqlBindings(): bool
    {
        return ! in_array($this->config['sql_bindings'] ?? true, [false, 0, '0'], true);
    }

    protected function sanitizePlainExceptionMessage(string $message): string
    {
        $sanitized = preg_replace(
            [
                '/\b(password|token|secret|api[_-]?key|authorization)\b\s*[:=]?\s*[^\s,;]+/i',
                '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
                '/https?:\/\/\S+/i',
                '/\b[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/',
            ],
            [
                '$1 [redacted]',
                '[redacted-email]',
                '[redacted-url]',
                '[redacted-token]',
            ],
            $message
        );

        return Str::squish($sanitized ?? $message);
    }
}
