<?php

namespace LaravelRootCause\Tests\Unit;

use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Data\DiagnosisReport;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Support\TraceFinder;
use PHPUnit\Framework\TestCase;

class TraceFinderTest extends TestCase
{
    public function test_it_resolves_latest_and_specific_traces(): void
    {
        $latest = $this->makeTrace('trc_latest');
        $named = $this->makeTrace('trc_named');
        $finder = new TraceFinder($this->repository([$latest, $named], ['trc_named' => $named]));

        $this->assertSame('trc_latest', $finder->resolve()?->traceId);
        $this->assertSame('trc_named', $finder->resolve('trc_named')?->traceId);
    }

    public function test_it_finds_failed_and_query_pathology_traces(): void
    {
        $success = $this->makeTrace('trc_success', 200);
        $pathology = $this->makeTrace('trc_pathology', 200, 'duplicate_query_burst');
        $failed = $this->makeTrace('trc_failed', 422, 'validation_contract_mismatch');
        $finder = new TraceFinder($this->repository([$success, $pathology, $failed], [
            'trc_success' => $success,
            'trc_pathology' => $pathology,
            'trc_failed' => $failed,
        ]));

        $this->assertSame('trc_failed', $finder->latestFailed()?->traceId);
        $this->assertSame('trc_pathology', $finder->latestQueryPathology()?->traceId);
    }

    public function test_it_searches_beyond_the_newest_fifty_traces_for_matching_entries(): void
    {
        $recentSuccesses = array_map(
            fn (int $index): TraceEnvelope => $this->makeTrace('trc_success_'.$index),
            range(1, 50)
        );
        $pathology = $this->makeTrace('trc_pathology', 200, 'duplicate_query_burst');
        $failed = $this->makeTrace('trc_failed', 422, 'validation_contract_mismatch');
        $recent = [...$recentSuccesses, $pathology, $failed];
        $finder = new TraceFinder($this->repository($recent, [
            'trc_pathology' => $pathology,
            'trc_failed' => $failed,
        ]));

        $this->assertSame('trc_failed', $finder->latestFailed()?->traceId);
        $this->assertSame('trc_pathology', $finder->latestQueryPathology()?->traceId);
    }

    public function test_it_filters_recent_traces_to_diagnosed_entries(): void
    {
        $diagnosed = $this->makeTrace('trc_diagnosed', 500, 'unhandled_exception');
        $plain = $this->makeTrace('trc_plain');
        $finder = new TraceFinder($this->repository([$diagnosed, $plain], [
            'trc_diagnosed' => $diagnosed,
            'trc_plain' => $plain,
        ]));

        $recent = $finder->recentDiagnoses();

        $this->assertCount(1, $recent);
        $this->assertSame('trc_diagnosed', $recent[0]->traceId);
    }

    /**
     * @param  array<int, TraceEnvelope>  $recent
     * @param  array<string, TraceEnvelope>  $named
     */
    protected function repository(array $recent, array $named): TraceRepository
    {
        return new class($recent, $named) implements TraceRepository
        {
            /**
             * @param  array<int, TraceEnvelope>  $recent
             * @param  array<string, TraceEnvelope>  $named
             */
            public function __construct(
                protected array $recent,
                protected array $named,
            ) {}

            public function save(TraceEnvelope $trace): void
            {
                $this->named[$trace->traceId] = $trace;
            }

            public function find(string $traceId): ?TraceEnvelope
            {
                return $this->named[$traceId] ?? null;
            }

            public function latest(): ?TraceEnvelope
            {
                return $this->recent[0] ?? null;
            }

            public function recent(int $limit = 20): array
            {
                return array_slice($this->recent, 0, $limit);
            }
        };
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
            entrypoint: ['method' => 'GET', 'uri' => '/health'],
            context: ['request_url' => 'http://localhost/health'],
            response: ['status_code' => $statusCode],
            diagnosis: $category ? new DiagnosisReport(
                summary: 'Diagnosis summary',
                rootCauseCategory: $category,
                confidence: 0.7,
            ) : null,
        );
    }
}
