<?php

namespace LaravelRootCause\Collectors;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LaravelRootCause\Support\RootCause;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RequestCollector
{
    public function __construct(protected RootCause $rootCause) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->rootCause->startRequest($request);

        $response = null;
        $throwable = null;

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

            return $response;
        } catch (ValidationException $exception) {
            $throwable = $exception;
            $this->rootCause->recordValidationException($exception, $request);

            throw $exception;
        } catch (Throwable $exception) {
            $throwable = $exception;
            $this->rootCause->recordException($exception);

            throw $exception;
        } finally {
            $this->rootCause->finishRequest($response, $throwable, $request);
        }
    }

    protected function responseException(Response $response): ?Throwable
    {
        $exception = $response->exception ?? null;

        return $exception instanceof Throwable ? $exception : null;
    }
}
