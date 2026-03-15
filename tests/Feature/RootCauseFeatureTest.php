<?php

namespace LaravelRootCause\Tests\Feature;

use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Data\Evidence;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Tests\TestCase;

class RootCauseFeatureTest extends TestCase
{
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

    public function test_it_ignores_handled_reported_exceptions_when_the_request_succeeds(): void
    {
        $this->getJson('/handled-report')
            ->assertOk();

        $trace = $this->latestTrace();

        $this->assertSame(200, $trace->response['status_code']);
        $this->assertCount(0, $trace->signalsOfType('exception_thrown'));
        $this->assertNull($trace->diagnosis);
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
