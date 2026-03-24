<?php

namespace LaravelRootCause\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LaravelRootCause\Collectors\ExceptionCollector;
use LaravelRootCause\Collectors\ValidationCollector;
use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Data\Evidence;
use LaravelRootCause\Data\Signal;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Diagnostics\RuleEngine;
use LaravelRootCause\Redaction\Redactor;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RootCause
{
    /**
     * @var array<string, TraceEnvelope>
     */
    protected array $activeTraces = [];

    public function __construct(
        protected Application $app,
        protected TraceRepository $repository,
        protected RootCauseContext $context,
        protected Redactor $redactor,
        protected ValidationCollector $validationCollector,
        protected ExceptionCollector $exceptionCollector,
        protected RuleEngine $ruleEngine,
    ) {}

    public function startRequest(Request $request): TraceEnvelope
    {
        $traceId = 'trc_'.Str::lower((string) Str::ulid());
        $route = $this->route($request);
        $requestInput = ValueNormalizer::assoc($request->all());
        $query = ValueNormalizer::assoc($request->query());
        $headers = ValueNormalizer::assoc($request->headers->all());

        $trace = new TraceEnvelope(
            traceId: $traceId,
            kind: 'http_request',
            startedAt: now()->toAtomString(),
            endedAt: null,
            environment: ValueNormalizer::string($this->app->environment(), 'unknown'),
            app: [
                'php_version' => PHP_VERSION,
                'laravel_version' => $this->app->version(),
            ],
            entrypoint: [
                'method' => $request->method(),
                'uri' => '/'.ltrim($request->path(), '/'),
                'route_name' => $route?->getName(),
                'controller' => $this->controllerAction($request),
                'middleware' => $route ? $route->gatherMiddleware() : [],
                'route_parameter_names' => $route ? array_keys($route->parametersWithoutNulls()) : [],
            ],
            context: [
                'user_id' => $this->userIdentifier($request),
                'request_url' => $request->url(),
                'input_keys' => $this->redactor->sanitizeInputKeys($requestInput),
                'input_shape' => $this->redactor->inputShape($requestInput),
                'query_keys' => array_keys($query),
                'header_keys' => $this->redactor->sanitizeHeaderKeys($headers),
            ],
        );

        if (! $this->isEnabled()) {
            return $trace;
        }

        $this->activeTraces[$traceId] = $trace;
        $this->context->begin($traceId, [
            'request_url' => $trace->context['request_url'],
            'actor_id' => $trace->context['user_id'],
        ]);

        return $trace;
    }

    public function currentTrace(): ?TraceEnvelope
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $traceId = $this->context->traceId();

        return $traceId ? ($this->activeTraces[$traceId] ?? null) : null;
    }

    public function recordSignal(Signal $signal): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $trace = $this->currentTrace();

        if (! $trace) {
            return;
        }

        $trace->addSignal($signal);
    }

    public function recordValidationException(Throwable $throwable, Request $request): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $trace = $this->currentTrace();

        if (! $trace || $this->hasSignalType($trace, 'validation_failed')) {
            return;
        }

        $this->setDiagnosticStatusCode(
            $trace,
            $throwable instanceof ValidationException ? $throwable->status : 422
        );
        $trace->addSignal($this->validationCollector->collect($throwable, $request));
    }

    public function recordValidationResponse(Response $response, Request $request): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $trace = $this->currentTrace();

        if (! $trace || $this->hasSignalType($trace, 'validation_failed')) {
            return;
        }

        $signal = $this->validationCollector->collectFromResponse($response, $request);

        if (! $signal) {
            return;
        }

        $this->setDiagnosticStatusCode($trace, 422);
        $trace->addSignal($signal);
    }

    public function recordException(Throwable $throwable): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $trace = $this->currentTrace();

        if (! $trace || $this->hasRecordedException($trace, $throwable)) {
            return;
        }

        $queryEvidence = $this->topQueryEvidence($trace);
        $signal = $this->exceptionCollector->collect($throwable, $queryEvidence);

        $this->setDiagnosticStatusCode(
            $trace,
            ValueNormalizer::int($signal->payload['status_code'] ?? null, $this->statusFromThrowable($throwable) ?? 500)
        );
        $trace->addSignal($signal);
    }

    public function finishRequest(?Response $response = null, ?Throwable $throwable = null, ?Request $request = null): ?TraceEnvelope
    {
        if (! $this->isEnabled()) {
            $this->context->clear();

            return null;
        }

        $trace = $this->currentTrace();

        if (! $trace) {
            return null;
        }

        $transportStatusCode = $this->resolvedTransportStatusCode($trace, $response, $throwable);
        $diagnosticStatusCode = $this->resolvedDiagnosticStatusCode($trace, $throwable, $transportStatusCode);
        $this->setTransportStatusCode($trace, $transportStatusCode);
        $this->setDiagnosticStatusCode($trace, $diagnosticStatusCode);
        $trace->endedAt = now()->toAtomString();

        $this->appendQueryPathology($trace);
        $trace->diagnosis = $this->ruleEngine->diagnose($trace);

        try {
            $this->persistTrace($trace);
        } finally {
            unset($this->activeTraces[$trace->traceId]);
            $this->context->clear();
        }

        return $trace;
    }

    public function refreshStoredTraceResponse(string $traceId, Response $response, ?Throwable $throwable = null): ?TraceEnvelope
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $trace = $this->repository->find($traceId);

        if (! $trace) {
            return null;
        }

        $transportStatusCode = $this->resolvedTransportStatusCode($trace, $response, $throwable);
        $diagnosticStatusCode = $this->resolvedDiagnosticStatusCode($trace, $throwable, $transportStatusCode);

        if (
            $trace->transportStatusCode($transportStatusCode) === $transportStatusCode
            && $trace->diagnosticStatusCode($diagnosticStatusCode) === $diagnosticStatusCode
        ) {
            return $trace;
        }

        $this->setTransportStatusCode($trace, $transportStatusCode);
        $this->setDiagnosticStatusCode($trace, $diagnosticStatusCode);
        $trace->diagnosis = $this->ruleEngine->diagnose($trace);

        $this->persistTrace($trace);

        return $trace;
    }

    protected function isEnabled(): bool
    {
        return RootCauseToggle::enabled();
    }

    /**
     * @return array<int, Evidence>
     */
    protected function topQueryEvidence(TraceEnvelope $trace): array
    {
        $querySignals = $trace->signalsOfType('query_executed');

        if ($querySignals === []) {
            return [];
        }

        /** @var array<string, array{fingerprint: string, count: int, duration_ms: float}> $groups */
        $groups = [];

        foreach ($querySignals as $signal) {
            $fingerprint = ValueNormalizer::string($signal->payload['fingerprint'] ?? null, 'unknown');

            if (! isset($groups[$fingerprint])) {
                $groups[$fingerprint] = [
                    'fingerprint' => $fingerprint,
                    'count' => 0,
                    'duration_ms' => 0.0,
                ];
            }

            $groups[$fingerprint]['count']++;
            $groups[$fingerprint]['duration_ms'] += ValueNormalizer::float($signal->payload['duration_ms'] ?? null);
        }

        uasort($groups, static function (array $left, array $right): int {
            return [$right['count'], $right['duration_ms']] <=> [$left['count'], $left['duration_ms']];
        });

        $top = array_slice(array_values($groups), 0, ValueNormalizer::int(config('root_cause.diagnostics.top_query_examples', 3), 3));

        return array_map(static fn (array $item) => new Evidence('query_summary', $item), $top);
    }

    protected function appendQueryPathology(TraceEnvelope $trace): void
    {
        $querySignals = $trace->signalsOfType('query_executed');

        if ($querySignals === []) {
            return;
        }

        /** @var array<string, array{fingerprint: string, count: int, total_duration_ms: float, table_candidates: mixed, connection: mixed, worst_offender_frame: mixed}> $groups */
        $groups = [];

        foreach ($querySignals as $signal) {
            $fingerprint = ValueNormalizer::string($signal->payload['fingerprint'] ?? null, 'unknown');

            if (! isset($groups[$fingerprint])) {
                $groups[$fingerprint] = [
                    'fingerprint' => $fingerprint,
                    'count' => 0,
                    'total_duration_ms' => 0.0,
                    'table_candidates' => $signal->payload['table_candidates'] ?? [],
                    'connection' => $signal->payload['connection'] ?? 'default',
                    'worst_offender_frame' => $signal->payload['caller_frame'] ?? null,
                ];
            }

            $groups[$fingerprint]['count']++;
            $groups[$fingerprint]['total_duration_ms'] += ValueNormalizer::float($signal->payload['duration_ms'] ?? null);
        }

        uasort($groups, static function (array $left, array $right): int {
            return [$right['count'], $right['total_duration_ms']] <=> [$left['count'], $left['total_duration_ms']];
        });

        $duplicateThreshold = ValueNormalizer::int(config('root_cause.collectors.query.duplicate_threshold', 5), 5);
        $nPlusOneThreshold = ValueNormalizer::int(config('root_cause.collectors.query.n_plus_one_threshold', 3), 3);
        $pathology = $this->queryPathologyCandidate($groups, $duplicateThreshold, $nPlusOneThreshold);

        if ($pathology === null) {
            return;
        }

        $trace->addSignal(new Signal(
            type: 'query_pathology_suspected',
            capturedAt: now()->toAtomString(),
            payload: $pathology,
            evidence: [
                new Evidence('query_pathology', [
                    'classification' => $pathology['classification'],
                    'fingerprint' => $pathology['fingerprint'],
                    'count' => $pathology['count'],
                    'duration_ms' => round($pathology['total_duration_ms'], 2),
                ]),
            ],
        ));
    }

    /**
     * @param  array<string, array{fingerprint: string, count: int, total_duration_ms: float, table_candidates: mixed, connection: mixed, worst_offender_frame: mixed}>  $groups
     * @return array{fingerprint: string, count: int, total_duration_ms: float, table_candidates: mixed, connection: mixed, worst_offender_frame: mixed, classification: string}|null
     */
    protected function queryPathologyCandidate(array $groups, int $duplicateThreshold, int $nPlusOneThreshold): ?array
    {
        $nPlusOne = null;
        $duplicate = null;

        foreach (array_values($groups) as $group) {
            if ($group['count'] >= $nPlusOneThreshold && $this->looksLikeNPlusOneFrame($group['worst_offender_frame'])) {
                $nPlusOne = $group + ['classification' => 'n_plus_one_suspected'];

                break;
            }

            if ($duplicate === null && $group['count'] >= $duplicateThreshold) {
                $duplicate = $group + ['classification' => 'duplicate_query_burst'];
            }
        }

        return $nPlusOne ?? $duplicate;
    }

    protected function looksLikeNPlusOneFrame(mixed $frame): bool
    {
        if (! is_array($frame)) {
            return false;
        }

        $haystacks = [
            ValueNormalizer::string($frame['file'] ?? null),
            ValueNormalizer::string($frame['class'] ?? null),
        ];

        foreach ($haystacks as $haystack) {
            if (str_contains($haystack, 'Models') ||
                str_contains($haystack, 'Resources') ||
                str_contains($haystack, 'Controllers') ||
                str_contains($haystack, 'views')) {
                return true;
            }
        }

        return false;
    }

    protected function resolvedTransportStatusCode(TraceEnvelope $trace, ?Response $response, ?Throwable $throwable): int
    {
        if ($response instanceof StreamedResponse && $throwable instanceof Throwable) {
            return $this->statusFromThrowable($throwable) ?? $trace->transportStatusCode(500);
        }

        if ($response instanceof Response) {
            return $response->getStatusCode();
        }

        return $this->statusFromThrowable($throwable) ?? $trace->transportStatusCode(200);
    }

    protected function resolvedDiagnosticStatusCode(TraceEnvelope $trace, ?Throwable $throwable, int $transportStatusCode): int
    {
        if ($this->hasSignalType($trace, 'validation_failed')) {
            return $this->statusFromThrowable($throwable) ?? $trace->diagnosticStatusCode(422);
        }

        if ($this->hasSignalType($trace, 'exception_thrown')) {
            return $this->statusFromThrowable($throwable) ?? $trace->diagnosticStatusCode(500);
        }

        return $trace->diagnosticStatusCode($transportStatusCode);
    }

    protected function setTransportStatusCode(TraceEnvelope $trace, int $statusCode): void
    {
        $trace->response['status_code'] = $statusCode;
        $trace->response['transport_status_code'] = $statusCode;
    }

    protected function setDiagnosticStatusCode(TraceEnvelope $trace, int $statusCode): void
    {
        $trace->response['diagnostic_status_code'] = $statusCode;
    }

    protected function statusFromThrowable(?Throwable $throwable): ?int
    {
        return ThrowableStatusResolver::resolve($throwable);
    }

    protected function persistTrace(TraceEnvelope $trace): void
    {
        try {
            $this->repository->save($trace);
        } catch (Throwable $exception) {
            $this->reportPersistenceException($exception);
        }
    }

    protected function reportPersistenceException(Throwable $exception): void
    {
        if (! $this->app->bound(ExceptionHandler::class)) {
            return;
        }

        try {
            $this->app->make(ExceptionHandler::class)->report($exception);
        } catch (Throwable) {
            // Persistence failures should never change the request outcome.
        }
    }

    protected function controllerAction(Request $request): ?string
    {
        $route = $this->route($request);

        if (! $route) {
            return null;
        }

        $action = $route->getActionName();

        return $action === 'Closure' ? null : $action;
    }

    protected function route(Request $request): ?Route
    {
        /** @var mixed $resolvedRoute */
        $resolvedRoute = $request->route();

        return $resolvedRoute instanceof Route ? $resolvedRoute : null;
    }

    protected function userIdentifier(Request $request): mixed
    {
        $user = $request->user();

        if (! is_object($user) || ! method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        return $user->getAuthIdentifier();
    }

    protected function hasSignalType(TraceEnvelope $trace, string $type): bool
    {
        return $trace->signalsOfType($type) !== [];
    }

    protected function hasRecordedException(TraceEnvelope $trace, Throwable $throwable): bool
    {
        foreach ($trace->signalsOfType('exception_thrown') as $signal) {
            if (($signal->payload['exception_class'] ?? null) === $throwable::class &&
                ($signal->payload['message'] ?? null) === $throwable->getMessage()) {
                return true;
            }
        }

        return false;
    }
}
