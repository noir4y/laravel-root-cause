<?php

namespace LaravelRootCause\Collectors;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LaravelRootCause\Data\Evidence;
use LaravelRootCause\Data\Signal;
use LaravelRootCause\Redaction\Redactor;
use LaravelRootCause\Support\StackFrameResolver;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ExceptionCollector
{
    public function __construct(
        protected StackFrameResolver $stackFrameResolver,
        protected Redactor $redactor,
    ) {}

    /**
     * @param  array<int, Evidence>  $queryEvidence
     */
    public function collect(Throwable $throwable, array $queryEvidence = []): Signal
    {
        $frames = $this->stackFrameResolver->applicationFramesFromThrowable($throwable);
        $message = $this->redactor->sanitizeExceptionMessage($throwable);
        $modelNotFound = $this->modelNotFound($throwable);
        $payload = [
            'exception_class' => $throwable::class,
            'message' => $message,
            'status_code' => $this->statusCode($throwable),
            'application_frames' => $frames,
        ];

        if ($modelNotFound) {
            $payload['model'] = $modelNotFound->getModel();
            $payload['ids'] = $modelNotFound->getIds();
        }

        $evidence = [
            new Evidence('exception', [
                'class' => $throwable::class,
                'message' => $message,
            ]),
        ];

        if ($frames !== []) {
            $evidence[] = new Evidence('stack_frame', $frames[0]);
        }

        return new Signal(
            type: 'exception_thrown',
            capturedAt: now()->toAtomString(),
            payload: $payload,
            evidence: [...$evidence, ...$queryEvidence],
        );
    }

    /**
     * @return ModelNotFoundException<Model>|null
     */
    protected function modelNotFound(Throwable $throwable): ?ModelNotFoundException
    {
        $current = $throwable;

        do {
            if ($current instanceof ModelNotFoundException) {
                return $current;
            }

            $current = $current->getPrevious();
        } while ($current instanceof Throwable);

        return null;
    }

    protected function statusCode(Throwable $throwable): int
    {
        if ($throwable instanceof HttpExceptionInterface) {
            return $throwable->getStatusCode();
        }

        if (method_exists($throwable, 'status')) {
            $status = $throwable->status();

            if (is_int($status) || is_float($status) || (is_string($status) && is_numeric($status))) {
                return (int) $status;
            }
        }

        return 500;
    }
}
