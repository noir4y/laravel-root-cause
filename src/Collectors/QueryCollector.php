<?php

namespace LaravelRootCause\Collectors;

use Illuminate\Database\Events\QueryExecuted;
use LaravelRootCause\Data\Evidence;
use LaravelRootCause\Data\Signal;
use LaravelRootCause\Redaction\Redactor;
use LaravelRootCause\Support\QueryFingerprint;
use LaravelRootCause\Support\RootCause;
use LaravelRootCause\Support\StackFrameResolver;

class QueryCollector
{
    public function __construct(
        protected RootCause $rootCause,
        protected Redactor $redactor,
        protected StackFrameResolver $stackFrameResolver,
    ) {}

    public function record(QueryExecuted $query): void
    {
        if (! $this->rootCause->currentTrace()) {
            return;
        }

        $fingerprint = QueryFingerprint::fromSql($query->sql);
        $callerFrame = $this->stackFrameResolver->firstApplicationFrameFromTrace(
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30)
        );

        $this->rootCause->recordSignal(new Signal(
            type: 'query_executed',
            capturedAt: now()->toAtomString(),
            payload: [
                'fingerprint' => $fingerprint['fingerprint'],
                'table_candidates' => $fingerprint['tables'],
                'bindings_count' => $this->redactor->sanitizeBindingsCount(array_values($query->bindings)),
                'duration_ms' => round((float) $query->time, 2),
                'connection' => $query->connectionName,
                'caller_frame' => $callerFrame,
            ],
            evidence: [
                new Evidence('query_fingerprint', [
                    'fingerprint' => $fingerprint['fingerprint'],
                    'tables' => $fingerprint['tables'],
                    'duration_ms' => round((float) $query->time, 2),
                ]),
            ],
        ));
    }
}
