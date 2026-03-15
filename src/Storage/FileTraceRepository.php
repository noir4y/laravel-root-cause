<?php

namespace LaravelRootCause\Storage;

use Illuminate\Filesystem\Filesystem;
use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Support\ValueNormalizer;

class FileTraceRepository implements TraceRepository
{
    protected int $lastSavedAt = 0;

    public function __construct(
        protected Filesystem $files,
        protected string $directory,
    ) {}

    public function save(TraceEnvelope $trace): void
    {
        $this->files->ensureDirectoryExists($this->directory);
        $payload = $trace->toArray();
        $payload['_meta'] = [
            'saved_at_us' => $this->nextSavedAt(),
        ];

        $this->files->put(
            $this->pathFor($trace->traceId),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    public function find(string $traceId): ?TraceEnvelope
    {
        $path = $this->pathFor($traceId);

        if (! $this->files->exists($path)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);

        return TraceEnvelope::fromArray($decoded);
    }

    public function latest(): ?TraceEnvelope
    {
        foreach ($this->traceFiles() as $path) {
            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);

                return TraceEnvelope::fromArray($decoded);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return array<int, TraceEnvelope>
     */
    public function recent(int $limit = 20): array
    {
        return array_values(array_filter(array_map(function (string $path): ?TraceEnvelope {
            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);

                return TraceEnvelope::fromArray($decoded);
            } catch (\Throwable) {
                return null;
            }
        }, array_slice($this->traceFiles(), 0, $limit))));
    }

    protected function pathFor(string $traceId): string
    {
        return rtrim($this->directory, '/').DIRECTORY_SEPARATOR.$traceId.'.json';
    }

    /**
     * @return array<int, string>
     */
    protected function traceFiles(): array
    {
        $files = glob(rtrim($this->directory, '/').DIRECTORY_SEPARATOR.'*.json') ?: [];

        usort($files, function (string $left, string $right): int {
            return [
                $this->savedAt($right),
                $this->modifiedAt($right),
                basename($right),
            ] <=> [
                $this->savedAt($left),
                $this->modifiedAt($left),
                basename($left),
            ];
        });

        return $files;
    }

    protected function nextSavedAt(): int
    {
        $now = (int) round(microtime(true) * 1_000_000);
        $this->lastSavedAt = max($this->lastSavedAt + 1, $now);

        return $this->lastSavedAt;
    }

    protected function savedAt(string $path): int
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return 0;
        }

        return ValueNormalizer::int(
            ValueNormalizer::assoc($decoded['_meta'] ?? [])['saved_at_us'] ?? null
        );
    }

    protected function modifiedAt(string $path): int
    {
        $mtime = filemtime($path);

        return is_int($mtime) ? $mtime : 0;
    }
}
