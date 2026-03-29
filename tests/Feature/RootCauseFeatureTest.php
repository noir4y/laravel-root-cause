<?php

namespace LaravelRootCause\Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Data\Evidence;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Support\TraceFinder;
use LaravelRootCause\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

class RootCauseFeatureTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function responseValidationProvider(): array
    {
        return [
            'default message' => ['/response-validation', ['email' => 'not-an-email']],
            'translated message' => ['/response-validation-translated', ['email' => 'not-an-email']],
            'framework wording diff' => ['/response-validation-framework-diff', ['email' => 'not-an-email']],
            'custom exception renderer' => ['/response-validation-custom-renderer', ['email' => 'not-an-email']],
            'custom standard-like message' => ['/response-validation-custom-message', ['email' => 'not-an-email']],
        ];
    }

    public function test_it_persists_a_validation_diagnosis_for_failed_requests(): void
    {
        $this->postJson('/users', ['name' => 'Taylor'])
            ->assertStatus(422);

        $this->assertNotNull($this->app);
        $repository = $this->app->make(TraceRepository::class);
        $this->assertInstanceOf(TraceRepository::class, $repository);
        $trace = $repository->latest();

        $this->assertNotNull($trace);
        $this->assertSame(
            'validation_contract_mismatch',
            $trace->diagnosis?->rootCauseCategory,
            json_encode($trace->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        $this->assertSame(422, $trace->response['status_code']);
    }

    public function test_it_marks_duplicate_query_bursts(): void
    {
        $this->getJson('/duplicate-query')
            ->assertOk();

        $this->assertNotNull($this->app);
        $repository = $this->app->make(TraceRepository::class);
        $this->assertInstanceOf(TraceRepository::class, $repository);
        $trace = $repository->latest();

        $this->assertNotNull($trace);
        $this->assertSame('duplicate_query_burst', $trace->diagnosis?->rootCauseCategory);
    }

    public function test_it_captures_validation_exceptions_and_redacts_sensitive_input(): void
    {
        $this->postJson('/users', [
            'email' => 'not-an-email',
            'password' => 'secret',
        ])->assertStatus(422);

        $trace = $this->latestTrace();
        $signal = $trace->signalsOfType('validation_failed')[0] ?? null;
        $inputShape = $trace->context['input_shape'] ?? null;

        $this->assertNotNull($signal);
        $this->assertIsArray($inputShape);
        $this->assertSame('validation_contract_mismatch', $trace->diagnosis?->rootCauseCategory);
        $this->assertSame(['email' => ['email']], $signal->payload['failed_fields']);
        $this->assertSame(['email'], $trace->context['input_keys']);
        $this->assertArrayNotHasKey('password', $inputShape);
    }

    public function test_it_tracks_transport_and_diagnostic_statuses_for_validation_redirects(): void
    {
        $this->from('/users/form')
            ->post('/users', ['name' => 'Taylor'])
            ->assertRedirect('/users/form');

        $trace = $this->latestTrace();

        $this->assertSame(302, $trace->response['status_code']);
        $this->assertSame(302, $trace->response['transport_status_code']);
        $this->assertSame(422, $trace->response['diagnostic_status_code']);
        $this->assertSame('validation_contract_mismatch', $trace->diagnosis?->rootCauseCategory);

        $this->assertNotNull($this->app);
        $finder = $this->app->make(TraceFinder::class);

        $this->assertInstanceOf(TraceFinder::class, $finder);
        $this->assertSame($trace->traceId, $finder->latestFailed()?->traceId);
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('responseValidationProvider')]
    public function test_it_diagnoses_framework_style_validation_responses_without_an_attached_exception(string $uri, array $payload): void
    {
        $this->postJson($uri, $payload)
            ->assertStatus(422);

        $trace = $this->latestTrace();
        $signal = $trace->signalsOfType('validation_failed')[0] ?? null;

        $this->assertNotNull($signal);
        $this->assertSame('validation_contract_mismatch', $trace->diagnosis?->rootCauseCategory);
        $this->assertSame(['email' => ['reported']], $signal->payload['failed_fields']);
    }

    public function test_it_does_not_treat_domain_422_payloads_as_validation_failures(): void
    {
        $this->postJson('/domain-conflict', [
            'order_id' => 'ord_123',
        ])->assertStatus(422);

        $trace = $this->latestTrace();

        $this->assertSame(422, $trace->response['status_code']);
        $this->assertCount(0, $trace->signalsOfType('validation_failed'));
        $this->assertNull($trace->diagnosis);
    }

    public function test_it_diagnoses_unhandled_exceptions_with_query_context(): void
    {
        $this->withExceptionHandling()
            ->getJson('/explode')
            ->assertStatus(500);

        $trace = $this->latestTrace();
        $signal = $trace->signalsOfType('exception_thrown')[0] ?? null;

        $this->assertNotNull($signal);
        $this->assertSame('unhandled_exception', $trace->diagnosis?->rootCauseCategory);
        $this->assertSame(500, $trace->response['status_code']);
        $this->assertTrue(collect($signal->evidence)->contains(
            static fn (Evidence $evidence): bool => $evidence->type === 'query_summary'
        ));
    }

    public function test_it_uses_the_final_custom_rendered_transport_status_without_double_rendering(): void
    {
        $this->assertNotNull($this->app);

        $innerHandler = $this->app->make(ExceptionHandler::class);
        $handler = new class($innerHandler) implements ExceptionHandler
        {
            public int $renderCount = 0;

            public function __construct(protected ExceptionHandler $innerHandler) {}

            public function report(Throwable $e)
            {
                $this->innerHandler->report($e);
            }

            public function shouldReport(Throwable $e)
            {
                return $this->innerHandler->shouldReport($e);
            }

            public function render($request, Throwable $e)
            {
                $this->renderCount++;

                if ($e instanceof \RuntimeException) {
                    return redirect('/custom-error');
                }

                return $this->innerHandler->render($request, $e);
            }

            public function renderForConsole($output, Throwable $e)
            {
                $this->innerHandler->renderForConsole($output, $e);
            }
        };

        $this->app->instance(ExceptionHandler::class, $handler);

        $this->get('/explode')
            ->assertRedirect('/custom-error');

        $trace = $this->latestTrace();

        $this->assertSame(302, $trace->response['status_code']);
        $this->assertSame(302, $trace->response['transport_status_code']);
        $this->assertSame(500, $trace->response['diagnostic_status_code']);
        $this->assertSame(1, $handler->renderCount);
    }

    public function test_it_ignores_handled_reported_exceptions_when_the_request_succeeds(): void
    {
        $this->getJson('/handled-report')
            ->assertOk();

        $trace = $this->latestTrace();

        $this->assertSame(200, $trace->response['status_code']);
        $this->assertCount(0, $trace->signalsOfType('exception_thrown'));
        $this->assertNull($trace->diagnosis);
    }

    public function test_it_keeps_request_responses_intact_when_trace_persistence_fails(): void
    {
        $this->assertNotNull($this->app);

        $repository = new class implements TraceRepository
        {
            public int $saveAttempts = 0;

            public function save(TraceEnvelope $trace): void
            {
                $this->saveAttempts++;

                throw new \RuntimeException('disk full');
            }

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
        };

        $this->app->instance(TraceRepository::class, $repository);

        $this->getJson('/duplicate-query')
            ->assertOk();

        $this->assertSame(1, $repository->saveAttempts);
        $this->assertNull($repository->latest());
    }

    public function test_it_preserves_framework_mapped_http_statuses_for_model_not_found_responses(): void
    {
        $this->withExceptionHandling()
            ->getJson('/users/999')
            ->assertStatus(404);

        $trace = $this->latestTrace();

        $this->assertSame(404, $trace->response['status_code']);
        $this->assertSame('missing_route_binding', $trace->diagnosis?->rootCauseCategory);
    }

    public function test_it_waits_for_stream_callbacks_before_persisting_the_trace(): void
    {
        $response = $this->get('/streamed-duplicate-query')
            ->assertOk();

        $this->assertNotNull($this->app);
        $repository = $this->app->make(TraceRepository::class);

        $this->assertInstanceOf(TraceRepository::class, $repository);
        $this->assertNull($repository->latest());
        $this->assertSame('stream-complete', $response->streamedContent());

        $trace = $this->latestTrace();

        $this->assertSame('duplicate_query_burst', $trace->diagnosis?->rootCauseCategory);
        $this->assertCount(3, $trace->signalsOfType('query_executed'));
    }

    public function test_it_also_defers_chunked_streamed_responses_until_the_chunks_are_consumed(): void
    {
        $response = $this->get('/streamed-chunks-duplicate-query')
            ->assertOk();

        $this->assertNotNull($this->app);
        $repository = $this->app->make(TraceRepository::class);

        $this->assertInstanceOf(TraceRepository::class, $repository);
        $this->assertNull($repository->latest());
        $this->assertSame('chunk-1chunk-2', $response->streamedContent());

        $trace = $this->latestTrace();

        $this->assertSame('duplicate_query_burst', $trace->diagnosis?->rootCauseCategory);
        $this->assertCount(3, $trace->signalsOfType('query_executed'));
    }

    protected function latestTrace(): TraceEnvelope
    {
        $this->assertNotNull($this->app);

        $repository = $this->app->make(TraceRepository::class);

        $this->assertInstanceOf(TraceRepository::class, $repository);

        $trace = $repository->latest();

        $this->assertInstanceOf(TraceEnvelope::class, $trace);

        return $trace;
    }
}
