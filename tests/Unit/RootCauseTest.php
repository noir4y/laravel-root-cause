<?php

namespace LaravelRootCause\Tests\Unit;

use Illuminate\Http\Request;
use LaravelRootCause\Collectors\ExceptionCollector;
use LaravelRootCause\Collectors\ValidationCollector;
use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Data\DiagnosisReport;
use LaravelRootCause\Data\Signal;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Diagnostics\RuleEngine;
use LaravelRootCause\Redaction\Redactor;
use LaravelRootCause\Support\RootCause;
use LaravelRootCause\Support\RootCauseContext;
use LaravelRootCause\Tests\TestCase as PackageTestCase;
use Symfony\Component\HttpFoundation\Response;

class RootCauseTest extends PackageTestCase
{
    public function test_it_classifies_n_plus_one_query_pathology_before_persisting_a_trace(): void
    {
        $repository = $this->createMock(TraceRepository::class);
        $validationCollector = $this->createMock(ValidationCollector::class);
        $exceptionCollector = $this->createMock(ExceptionCollector::class);
        $ruleEngine = $this->createMock(RuleEngine::class);

        $this->assertNotNull($this->app);

        $rootCause = new RootCause(
            $this->app,
            $repository,
            new RootCauseContext,
            new Redactor,
            $validationCollector,
            $exceptionCollector,
            $ruleEngine,
        );

        $trace = $rootCause->startRequest(Request::create('/n-plus-one', 'GET'));

        foreach ([1.2, 1.4, 0.9] as $duration) {
            $trace->addSignal(new Signal(
                type: 'query_executed',
                capturedAt: '2026-03-13T14:00:01+09:00',
                payload: [
                    'fingerprint' => 'select * from "comments" where "post_id" = ?',
                    'table_candidates' => ['comments'],
                    'duration_ms' => $duration,
                    'connection' => 'testing',
                    'caller_frame' => [
                        'file' => '/app/Models/Post.php',
                        'line' => 32,
                        'class' => 'App\\Models\\Post',
                        'function' => 'comments',
                    ],
                ],
            ));
        }

        $ruleEngine->expects($this->once())
            ->method('diagnose')
            ->with($this->callback(function (TraceEnvelope $trace): bool {
                $signal = $trace->signalsOfType('query_pathology_suspected')[0] ?? null;

                $this->assertNotNull($signal);
                $this->assertSame('n_plus_one_suspected', $signal->payload['classification']);
                $this->assertSame(3, $signal->payload['count']);

                return true;
            }))
            ->willReturn(new DiagnosisReport(
                summary: 'N+1 is suspected based on the repetition of the same query fingerprint.',
                rootCauseCategory: 'n_plus_one_suspected',
                confidence: 0.81,
            ));

        $repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (TraceEnvelope $trace): bool {
                $signal = $trace->signalsOfType('query_pathology_suspected')[0] ?? null;

                $this->assertNotNull($signal);
                $this->assertSame('n_plus_one_suspected', $trace->diagnosis?->rootCauseCategory);

                return true;
            }));

        $finished = $rootCause->finishRequest(new Response('', 200));

        $this->assertInstanceOf(TraceEnvelope::class, $finished);
        $this->assertSame('n_plus_one_suspected', $finished->diagnosis?->rootCauseCategory);
    }

    public function test_it_checks_all_query_groups_before_concluding_there_is_no_pathology(): void
    {
        config()->set('root_cause.collectors.query.duplicate_threshold', 5);
        config()->set('root_cause.collectors.query.n_plus_one_threshold', 3);

        $repository = $this->createMock(TraceRepository::class);
        $validationCollector = $this->createMock(ValidationCollector::class);
        $exceptionCollector = $this->createMock(ExceptionCollector::class);
        $ruleEngine = $this->createMock(RuleEngine::class);

        $this->assertNotNull($this->app);

        $rootCause = new RootCause(
            $this->app,
            $repository,
            new RootCauseContext,
            new Redactor,
            $validationCollector,
            $exceptionCollector,
            $ruleEngine,
        );

        $trace = $rootCause->startRequest(Request::create('/mixed-pathology', 'GET'));

        foreach ([0.2, 0.2, 0.2, 0.2] as $duration) {
            $trace->addSignal(new Signal(
                type: 'query_executed',
                capturedAt: '2026-03-13T14:00:01+09:00',
                payload: [
                    'fingerprint' => 'select * from "jobs" where "queue" = ?',
                    'table_candidates' => ['jobs'],
                    'duration_ms' => $duration,
                    'connection' => 'testing',
                    'caller_frame' => null,
                ],
            ));
        }

        foreach ([1.1, 1.2, 1.3] as $duration) {
            $trace->addSignal(new Signal(
                type: 'query_executed',
                capturedAt: '2026-03-13T14:00:01+09:00',
                payload: [
                    'fingerprint' => 'select * from "comments" where "post_id" = ?',
                    'table_candidates' => ['comments'],
                    'duration_ms' => $duration,
                    'connection' => 'testing',
                    'caller_frame' => [
                        'file' => '/app/Models/Post.php',
                        'line' => 32,
                        'class' => 'App\\Models\\Post',
                        'function' => 'comments',
                    ],
                ],
            ));
        }

        $ruleEngine->expects($this->once())
            ->method('diagnose')
            ->with($this->callback(function (TraceEnvelope $trace): bool {
                $signal = $trace->signalsOfType('query_pathology_suspected')[0] ?? null;

                $this->assertNotNull($signal);
                $this->assertSame('n_plus_one_suspected', $signal->payload['classification']);
                $this->assertSame('select * from "comments" where "post_id" = ?', $signal->payload['fingerprint']);

                return true;
            }))
            ->willReturn(new DiagnosisReport(
                summary: 'N+1 is suspected based on the repetition of the same query fingerprint.',
                rootCauseCategory: 'n_plus_one_suspected',
                confidence: 0.81,
            ));

        $repository->expects($this->once())
            ->method('save');

        $finished = $rootCause->finishRequest(new Response('', 200));

        $this->assertInstanceOf(TraceEnvelope::class, $finished);
        $this->assertSame('n_plus_one_suspected', $finished->diagnosis?->rootCauseCategory);
    }

    public function test_it_clears_runtime_state_when_trace_persistence_fails(): void
    {
        $repository = $this->createMock(TraceRepository::class);
        $validationCollector = $this->createMock(ValidationCollector::class);
        $exceptionCollector = $this->createMock(ExceptionCollector::class);
        $ruleEngine = $this->createMock(RuleEngine::class);

        $this->assertNotNull($this->app);

        $ruleEngine->expects($this->once())
            ->method('diagnose')
            ->willReturn(null);

        $repository->expects($this->once())
            ->method('save')
            ->willThrowException(new \RuntimeException('disk full'));

        $rootCause = new RootCause(
            $this->app,
            $repository,
            new RootCauseContext,
            new Redactor,
            $validationCollector,
            $exceptionCollector,
            $ruleEngine,
        );

        $trace = $rootCause->startRequest(Request::create('/persistence-failure', 'GET'));
        $finished = $rootCause->finishRequest(new Response('', 200));

        $this->assertSame($trace->traceId, $finished?->traceId);
        $this->assertNull($rootCause->currentTrace());
    }
}
