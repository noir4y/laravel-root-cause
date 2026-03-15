<?php

namespace LaravelRootCause\Collectors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use LaravelRootCause\Data\Evidence;
use LaravelRootCause\Data\Signal;
use LaravelRootCause\Redaction\Redactor;
use LaravelRootCause\Support\ValueNormalizer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ValidationCollector
{
    public function __construct(protected Redactor $redactor) {}

    public function collect(Throwable $throwable, Request $request): Signal
    {
        if (! $throwable instanceof ValidationException) {
            throw new \InvalidArgumentException('ValidationCollector expects a ValidationException.');
        }

        $validator = $throwable->validator;
        /** @var array<string, array<string, mixed>> $failed */
        $failed = $validator->failed();
        $input = $validator instanceof Validator
            ? ValueNormalizer::assoc($validator->getData())
            : ValueNormalizer::assoc($request->all());
        /** @var array<string, array<int, string>> $messages */
        $messages = $throwable->errors();
        $formRequest = $this->guessFormRequestClass($throwable->getTrace());
        $routeData = $this->routeData($request);

        $evidence = [];

        foreach ($failed as $field => $rules) {
            foreach (array_keys($rules) as $rule) {
                $message = $messages[$field][0] ?? null;

                $evidence[] = new Evidence('validation_rule', [
                    'source' => $formRequest,
                    'field' => $field,
                    'rule' => strtolower((string) $rule),
                    'message' => $message,
                ]);
            }
        }

        $evidence[] = new Evidence('input_keys', [
            'keys' => $this->redactor->sanitizeInputKeys($input),
        ]);

        $evidence[] = new Evidence('route', $routeData);

        return new Signal(
            type: 'validation_failed',
            capturedAt: now()->toAtomString(),
            payload: [
                'form_request' => $formRequest,
                'failed_fields' => $this->normalizeFailedRules($failed),
                'input_keys' => $this->redactor->sanitizeInputKeys($input),
                'input_shape' => $this->redactor->inputShape($input),
                'route' => [
                    'name' => $routeData['route_name'],
                    'controller' => $routeData['controller'],
                ],
            ],
            evidence: $evidence,
        );
    }

    public function collectFromResponse(Response $response, Request $request): ?Signal
    {
        if ($response->getStatusCode() !== 422) {
            return null;
        }

        $exception = $response->exception ?? null;

        if (! $exception instanceof ValidationException) {
            return null;
        }

        return $this->collect($exception, $request);
    }

    /**
     * @return array{route_name: string|null, controller: string|null}
     */
    protected function routeData(Request $request): array
    {
        /** @var mixed $resolvedRoute */
        $resolvedRoute = $request->route();
        $route = $resolvedRoute instanceof Route ? $resolvedRoute : null;

        return [
            'route_name' => $route?->getName(),
            'controller' => $route?->getActionName(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $trace
     */
    protected function guessFormRequestClass(array $trace): ?string
    {
        foreach ($trace as $frame) {
            $class = $frame['class'] ?? null;

            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (is_subclass_of($class, FormRequest::class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $failed
     * @return array<string, array<int, string>>
     */
    protected function normalizeFailedRules(array $failed): array
    {
        $normalized = [];

        foreach ($failed as $field => $rules) {
            $normalized[$field] = array_map(static fn (string $rule): string => strtolower($rule), array_keys($rules));
        }

        return $normalized;
    }
}
