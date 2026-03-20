<?php

namespace LaravelRootCause\Tests\Feature;

use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Diagnostics\RuleEngine;
use LaravelRootCause\Support\ValueNormalizer;
use LaravelRootCause\Tests\TestCase;

class DiagnosisSnapshotTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function incidentProvider(): array
    {
        return [
            'validation failure' => ['validation-failure', 'validation-failure'],
            'exception' => ['exception', 'exception'],
            'duplicate query burst' => ['query-pathology', 'query-pathology'],
            'domain 422 remains undecided' => ['domain-422-no-diagnosis', 'no-diagnosis'],
            'handled exception remains undecided' => ['handled-report-no-diagnosis', 'no-diagnosis'],
        ];
    }

    /**
     * @dataProvider incidentProvider
     */
    public function test_public_incident_snapshots_match_the_rule_engine(string $fixture, string $snapshot): void
    {
        $trace = $this->loadFixture($fixture);
        $this->assertNotNull($this->app);

        $diagnosis = $this->app->make(RuleEngine::class)->diagnose($trace);
        /** @var array<string, mixed>|null $expected */
        $expected = json_decode(
            (string) file_get_contents(__DIR__.'/../../docs/incidents/snapshots/'.$snapshot.'.diagnosis.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame($this->normalizeDiagnosis($expected), $this->normalizeDiagnosis($diagnosis?->toArray()));
    }

    protected function loadFixture(string $fixture): TraceEnvelope
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode(
            (string) file_get_contents(__DIR__.'/../../docs/incidents/fixtures/'.$fixture.'.trace.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return TraceEnvelope::fromArray($payload);
    }

    /**
     * @param  array<string, mixed>|null  $diagnosis
     * @return array<string, mixed>|null
     */
    protected function normalizeDiagnosis(?array $diagnosis): ?array
    {
        if ($diagnosis === null) {
            return null;
        }

        return [
            'root_cause_category' => ValueNormalizer::nullableString($diagnosis['root_cause_category'] ?? null),
            'supporting_evidence' => array_map(
                fn (array $evidence): array => $this->normalizeEvidence($evidence),
                ValueNormalizer::listOfAssoc($diagnosis['supporting_evidence'] ?? [])
            ),
            'affected_files' => ValueNormalizer::stringList($diagnosis['affected_files'] ?? []),
            'repro' => ValueNormalizer::assoc($diagnosis['repro'] ?? []),
            'candidate_fix_count' => count(ValueNormalizer::stringList($diagnosis['candidate_fixes'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    protected function normalizeEvidence(array $evidence): array
    {
        $type = ValueNormalizer::string($evidence['type'] ?? null, 'unknown');

        return match ($type) {
            'validation_rule' => [
                'type' => $type,
                'source' => ValueNormalizer::nullableString($evidence['source'] ?? null),
                'field' => ValueNormalizer::nullableString($evidence['field'] ?? null),
                'rule' => ValueNormalizer::nullableString($evidence['rule'] ?? null),
            ],
            'input_keys' => [
                'type' => $type,
                'keys' => ValueNormalizer::stringList($evidence['keys'] ?? []),
            ],
            'route' => [
                'type' => $type,
                'route_name' => ValueNormalizer::nullableString($evidence['route_name'] ?? null),
                'controller' => ValueNormalizer::nullableString($evidence['controller'] ?? null),
            ],
            'exception' => [
                'type' => $type,
                'class' => ValueNormalizer::nullableString($evidence['class'] ?? null),
                'message' => ValueNormalizer::nullableString($evidence['message'] ?? null),
            ],
            'stack_frame' => [
                'type' => $type,
                'file' => ValueNormalizer::nullableString($evidence['file'] ?? null),
                'class' => ValueNormalizer::nullableString($evidence['class'] ?? null),
                'function' => ValueNormalizer::nullableString($evidence['function'] ?? null),
            ],
            'query_summary' => [
                'type' => $type,
                'fingerprint' => ValueNormalizer::nullableString($evidence['fingerprint'] ?? null),
                'count' => ValueNormalizer::int($evidence['count'] ?? null),
            ],
            'query_pathology' => [
                'type' => $type,
                'classification' => ValueNormalizer::nullableString($evidence['classification'] ?? null),
                'fingerprint' => ValueNormalizer::nullableString($evidence['fingerprint'] ?? null),
                'count' => ValueNormalizer::int($evidence['count'] ?? null),
            ],
            default => [
                'type' => $type,
            ],
        };
    }
}
