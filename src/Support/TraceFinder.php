<?php

namespace LaravelRootCause\Support;

use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Data\TraceEnvelope;

class TraceFinder
{
    public function __construct(protected TraceRepository $repository) {}

    public function resolve(?string $traceId = 'latest'): ?TraceEnvelope
    {
        if (! $traceId || $traceId === 'latest') {
            return $this->repository->latest();
        }

        return $this->repository->find($traceId);
    }

    public function latestFailed(): ?TraceEnvelope
    {
        foreach ($this->repository->recent(PHP_INT_MAX) as $trace) {
            if ($trace->diagnosticStatusCode(200) >= 400) {
                return $trace;
            }
        }

        return null;
    }

    public function latestQueryPathology(): ?TraceEnvelope
    {
        foreach ($this->repository->recent(PHP_INT_MAX) as $trace) {
            $category = $trace->diagnosis?->rootCauseCategory;

            if (in_array($category, ['n_plus_one_suspected', 'duplicate_query_burst'], true)) {
                return $trace;
            }
        }

        return null;
    }

    /**
     * @return array<int, TraceEnvelope>
     */
    public function recentDiagnoses(int $limit = 20): array
    {
        return array_values(array_filter(
            $this->repository->recent($limit),
            static fn (TraceEnvelope $trace): bool => $trace->diagnosis !== null
        ));
    }
}
