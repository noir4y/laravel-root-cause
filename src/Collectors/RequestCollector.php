<?php

namespace LaravelRootCause\Collectors;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LaravelRootCause\Support\RootCause;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RequestCollector
{
    protected const TRACE_ID_ATTRIBUTE = 'root_cause.trace_id';

    protected const THREW_EXCEPTION_ATTRIBUTE = 'root_cause.threw_exception';

    public function __construct(protected RootCause $rootCause) {}

    public function handle(Request $request, Closure $next): Response
    {
        $trace = $this->rootCause->startRequest($request);
        $request->attributes->set(self::TRACE_ID_ATTRIBUTE, $trace->traceId);

        $response = null;
        $throwable = null;
        $finishImmediately = true;

        try {
            /** @var Response $response */
            $response = $next($request);
            $responseException = $this->responseException($response);

            if ($responseException instanceof ValidationException) {
                $throwable = $responseException;
                $this->rootCause->recordValidationException($responseException, $request);
            } else {
                $this->rootCause->recordValidationResponse($response, $request);

                if ($responseException instanceof Throwable) {
                    $throwable = $responseException;
                    $this->rootCause->recordException($responseException);
                }
            }

            if ($response instanceof StreamedResponse) {
                $streamState = ['throwable' => $throwable];
                $finishImmediately = ! $this->deferFinishUntilStreamCompletes($response, $request, $streamState);
            }

            return $response;
        } catch (ValidationException $exception) {
            $throwable = $exception;
            $request->attributes->set(self::THREW_EXCEPTION_ATTRIBUTE, true);
            $this->rootCause->recordValidationException($exception, $request);

            throw $exception;
        } catch (Throwable $exception) {
            $throwable = $exception;
            $request->attributes->set(self::THREW_EXCEPTION_ATTRIBUTE, true);
            $this->rootCause->recordException($exception);

            throw $exception;
        } finally {
            if ($finishImmediately) {
                $this->rootCause->finishRequest($response, $throwable, $request);
            }
        }
    }

    protected function responseException(Response $response): ?Throwable
    {
        $exception = $response->exception ?? null;

        return $exception instanceof Throwable ? $exception : null;
    }

    public function terminate(Request $request, Response $response): void
    {
        $traceId = $request->attributes->get(self::TRACE_ID_ATTRIBUTE);
        $threwException = $request->attributes->get(self::THREW_EXCEPTION_ATTRIBUTE, false);

        if (! is_string($traceId) || $traceId === '' || $threwException !== true) {
            return;
        }

        $this->rootCause->refreshStoredTraceResponse($traceId, $response, $this->responseException($response));
    }

    /**
     * @param  array{throwable: Throwable|null}  $state
     */
    protected function deferFinishUntilStreamCompletes(StreamedResponse $response, Request $request, array &$state): bool
    {
        // On supported Laravel 11/12 stacks (Symfony HttpFoundation >= 7.2),
        // iterable chunk streams are normalized into an internal callback too.
        $callback = $response->getCallback();

        if ($callback === null) {
            return false;
        }

        $response->setCallback(function () use ($callback, $response, $request, &$state): void {
            try {
                $callback();
            } catch (ValidationException $exception) {
                $state['throwable'] = $exception;
                $this->rootCause->recordValidationException($exception, $request);

                throw $exception;
            } catch (Throwable $exception) {
                $state['throwable'] = $exception;
                $this->rootCause->recordException($exception);

                throw $exception;
            } finally {
                $this->rootCause->finishRequest($response, $state['throwable'], $request);
            }
        });

        return true;
    }
}
