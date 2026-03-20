<?php

namespace LaravelRootCause\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use LaravelRootCause\Contracts\PrunableTraceRepository;
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
                summary: 'An Unhandled RuntimeException returned a 500 error.',
                rootCauseCategory: 'unhandled_exception',
                confidence: 0.57,
                supportingEvidence: [new Evidence('exception', ['class' => \RuntimeException::class])],
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

    public function test_it_returns_null_when_a_trace_disappears_during_find(): void
    {
        $files = $this->createMock(Filesystem::class);
        $repository = new FileTraceRepository($files, '/tmp/root-cause');

        $files->expects($this->atLeastOnce())
            ->method('get')
            ->with('/tmp/root-cause/trc_missing.json')
            ->willThrowException(new \RuntimeException('gone'));

        $this->assertNull($repository->find('trc_missing'));
    }

    public function test_it_prunes_traces_older_than_a_threshold(): void
    {
        $directory = sys_get_temp_dir().'/laravel-root-cause-repository-tests/'.uniqid('', true);
        $repository = new FileTraceRepository(new Filesystem, $directory);

        $repository->save($this->makeTrace('trc_keep'));
        $repository->save($this->makeTrace('trc_prune'));
        $this->rewriteSavedAtUs($directory.'/trc_prune.json', CarbonImmutable::parse('2026-03-01 10:00:00 UTC')->getTimestamp() * 1_000_000);

        touch($directory.'/trc_keep.json', CarbonImmutable::parse('2026-03-19 10:00:00 UTC')->getTimestamp());
        touch($directory.'/trc_prune.json', CarbonImmutable::parse('2026-03-19 10:00:00 UTC')->getTimestamp());

        $deleted = $repository->pruneOlderThan(CarbonImmutable::parse('2026-03-10 00:00:00 UTC'));

        $this->assertSame(1, $deleted);
        $this->assertFileExists($directory.'/trc_keep.json');
        $this->assertFileDoesNotExist($directory.'/trc_prune.json');
    }

    public function test_it_does_not_prune_non_trace_json_files_from_the_storage_directory(): void
    {
        $directory = sys_get_temp_dir().'/laravel-root-cause-repository-tests/'.uniqid('', true);
        $repository = new FileTraceRepository(new Filesystem, $directory);

        $repository->save($this->makeTrace('trc_keep'));
        file_put_contents($directory.'/export.json', json_encode(['report' => 'leave-me-alone'], JSON_THROW_ON_ERROR));
        touch($directory.'/export.json', CarbonImmutable::parse('2026-03-01 10:00:00 UTC')->getTimestamp());

        $deleted = $repository->pruneOlderThan(CarbonImmutable::parse('2026-03-10 00:00:00 UTC'));

        $this->assertSame(0, $deleted);
        $this->assertFileExists($directory.'/export.json');
    }

    public function test_it_prunes_corrupted_trace_files_that_match_the_package_trace_naming_convention(): void
    {
        $directory = sys_get_temp_dir().'/laravel-root-cause-repository-tests/'.uniqid('', true);
        $repository = new FileTraceRepository(new Filesystem, $directory);

        (new Filesystem)->ensureDirectoryExists($directory);
        file_put_contents($directory.'/trc_corrupted.json', '{invalid');
        touch($directory.'/trc_corrupted.json', CarbonImmutable::parse('2026-03-01 10:00:00 UTC')->getTimestamp());

        $deleted = $repository->pruneOlderThan(CarbonImmutable::parse('2026-03-10 00:00:00 UTC'));

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($directory.'/trc_corrupted.json');
    }

    public function test_it_does_not_prune_legacy_unstamped_trace_payloads(): void
    {
        $directory = sys_get_temp_dir().'/laravel-root-cause-repository-tests/'.uniqid('', true);
        $repository = new FileTraceRepository(new Filesystem, $directory);

        $repository->save($this->makeTrace('trc_legacy'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) file_get_contents($directory.'/trc_legacy.json'), true, 512, JSON_THROW_ON_ERROR);
        $meta = $payload['_meta'] ?? [];
        $this->assertIsArray($meta);
        unset($meta['repository']);
        $payload['_meta'] = $meta;
        file_put_contents($directory.'/trc_legacy.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        touch($directory.'/trc_legacy.json', CarbonImmutable::parse('2026-03-01 10:00:00 UTC')->getTimestamp());

        $deleted = $repository->pruneOlderThan(CarbonImmutable::parse('2026-03-10 00:00:00 UTC'));

        $this->assertSame(0, $deleted);
        $this->assertFileExists($directory.'/trc_legacy.json');
        $this->assertSame('trc_legacy', $repository->latest()?->traceId);
    }

    public function test_it_prunes_stale_temporary_trace_artifacts_from_both_current_and_legacy_locations(): void
    {
        $directory = sys_get_temp_dir().'/laravel-root-cause-repository-tests/'.uniqid('', true);
        $files = new Filesystem;
        $repository = new FileTraceRepository($files, $directory);

        $files->ensureDirectoryExists($directory.'/.tmp');
        file_put_contents($directory.'/.tmp/trc_buffered.json.tmp.current', '{"trace_id":"trc_buffered"}');
        file_put_contents($directory.'/trc_buffered.json.tmp.legacy', '{"trace_id":"trc_buffered"}');
        touch($directory.'/.tmp/trc_buffered.json.tmp.current', CarbonImmutable::parse('2026-03-01 10:00:00 UTC')->getTimestamp());
        touch($directory.'/trc_buffered.json.tmp.legacy', CarbonImmutable::parse('2026-03-01 10:00:00 UTC')->getTimestamp());

        $deleted = $repository->pruneOlderThan(CarbonImmutable::parse('2026-03-10 00:00:00 UTC'));

        $this->assertSame(2, $deleted);
        $this->assertFileDoesNotExist($directory.'/.tmp/trc_buffered.json.tmp.current');
        $this->assertFileDoesNotExist($directory.'/trc_buffered.json.tmp.legacy');
    }

    public function test_it_implements_the_prunable_repository_contract_without_widening_the_base_contract(): void
    {
        $repository = new FileTraceRepository(new Filesystem, sys_get_temp_dir().'/laravel-root-cause-repository-tests/'.uniqid('', true));

        $this->assertInstanceOf(PrunableTraceRepository::class, $repository);
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
