<?php

namespace LaravelRootCause\Tests\Unit;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use LaravelRootCause\Support\ThrowableStatusResolver;
use LaravelRootCause\Tests\Fixtures\Models\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ThrowableStatusResolverTest extends TestCase
{
    public function test_it_resolves_wrapped_framework_statuses(): void
    {
        $this->assertSame(
            404,
            ThrowableStatusResolver::resolve(
                new NotFoundHttpException(
                    'Not found.',
                    (new ModelNotFoundException)->setModel(User::class, [99])
                )
            )
        );

        $this->assertSame(
            429,
            ThrowableStatusResolver::resolve(
                new HttpResponseException(new Response('', 429))
            )
        );
    }

    public function test_it_resolves_custom_status_methods_and_defaults_to_500(): void
    {
        $this->assertSame(409, ThrowableStatusResolver::resolve(new class('Conflict') extends \RuntimeException
        {
            public function status(): string
            {
                return '409';
            }
        }));

        $this->assertSame(500, ThrowableStatusResolver::resolve(new \RuntimeException('Boom')));
    }
}
