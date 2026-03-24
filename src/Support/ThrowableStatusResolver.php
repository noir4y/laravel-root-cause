<?php

namespace LaravelRootCause\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ThrowableStatusResolver
{
    public static function resolve(?Throwable $throwable): ?int
    {
        if (! $throwable) {
            return null;
        }

        $current = $throwable;

        do {
            if ($current instanceof HttpResponseException) {
                return $current->getResponse()->getStatusCode();
            }

            if ($current instanceof HttpExceptionInterface) {
                return $current->getStatusCode();
            }

            if ($current instanceof ValidationException) {
                return $current->status;
            }

            if ($current instanceof ModelNotFoundException) {
                return 404;
            }

            if (is_a($current, 'Illuminate\\Auth\\AuthenticationException')) {
                return 401;
            }

            if (is_a($current, 'Illuminate\\Auth\\Access\\AuthorizationException')) {
                $status = $current->status();

                if ($status !== null) {
                    return (int) $status;
                }

                return 403;
            }

            if (is_a($current, 'Illuminate\\Session\\TokenMismatchException')) {
                return 419;
            }

            if (method_exists($current, 'status')) {
                $status = $current->status();

                if (is_int($status) || is_float($status) || (is_string($status) && is_numeric($status))) {
                    return (int) $status;
                }
            }

            $current = $current->getPrevious();
        } while ($current instanceof Throwable);

        return 500;
    }
}
