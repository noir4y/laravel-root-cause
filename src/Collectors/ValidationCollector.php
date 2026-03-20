<?php

namespace LaravelRootCause\Collectors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
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

        return $this->signalFromValidationData($failed, $messages, $input, $routeData, $formRequest);
    }

    public function collectFromResponse(Response $response, Request $request): ?Signal
    {
        if ($response->getStatusCode() !== 422) {
            return null;
        }

        $exception = $response->exception ?? null;

        if (! $exception instanceof ValidationException) {
            return $this->collectFromValidationPayload($response, $request);
        }

        return $this->collect($exception, $request);
    }

    protected function collectFromValidationPayload(Response $response, Request $request): ?Signal
    {
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $message = ValueNormalizer::string($payload['message'] ?? null);

        /** @var array<string, array<int, string>> $messages */
        $messages = [];

        foreach (ValueNormalizer::assoc($payload['errors'] ?? []) as $field => $errors) {
            if (! is_array($errors)) {
                return null;
            }

            $normalizedErrors = array_values(array_filter($errors, 'is_string'));

            if ($normalizedErrors === []) {
                return null;
            }

            $messages[$field] = $normalizedErrors;
        }

        if ($messages === []) {
            return null;
        }

        $input = ValueNormalizer::assoc($request->all());

        if (
            $message !== $this->expectedValidationMessage()
            && ! $this->looksLikeValidationPayload(array_keys($messages), array_keys($input))
        ) {
            return null;
        }

        $failed = [];

        foreach (array_keys($messages) as $field) {
            $failed[$field] = ['reported' => true];
        }

        return $this->signalFromValidationData(
            $failed,
            $messages,
            $input,
            $this->routeData($request),
            null
        );
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

    protected function expectedValidationMessage(): string
    {
        try {
            $translated = trans('validation.invalid');

            if (is_string($translated) && $translated !== 'validation.invalid') {
                return $translated;
            }
        } catch (Throwable) {
            // Fall back to Laravel's default English message when translation services are unavailable.
        }

        return 'The given data was invalid.';
    }

    /**
     * @param  array<int, string>  $messageFields
     * @param  array<int, string>  $inputKeys
     */
    protected function looksLikeValidationPayload(array $messageFields, array $inputKeys): bool
    {
        if ($messageFields === []) {
            return false;
        }

        if ($inputKeys === []) {
            return true;
        }

        $normalizedInputRoots = array_values(array_unique(array_filter(array_map(
            fn (string $key): string => $this->normalizeFieldKey($key),
            $inputKeys
        ))));

        foreach ($messageFields as $field) {
            $normalizedField = $this->normalizeFieldKey($field);

            if ($normalizedField === '') {
                return false;
            }

            if (in_array($normalizedField, $normalizedInputRoots, true)) {
                continue;
            }

            return false;
        }

        return true;
    }

    protected function normalizeFieldKey(string $key): string
    {
        $segments = preg_split('/[.\[]+/', Str::lower($key));
        $root = is_array($segments) ? ($segments[0] ?? '') : '';

        return trim((string) $root, '] ');
    }

    /**
     * @param  array<string, array<string, mixed>>  $failed
     * @param  array<string, array<int, string>>  $messages
     * @param  array<string, mixed>  $input
     * @param  array{route_name: string|null, controller: string|null}  $routeData
     */
    protected function signalFromValidationData(
        array $failed,
        array $messages,
        array $input,
        array $routeData,
        ?string $formRequest,
    ): Signal {
        $evidence = [];

        foreach ($failed as $field => $rules) {
            foreach (array_keys($rules) as $rule) {
                $evidence[] = new Evidence('validation_rule', [
                    'source' => $formRequest,
                    'field' => $field,
                    'rule' => strtolower((string) $rule),
                    'message' => $messages[$field][0] ?? null,
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
}
