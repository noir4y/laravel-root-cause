<?php

namespace LaravelRootCause\Contracts;

use LaravelRootCause\Data\TraceEnvelope;

interface TraceRepository
{
    public function save(TraceEnvelope $trace): void;

    public function find(string $traceId): ?TraceEnvelope;

    public function latest(): ?TraceEnvelope;

    /**
     * @return array<int, TraceEnvelope>
     */
    public function recent(int $limit = 20): array;
}
