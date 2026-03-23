<?php

namespace LaravelRootCause\Diagnostics;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use LaravelRootCause\Data\DiagnosisReport;
use LaravelRootCause\Data\Evidence;
use LaravelRootCause\Data\Signal;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Support\ClassFileResolver;
use LaravelRootCause\Support\ValueNormalizer;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RuleEngine
{
    public function __construct(
        protected ConfidenceScorer $confidenceScorer,
        protected CandidateFixGenerator $candidateFixGenerator,
        protected ClassFileResolver $classFileResolver,
    ) {}

    public function diagnose(TraceEnvelope $trace): ?DiagnosisReport
    {
        if ($validation = $this->diagnoseValidationMismatch($trace)) {
            return $validation;
        }

        if ($binding = $this->diagnoseMissingRouteBinding($trace)) {
            return $binding;
        }

        if ($pathology = $this->diagnoseQueryPathology($trace)) {
            return $pathology;
        }

        if ($exception = $this->diagnoseUnhandledException($trace)) {
            return $exception;
        }

        return null;
    }

    protected function diagnoseValidationMismatch(TraceEnvelope $trace): ?DiagnosisReport
    {
        $signal = $trace->signalsOfType('validation_failed')[0] ?? null;

        if (! $signal) {
            return null;
        }

        $failedFields = array_keys(ValueNormalizer::assoc($signal->payload['failed_fields'] ?? []));
        $inputKeys = ValueNormalizer::stringList($signal->payload['input_keys'] ?? []);
        $formRequest = ValueNormalizer::nullableString($signal->payload['form_request'] ?? null);
        $controller = ValueNormalizer::nullableString($trace->entrypoint['controller'] ?? null);
        $statusCode = $trace->diagnosticStatusCode(422);

        return new DiagnosisReport(
            summary: sprintf(
                'Error %d occurred due to a mismatch between %s and payload.',
                $statusCode,
                $formRequest ? class_basename($formRequest) : 'Validation contract'
            ),
            rootCauseCategory: 'validation_contract_mismatch',
            confidence: $this->confidenceScorer->validationContractMismatch($failedFields, $formRequest, $inputKeys),
            supportingEvidence: $signal->evidence,
            affectedFiles: array_values(array_filter([
                $this->classFileResolver->resolve($formRequest),
                $this->resolveControllerFile($controller),
            ])),
            candidateFixes: $this->candidateFixGenerator->forValidationMismatch($failedFields, $inputKeys, $formRequest),
            repro: [
                'method' => ValueNormalizer::string($trace->entrypoint['method'] ?? null, 'GET'),
                'uri' => ValueNormalizer::string($trace->entrypoint['uri'] ?? null, '/'),
                'route_name' => ValueNormalizer::nullableString($trace->entrypoint['route_name'] ?? null),
                'payload_keys' => $inputKeys,
            ],
            tokenBudgetHint: $this->tokenBudgetHint(),
        );
    }

    protected function diagnoseMissingRouteBinding(TraceEnvelope $trace): ?DiagnosisReport
    {
        $signal = $trace->signalsOfType('exception_thrown')[0] ?? null;

        if (! $signal) {
            return null;
        }

        $exceptionClass = ValueNormalizer::string($signal->payload['exception_class'] ?? null);
        $model = ValueNormalizer::nullableString($signal->payload['model'] ?? null);

        if (! in_array($exceptionClass, [ModelNotFoundException::class, NotFoundHttpException::class], true)) {
            return null;
        }

        if ($exceptionClass === NotFoundHttpException::class && $model === null) {
            return null;
        }

        $controller = ValueNormalizer::nullableString($trace->entrypoint['controller'] ?? null);
        $routeParameterNames = ValueNormalizer::stringList($trace->entrypoint['route_parameter_names'] ?? []);

        if (! $this->isRouteBindingContext($model, $routeParameterNames, $controller)) {
            return null;
        }

        return new DiagnosisReport(
            summary: 'Route model binding or 404 route resolution failed',
            rootCauseCategory: 'missing_route_binding',
            confidence: $this->confidenceScorer->missingRouteBinding(
                $model,
                $controller !== null
            ),
            supportingEvidence: $signal->evidence,
            affectedFiles: array_values(array_filter([
                $this->resolveControllerFile($controller),
                $model ? $this->classFileResolver->resolve($model) : null,
            ])),
            candidateFixes: $this->candidateFixGenerator->forMissingRouteBinding(
                $model,
                $routeParameterNames
            ),
            repro: [
                'method' => ValueNormalizer::string($trace->entrypoint['method'] ?? null, 'GET'),
                'uri' => ValueNormalizer::string($trace->entrypoint['uri'] ?? null, '/'),
                'route_name' => ValueNormalizer::nullableString($trace->entrypoint['route_name'] ?? null),
                'route_parameter_names' => $routeParameterNames,
            ],
            tokenBudgetHint: $this->tokenBudgetHint(),
        );
    }

    protected function diagnoseQueryPathology(TraceEnvelope $trace): ?DiagnosisReport
    {
        $signal = $trace->signalsOfType('query_pathology_suspected')[0] ?? null;

        if (! $signal) {
            return null;
        }

        $classification = ValueNormalizer::string($signal->payload['classification'] ?? null, 'duplicate_query_burst');
        $frame = $signal->payload['worst_offender_frame'] ?? null;
        $frameFile = is_array($frame) ? ValueNormalizer::nullableString($frame['file'] ?? null) : null;
        $queryCount = ValueNormalizer::int($signal->payload['count'] ?? null);

        return new DiagnosisReport(
            summary: $classification === 'n_plus_one_suspected'
                ? 'N+1 is suspected due to the repetition of the same query fingerprint.'
                : 'The same query fingerprint is being repeated in a short period of time.',
            rootCauseCategory: $classification,
            confidence: $this->confidenceScorer->queryPathology(
                $classification,
                $queryCount,
                is_string($frameFile)
            ),
            supportingEvidence: $signal->evidence,
            affectedFiles: array_values(array_filter([
                is_string($frameFile) ? $frameFile : null,
                $this->resolveControllerFile(ValueNormalizer::nullableString($trace->entrypoint['controller'] ?? null)),
            ])),
            candidateFixes: $this->candidateFixGenerator->forQueryPathology($signal->payload),
            repro: [
                'method' => ValueNormalizer::string($trace->entrypoint['method'] ?? null, 'GET'),
                'uri' => ValueNormalizer::string($trace->entrypoint['uri'] ?? null, '/'),
                'query_fingerprint' => ValueNormalizer::nullableString($signal->payload['fingerprint'] ?? null),
                'query_count' => $queryCount,
            ],
            tokenBudgetHint: $this->tokenBudgetHint(),
        );
    }

    protected function diagnoseUnhandledException(TraceEnvelope $trace): ?DiagnosisReport
    {
        $signal = $trace->signalsOfType('exception_thrown')[0] ?? null;

        if (! $signal) {
            return null;
        }

        $frames = ValueNormalizer::listOfAssoc($signal->payload['application_frames'] ?? []);
        $exceptionClass = ValueNormalizer::string($signal->payload['exception_class'] ?? null, 'Exception');
        $statusCode = $trace->diagnosticStatusCode(500);
        $controller = ValueNormalizer::nullableString($trace->entrypoint['controller'] ?? null);
        $firstFrame = $frames[0] ?? null;
        $firstFrameFile = is_array($firstFrame) ? ValueNormalizer::nullableString($firstFrame['file'] ?? null) : null;

        return new DiagnosisReport(
            summary: sprintf(
                'An Unhandled %s returned a %d error.',
                class_basename($exceptionClass),
                $statusCode
            ),
            rootCauseCategory: 'unhandled_exception',
            confidence: $this->confidenceScorer->unhandledException(
                $frames !== [],
                $this->hasEvidenceType($signal, 'query_summary')
            ),
            supportingEvidence: $signal->evidence,
            affectedFiles: array_values(array_filter([
                $firstFrameFile,
                $this->resolveControllerFile($controller),
            ])),
            candidateFixes: $this->candidateFixGenerator->forUnhandledException($exceptionClass, $frames),
            repro: [
                'method' => ValueNormalizer::string($trace->entrypoint['method'] ?? null, 'GET'),
                'uri' => ValueNormalizer::string($trace->entrypoint['uri'] ?? null, '/'),
                'exception_class' => $exceptionClass,
            ],
            tokenBudgetHint: $this->tokenBudgetHint(),
        );
    }

    protected function resolveControllerFile(?string $controller): ?string
    {
        $target = $this->controllerTarget($controller);

        if ($target === null) {
            return null;
        }

        return $this->classFileResolver->resolve($target['class']);
    }

    /**
     * @return array{class: string, method: string}|null
     */
    protected function controllerTarget(?string $controller): ?array
    {
        if (! is_string($controller) || $controller === '') {
            return null;
        }

        if (str_contains($controller, '@')) {
            [$class, $method] = explode('@', $controller, 2);

            return ['class' => $class, 'method' => $method];
        }

        if (class_exists($controller) && method_exists($controller, '__invoke')) {
            return ['class' => $controller, 'method' => '__invoke'];
        }

        return null;
    }

    protected function hasEvidenceType(Signal $signal, string $type): bool
    {
        return collect($signal->evidence)->contains(
            static fn (Evidence $evidence): bool => $evidence->type === $type
        );
    }

    protected function tokenBudgetHint(): string
    {
        try {
            return ValueNormalizer::string(config('root_cause.diagnostics.token_budget_hint', 'small'), 'small');
        } catch (\Throwable) {
            return 'small';
        }
    }

    /**
     * @param  array<int, string>  $routeParameterNames
     */
    protected function isRouteBindingContext(?string $model, array $routeParameterNames, ?string $controller): bool
    {
        if ($model === null || $routeParameterNames === []) {
            return false;
        }

        $controllerMatch = $this->controllerRouteBindingMatch($controller, $model, $routeParameterNames);

        if ($controllerMatch !== null) {
            return $controllerMatch;
        }

        return array_intersect($routeParameterNames, $this->expectedRouteParameterNames($model)) !== [];
    }

    /**
     * @param  array<int, string>  $routeParameterNames
     */
    protected function controllerRouteBindingMatch(?string $controller, string $model, array $routeParameterNames): ?bool
    {
        $target = $this->controllerTarget($controller);

        if ($target === null) {
            return null;
        }

        $class = $target['class'];
        $method = $target['method'];

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return null;
        }

        foreach ($reflection->getParameters() as $parameter) {
            if (! in_array($parameter->getName(), $routeParameterNames, true)) {
                continue;
            }

            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            if ($type->getName() === $model) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function expectedRouteParameterNames(string $model): array
    {
        $basename = class_basename($model);

        return array_values(array_unique([
            Str::snake($basename),
            Str::camel($basename),
            lcfirst($basename),
        ]));
    }
}
