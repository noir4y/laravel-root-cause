<?php

namespace LaravelRootCause\Support;

use Throwable;

class StackFrameResolver
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function applicationFramesFromThrowable(Throwable $throwable, int $limit = 8): array
    {
        $frames = [];

        foreach ($throwable->getTrace() as $frame) {
            $file = ValueNormalizer::nullableString($frame['file'] ?? null);

            if (! is_string($file) || ! $this->isApplicationFrame($file)) {
                continue;
            }

            $frames[] = $this->normalizeFrame($frame);

            if (count($frames) >= $limit) {
                break;
            }
        }

        if ($frames === [] && $throwable->getFile() && $this->isApplicationFrame($throwable->getFile())) {
            $frames[] = [
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'class' => null,
                'function' => null,
            ];
        }

        return $frames;
    }

    /**
     * @param  array<int, array<string, mixed>>  $trace
     * @return array{file: string, line: int, class: string|null, function: string|null}|null
     */
    public function firstApplicationFrameFromTrace(array $trace): ?array
    {
        foreach ($trace as $frame) {
            $file = ValueNormalizer::nullableString($frame['file'] ?? null);

            if (! is_string($file) || ! $this->isApplicationFrame($file)) {
                continue;
            }

            return $this->normalizeFrame($frame);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $frame
     * @return array{file: string, line: int, class: string|null, function: string|null}
     */
    protected function normalizeFrame(array $frame): array
    {
        return [
            'file' => ValueNormalizer::string($frame['file'] ?? null),
            'line' => ValueNormalizer::int($frame['line'] ?? null),
            'class' => ValueNormalizer::nullableString($frame['class'] ?? null),
            'function' => ValueNormalizer::nullableString($frame['function'] ?? null),
        ];
    }

    protected function isApplicationFrame(string $file): bool
    {
        $basePath = function_exists('base_path') ? base_path() : getcwd();

        if (! str_starts_with($file, (string) $basePath)) {
            return false;
        }

        return ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR);
    }
}
