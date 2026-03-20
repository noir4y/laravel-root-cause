<?php

namespace LaravelRootCause\Tests\Unit;

use LaravelRootCause\Data\DiagnosisReport;
use LaravelRootCause\Data\Evidence;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Export\CliTraceFormatter;
use PHPUnit\Framework\TestCase;

class CliTraceFormatterTest extends TestCase
{
    public function test_it_formats_a_diagnosed_trace_for_the_cli(): void
    {
        $trace = new TraceEnvelope(
            traceId: 'trc_cli',
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: '2026-03-13T14:00:02+09:00',
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: ['method' => 'POST', 'uri' => '/users'],
            context: ['request_url' => 'http://localhost/users'],
            diagnosis: new DiagnosisReport(
                summary: 'Error 422 occurred due to mismatch between StoreUserRequest and payload',
                rootCauseCategory: 'validation_contract_mismatch',
                confidence: 0.76,
                supportingEvidence: [
                    new Evidence('validation_rule', [
                        'source' => 'App\\Http\\Requests\\StoreUserRequest',
                        'field' => 'email',
                        'rule' => 'required',
                    ]),
                    new Evidence('input_keys', [
                        'keys' => ['name'],
                    ]),
                ],
                affectedFiles: ['/app/Http/Requests/StoreUserRequest.php'],
                candidateFixes: ['Include the required field "email" in the request payload or make the rule nullable.'],
                repro: ['method' => 'POST', 'uri' => '/users'],
            ),
        );

        $output = (new CliTraceFormatter)->format($trace);

        $this->assertStringContainsString('Trace: trc_cli', $output);
        $this->assertStringContainsString('Root cause: validation_contract_mismatch', $output);
        $this->assertStringContainsString('StoreUserRequest failed on email.required', $output);
        $this->assertStringContainsString('Payload keys: [name]', $output);
        $this->assertStringContainsString('/app/Http/Requests/StoreUserRequest.php', $output);
        $this->assertStringContainsString('Include the required field "email" in the request payload or make the rule nullable.', $output);
    }
}
