<?php

namespace LaravelRootCause\Data;

use LaravelRootCause\Support\ValueNormalizer;

class TraceEnvelope
{
    /**
     * @param  array<string, mixed>  $app
     * @param  array<string, mixed>  $entrypoint
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $response
     * @param  array<int, Signal>  $signals
     */
    public function __construct(
        public string $traceId,
        public string $kind,
        public string $startedAt,
        public ?string $endedAt,
        public string $environment,
        public array $app,
        public array $entrypoint,
        public array $context,
        public array $response = [],
        public array $signals = [],
        public ?DiagnosisReport $diagnosis = null,
    ) {}

    public function addSignal(Signal $signal): void
    {
        $this->signals[] = $signal;
    }

    /**
     * @return array<int, Signal>
     */
    public function signalsOfType(string $type): array
    {
        return array_values(array_filter(
            $this->signals,
            static fn (Signal $signal): bool => $signal->type === $type
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'kind' => $this->kind,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'environment' => $this->environment,
            'app' => $this->app,
            'entrypoint' => $this->entrypoint,
            'context' => $this->context,
            'response' => $this->response,
            'signals' => array_map(static fn (Signal $signal) => $signal->toArray(), $this->signals),
            'diagnosis' => $this->diagnosis?->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $signals = array_map(
            static fn (array $item): Signal => Signal::fromArray($item),
            ValueNormalizer::listOfAssoc($payload['signals'] ?? [])
        );
        $diagnosisPayload = $payload['diagnosis'] ?? null;

        return new self(
            ValueNormalizer::string($payload['trace_id'] ?? null),
            ValueNormalizer::string($payload['kind'] ?? null, 'http_request'),
            ValueNormalizer::string($payload['started_at'] ?? null, now()->toAtomString()),
            ValueNormalizer::nullableString($payload['ended_at'] ?? null),
            ValueNormalizer::string($payload['environment'] ?? null, 'unknown'),
            ValueNormalizer::assoc($payload['app'] ?? []),
            ValueNormalizer::assoc($payload['entrypoint'] ?? []),
            ValueNormalizer::assoc($payload['context'] ?? []),
            ValueNormalizer::assoc($payload['response'] ?? []),
            $signals,
            is_array($diagnosisPayload)
                ? DiagnosisReport::fromArray(ValueNormalizer::assoc($diagnosisPayload))
                : null
        );
    }
}
