<?php

namespace LaravelRootCause\Export;

use LaravelRootCause\Data\DiagnosisReport;
use LaravelRootCause\Data\Evidence;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Support\ValueNormalizer;

class CliTraceFormatter
{
    public function format(TraceEnvelope $trace): string
    {
        if (! $trace->diagnosis instanceof DiagnosisReport) {
            return implode(PHP_EOL, [
                sprintf('Trace: %s', $trace->traceId),
                'No diagnosis available.',
            ]);
        }

        $diagnosis = $trace->diagnosis;
        $lines = [
            sprintf('Trace: %s', $trace->traceId),
            sprintf('Root cause: %s', $diagnosis->rootCauseCategory),
            sprintf('Confidence: %.2f', $diagnosis->confidence),
            '',
            sprintf('Summary: %s', $diagnosis->summary),
            '',
            'Evidence',
        ];

        foreach (array_slice($diagnosis->supportingEvidence, 0, 6) as $evidence) {
            $lines[] = '- '.$this->renderEvidence($evidence);
        }

        if ($diagnosis->affectedFiles !== []) {
            $lines[] = '';
            $lines[] = 'Likely files';

            foreach ($diagnosis->affectedFiles as $file) {
                $lines[] = '- '.$file;
            }
        }

        if ($diagnosis->candidateFixes !== []) {
            $lines[] = '';
            $lines[] = 'Suggested fix';

            foreach ($diagnosis->candidateFixes as $fix) {
                $lines[] = '- '.$fix;
            }
        }

        if ($diagnosis->repro !== []) {
            $lines[] = '';
            $lines[] = 'Repro';
            $lines[] = '- '.json_encode($diagnosis->repro, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return implode(PHP_EOL, $lines);
    }

    protected function renderEvidence(Evidence $evidence): string
    {
        return match ($evidence->type) {
            'validation_rule' => sprintf(
                '%s failed on %s.%s',
                class_basename(ValueNormalizer::string($evidence->attributes['source'] ?? null, 'validation')),
                ValueNormalizer::string($evidence->attributes['field'] ?? null, 'unknown'),
                ValueNormalizer::string($evidence->attributes['rule'] ?? null, 'rule')
            ),
            'input_keys' => sprintf(
                'Payload keys: [%s]',
                implode(', ', ValueNormalizer::stringList($evidence->attributes['keys'] ?? []))
            ),
            'route' => sprintf(
                'Route: %s / Controller: %s',
                ValueNormalizer::string($evidence->attributes['route_name'] ?? null, 'unknown'),
                ValueNormalizer::string($evidence->attributes['controller'] ?? null, 'unknown')
            ),
            'query_pathology' => sprintf(
                '%s repeated %d times',
                ValueNormalizer::string($evidence->attributes['fingerprint'] ?? null, 'query'),
                ValueNormalizer::int($evidence->attributes['count'] ?? null)
            ),
            'exception' => sprintf(
                '%s: %s',
                class_basename(ValueNormalizer::string($evidence->attributes['class'] ?? null, 'Exception')),
                ValueNormalizer::string($evidence->attributes['message'] ?? null)
            ),
            'stack_frame' => sprintf(
                '%s:%d',
                ValueNormalizer::string($evidence->attributes['file'] ?? null, 'unknown'),
                ValueNormalizer::int($evidence->attributes['line'] ?? null)
            ),
            'query_summary' => sprintf(
                'Query fingerprint seen %d times (%sms total)',
                ValueNormalizer::int($evidence->attributes['count'] ?? null),
                round(ValueNormalizer::float($evidence->attributes['duration_ms'] ?? null), 2)
            ),
            default => json_encode($evidence->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        };
    }
}
