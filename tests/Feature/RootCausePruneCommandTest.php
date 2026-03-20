<?php

namespace LaravelRootCause\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Testing\PendingCommand;
use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Tests\TestCase;

class RootCausePruneCommandTest extends TestCase
{
    public function test_prune_command_deletes_only_traces_older_than_the_retention_window(): void
    {
        CarbonImmutable::setTestNow('2026-03-20 12:00:00 UTC');

        try {
            $this->storeTrace($this->makeTrace('trc_recent'));
            $this->storeTrace($this->makeTrace('trc_stale'));

            $path = config('root_cause.storage.path');
            $files = new Filesystem;

            $this->assertIsString($path);
            $files->ensureDirectoryExists($path);
            $this->rewriteSavedAtUs($path.'/trc_stale.json', CarbonImmutable::parse('2026-03-01 12:00:00 UTC')->getTimestamp() * 1_000_000);
            touch($path.'/trc_recent.json', CarbonImmutable::parse('2026-03-18 12:00:00 UTC')->getTimestamp());
            touch($path.'/trc_stale.json', CarbonImmutable::parse('2026-03-01 12:00:00 UTC')->getTimestamp());

            $this->runArtisan('root-cause:prune', ['--days' => 7])
                ->expectsOutputToContain('Pruned 1 trace(s) older than 7 day(s).')
                ->assertExitCode(0);

            $this->assertFileExists($path.'/trc_recent.json');
            $this->assertFileDoesNotExist($path.'/trc_stale.json');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_prune_command_rejects_invalid_retention_days(): void
    {
        $this->runArtisan('root-cause:prune', ['--days' => 0])
            ->expectsOutputToContain('Retention days must be at least 1.')
            ->assertExitCode(1);
    }

    protected function storeTrace(TraceEnvelope $trace): void
    {
        $this->assertNotNull($this->app);

        $repository = $this->app->make(TraceRepository::class);
        $repository->save($trace);
    }

    protected function makeTrace(string $traceId): TraceEnvelope
    {
        return new TraceEnvelope(
            traceId: $traceId,
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: '2026-03-13T14:00:02+09:00',
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: ['method' => 'GET', 'uri' => '/root-cause'],
            context: ['request_url' => 'http://localhost/root-cause'],
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pendingCommand = $this->artisan($command, $parameters);

        $this->assertInstanceOf(PendingCommand::class, $pendingCommand);

        return $pendingCommand;
    }

    protected function rewriteSavedAtUs(string $path, int $savedAtUs): void
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $meta = $payload['_meta'] ?? [];

        if (! is_array($meta)) {
            $meta = [];
        }

        $meta['saved_at_us'] = $savedAtUs;
        $payload['_meta'] = $meta;

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
