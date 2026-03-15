<?php

namespace LaravelRootCause\Tests\Feature;

use Illuminate\Testing\PendingCommand;
use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Data\DiagnosisReport;
use LaravelRootCause\Data\Evidence;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Tests\TestCase;

class RootCauseCommandTest extends TestCase
{
    public function test_trace_command_renders_a_stored_trace(): void
    {
        $this->storeTrace($this->makeTrace('trc_validation', 422, 'validation_contract_mismatch'));

        $this->runArtisan('root-cause:trace', ['trace_id' => 'latest'])
            ->expectsOutputToContain('Trace: trc_validation')
            ->assertExitCode(0);
    }

    public function test_failed_request_and_query_pathology_commands_select_matching_traces(): void
    {
        $this->storeTrace($this->makeTrace('trc_success', 200));
        $this->storeTrace($this->makeTrace('trc_failed', 422, 'validation_contract_mismatch'));
        $this->storeTrace($this->makeTrace('trc_pathology', 200, 'duplicate_query_burst'));

        $this->runArtisan('root-cause:failed-request')
            ->expectsOutputToContain('Trace: trc_failed')
            ->assertExitCode(0);

        $this->runArtisan('root-cause:query-pathology')
            ->expectsOutputToContain('Trace: trc_pathology')
            ->assertExitCode(0);
    }

    public function test_commands_report_failures_and_can_export_to_a_file(): void
    {
        $this->runArtisan('root-cause:failed-request')
            ->expectsOutputToContain('No failed request traces found.')
            ->assertExitCode(1);

        $this->storeTrace($this->makeTrace('trc_export', 200, 'duplicate_query_burst'));

        $this->runArtisan('root-cause:export', ['trace_id' => 'latest', '--format' => 'yaml'])
            ->expectsOutputToContain('Only JSON export is supported in v0.1.')
            ->assertExitCode(1);

        $path = sys_get_temp_dir().'/root-cause-export-'.uniqid('', true).'.json';

        $this->runArtisan('root-cause:export', ['trace_id' => 'latest', '--path' => $path])
            ->expectsOutputToContain(sprintf('Exported trace to %s', $path))
            ->assertExitCode(0);

        $this->assertFileExists($path);
        $this->assertStringContainsString('"trace_id": "trc_export"', file_get_contents($path) ?: '');
    }

    protected function storeTrace(TraceEnvelope $trace): void
    {
        $this->assertNotNull($this->app);

        $repository = $this->app->make(TraceRepository::class);

        $this->assertInstanceOf(TraceRepository::class, $repository);
        $repository->save($trace);
    }

    protected function makeTrace(string $traceId, int $statusCode = 200, ?string $category = null): TraceEnvelope
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
            response: ['status_code' => $statusCode],
            diagnosis: $category ? new DiagnosisReport(
                summary: 'Diagnosis summary',
                rootCauseCategory: $category,
                confidence: 0.73,
                supportingEvidence: [
                    new Evidence('exception', [
                        'class' => \RuntimeException::class,
                        'message' => 'Boom from route',
                    ]),
                ],
                candidateFixes: ['Add a regression test.'],
                repro: ['method' => 'GET', 'uri' => '/root-cause'],
            ) : null,
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
}
