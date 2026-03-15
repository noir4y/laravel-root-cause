<?php

namespace LaravelRootCause\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use LaravelRootCause\Data\DiagnosisReport;
use LaravelRootCause\Data\Evidence;
use LaravelRootCause\Data\Signal;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Storage\FileTraceRepository;
use PHPUnit\Framework\TestCase;

class FileTraceRepositoryTest extends TestCase
{
    public function test_it_round_trips_a_trace_envelope_with_diagnosis_and_signals(): void
    {
        $directory = sys_get_temp_dir().'/laravel-root-cause-repository-tests/'.uniqid('', true);
        $repository = new FileTraceRepository(new Filesystem, $directory);
        $trace = new TraceEnvelope(
            traceId: 'trc_example',
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: '2026-03-13T14:00:02+09:00',
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: ['method' => 'GET', 'uri' => '/health'],
            context: ['request_url' => 'http://localhost/health'],
            response: ['status_code' => 500],
            signals: [
                new Signal(
                    type: 'exception_thrown',
                    capturedAt: '2026-03-13T14:00:01+09:00',
                    payload: ['exception_class' => \RuntimeException::class],
                    evidence: [new Evidence('exception', ['class' => \RuntimeException::class])]
                ),
            ],
            diagnosis: new DiagnosisReport(
                summary: 'Unhandled RuntimeException が 500 を返しました',
                rootCauseCategory: 'unhandled_exception',
                confidence: 0.57,
                supportingEvidence: [new Evidence('exception', ['class' => \RuntimeException::class])]
            ),
        );

        $repository->save($trace);
        $stored = $repository->find('trc_example');

        $this->assertNotNull($stored);
        $this->assertSame('trc_example', $stored->traceId);
        $this->assertSame('/health', $stored->entrypoint['uri']);
        $this->assertSame('unhandled_exception', $stored->diagnosis?->rootCauseCategory);
        $this->assertCount(1, $stored->signals);
    }

    public function test_it_returns_the_latest_valid_trace_and_recent_traces_in_descending_order(): void
    {
        $directory = sys_get_temp_dir().'/laravel-root-cause-repository-tests/'.uniqid('', true);
        $repository = new FileTraceRepository(new Filesystem, $directory);

        $oldest = $this->makeTrace('trc_oldest');
        $middle = $this->makeTrace('trc_middle');
        $latest = $this->makeTrace('trc_latest');

        $repository->save($oldest);
        $repository->save($middle);
        $repository->save($latest);

        touch($directory.'/trc_oldest.json', 1_710_000_001);
        touch($directory.'/trc_middle.json', 1_710_000_002);
        touch($directory.'/trc_latest.json', 1_710_000_003);

        file_put_contents($directory.'/broken.json', '{invalid');
        touch($directory.'/broken.json', 1_710_000_004);

        $recent = $repository->recent(4);

        $this->assertSame('trc_latest', $repository->latest()?->traceId);
        $this->assertSame(['trc_latest', 'trc_middle', 'trc_oldest'], array_map(
            static fn (TraceEnvelope $trace): string => $trace->traceId,
            $recent
        ));
    }

    public function test_it_breaks_same_second_ties_using_saved_write_order(): void
    {
        $directory = sys_get_temp_dir().'/laravel-root-cause-repository-tests/'.uniqid('', true);
        $repository = new FileTraceRepository(new Filesystem, $directory);

        $repository->save($this->makeTrace('trc_oldest'));
        usleep(1_000);
        $repository->save($this->makeTrace('trc_middle'));
        usleep(1_000);
        $repository->save($this->makeTrace('trc_latest'));

        touch($directory.'/trc_oldest.json', 1_710_000_010);
        touch($directory.'/trc_middle.json', 1_710_000_010);
        touch($directory.'/trc_latest.json', 1_710_000_010);

        $this->assertSame('trc_latest', $repository->latest()?->traceId);
        $this->assertSame(['trc_latest', 'trc_middle', 'trc_oldest'], array_map(
            static fn (TraceEnvelope $trace): string => $trace->traceId,
            $repository->recent(3)
        ));
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
            entrypoint: ['method' => 'GET', 'uri' => '/health'],
            context: ['request_url' => 'http://localhost/health'],
        );
    }
}
