<?php

namespace LaravelRootCause\Tests\Feature;

use Illuminate\Testing\PendingCommand;
use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Tests\TestCase;

class PruneCommandSupportTest extends TestCase
{
    public function test_it_reports_when_the_configured_repository_cannot_prune(): void
    {
        $this->assertNotNull($this->app);

        $this->app->instance(TraceRepository::class, new class implements TraceRepository
        {
            public function save(TraceEnvelope $trace): void {}

            public function find(string $traceId): ?TraceEnvelope
            {
                return null;
            }

            public function latest(): ?TraceEnvelope
            {
                return null;
            }

            public function recent(int $limit = 20): array
            {
                return [];
            }
        });

        $this->runArtisan('root-cause:prune')
            ->expectsOutputToContain('The configured trace repository does not support pruning.')
            ->assertExitCode(1);
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
}
