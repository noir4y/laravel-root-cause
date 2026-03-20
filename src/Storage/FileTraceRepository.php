<?php

namespace LaravelRootCause\Storage;

use DateTimeInterface;
use Illuminate\Filesystem\Filesystem;
use LaravelRootCause\Contracts\PrunableTraceRepository;
use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Support\ValueNormalizer;

class FileTraceRepository implements PrunableTraceRepository, TraceRepository
{
    protected const META_REPOSITORY = 'laravel-root-cause';

    protected int $lastSavedAt = 0;

    public function __construct(
        protected Filesystem $files,
        protected string $directory,
    ) {}

    public function save(TraceEnvelope $trace): void
    {
        $this->files->ensureDirectoryExists($this->directory);
        $this->files->ensureDirectoryExists($this->temporaryDirectory());
        $payload = $trace->toArray();
        $payload['_meta'] = [
            'saved_at_us' => $this->nextSavedAt(),
            'repository' => self::META_REPOSITORY,
        ];
        $path = $this->pathFor($trace->traceId);
        $temporaryPath = $this->temporaryPathFor($trace->traceId);

        $this->files->put(
            $temporaryPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            true
        );

        if (! @rename($temporaryPath, $path)) {
            $this->files->delete($temporaryPath);

            throw new \RuntimeException(sprintf('Unable to persist trace [%s].', $trace->traceId));
        }
    }

    public function find(string $traceId): ?TraceEnvelope
    {
        return $this->readTrace($this->pathFor($traceId));
    }

    public function latest(): ?TraceEnvelope
    {
        foreach ($this->traceFiles() as $path) {
            $trace = $this->readTrace($path);

            if ($trace instanceof TraceEnvelope) {
                return $trace;
            }
        }

        return null;
    }

    /**
     * @return array<int, TraceEnvelope>
     */
    public function recent(int $limit = 20): array
    {
        return array_values(array_filter(array_map(
            fn (string $path): ?TraceEnvelope => $this->readTrace($path),
            array_slice($this->traceFiles(), 0, $limit)
        )));
    }

    public function pruneOlderThan(DateTimeInterface $threshold): int
    {
        $deleted = 0;
        $thresholdUs = $threshold->getTimestamp() * 1_000_000;

        foreach ($this->prunableTraceFiles() as $path) {
            if ($this->ageReferenceUs($path) >= $thresholdUs) {
                continue;
            }

            if ($this->files->delete($path)) {
                $deleted++;
            }
        }

        return $deleted + $this->pruneTemporaryTraceFilesOlderThan($thresholdUs);
    }

    protected function pathFor(string $traceId): string
    {
        return rtrim($this->directory, '/').DIRECTORY_SEPARATOR.$traceId.'.json';
    }

    protected function temporaryPathFor(string $traceId): string
    {
        return sprintf(
            '%s%s%s.json.tmp.%s',
            $this->temporaryDirectory(),
            DIRECTORY_SEPARATOR,
            $traceId,
            bin2hex(random_bytes(6))
        );
    }

    protected function temporaryDirectory(): string
    {
        return rtrim($this->directory, '/').DIRECTORY_SEPARATOR.'.tmp';
    }

    /**
     * @return array<int, string>
     */
    protected function temporaryTraceFiles(): array
    {
        $paths = [
            ...($this->temporaryDirectoryExists() ? glob($this->temporaryDirectory().DIRECTORY_SEPARATOR.'trc_*.json.tmp.*') ?: [] : []),
            ...(glob(rtrim($this->directory, '/').DIRECTORY_SEPARATOR.'trc_*.json.tmp.*') ?: []),
        ];

        return array_values(array_unique($paths));
    }

    protected function temporaryDirectoryExists(): bool
    {
        return $this->files->isDirectory($this->temporaryDirectory());
    }

    protected function pruneTemporaryTraceFilesOlderThan(int $thresholdUs): int
    {
        $deleted = 0;

        foreach ($this->temporaryTraceFiles() as $path) {
            if ($this->modifiedAt($path) * 1_000_000 >= $thresholdUs) {
                continue;
            }

            if ($this->files->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return array<int, string>
     */
    protected function traceFiles(): array
    {
        $files = array_values(array_filter(
            $this->candidateTraceFiles(),
            fn (string $path): bool => $this->isReadableTraceFile($path)
        ));

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

    /**
     * @return array<int, string>
     */
    protected function prunableTraceFiles(): array
    {
        return array_values(array_filter(
            $this->candidateTraceFiles(),
            fn (string $path): bool => $this->isPrunableTraceFile($path)
        ));
    }

    /**
     * @return array<int, string>
     */
    protected function candidateTraceFiles(): array
    {
        return glob(rtrim($this->directory, '/').DIRECTORY_SEPARATOR.'trc_*.json') ?: [];
    }

    protected function nextSavedAt(): int
    {
        $now = (int) round(microtime(true) * 1_000_000);
        $this->lastSavedAt = max($this->lastSavedAt + 1, $now);

        return $this->lastSavedAt;
    }

    protected function savedAt(string $path): int
    {
        $decoded = $this->readPayload($path);

        if ($decoded === null) {
            return 0;
        }

        return ValueNormalizer::int(
            ValueNormalizer::assoc($decoded['_meta'] ?? [])['saved_at_us'] ?? null
        );
    }

    protected function modifiedAt(string $path): int
    {
        clearstatcache(true, $path);
        $mtime = @filemtime($path);

        return is_int($mtime) ? $mtime : 0;
    }

    protected function ageReferenceUs(string $path): int
    {
        $savedAtUs = $this->savedAt($path);

        if ($savedAtUs > 0) {
            return $savedAtUs;
        }

        return $this->modifiedAt($path) * 1_000_000;
    }

    protected function isReadableTraceFile(string $path): bool
    {
        $payload = $this->readPayload($path);

        if ($payload === null) {
            return false;
        }

        if (! $this->matchesTraceIdentity($path, $payload)) {
            return false;
        }

        $meta = ValueNormalizer::assoc($payload['_meta'] ?? []);
        $repository = ValueNormalizer::nullableString($meta['repository'] ?? null);

        return $repository === null || $repository === self::META_REPOSITORY;
    }

    protected function isPrunableTraceFile(string $path): bool
    {
        $payload = $this->readPayload($path);

        if ($payload === null) {
            return true;
        }

        if (! $this->matchesTraceIdentity($path, $payload)) {
            return false;
        }

        $meta = ValueNormalizer::assoc($payload['_meta'] ?? []);
        $repository = ValueNormalizer::nullableString($meta['repository'] ?? null);

        return $repository === self::META_REPOSITORY;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function matchesTraceIdentity(string $path, array $payload): bool
    {
        $traceId = ValueNormalizer::nullableString($payload['trace_id'] ?? null);
        $kind = ValueNormalizer::nullableString($payload['kind'] ?? null);

        if (! is_string($traceId) || $traceId === '' || ! is_string($kind) || $kind === '') {
            return false;
        }

        if (basename($path, '.json') !== $traceId) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readPayload(string $path): ?array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return $decoded;
    }

    protected function readTrace(string $path): ?TraceEnvelope
    {
        $payload = $this->readPayload($path);

        if ($payload === null || ! $this->matchesTraceIdentity($path, $payload)) {
            return null;
        }

        $meta = ValueNormalizer::assoc($payload['_meta'] ?? []);
        $repository = ValueNormalizer::nullableString($meta['repository'] ?? null);

        if ($repository !== null && $repository !== self::META_REPOSITORY) {
            return null;
        }

        return TraceEnvelope::fromArray($payload);
    }
}
