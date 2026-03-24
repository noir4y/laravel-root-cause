<?php

namespace LaravelRootCause\Tests\Unit;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use LaravelRootCause\Collectors\ExceptionCollector;
use LaravelRootCause\Data\Evidence;
use LaravelRootCause\Redaction\Redactor;
use LaravelRootCause\Support\StackFrameResolver;
use LaravelRootCause\Tests\Fixtures\Models\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ExceptionCollectorTest extends TestCase
{
    public function test_it_collects_exception_payloads_frames_and_query_evidence(): void
    {
        $stackFrameResolver = $this->createMock(StackFrameResolver::class);

        $stackFrameResolver->expects($this->once())
            ->method('applicationFramesFromThrowable')
            ->willReturn([
                [
                    'file' => '/app/Models/User.php',
                    'line' => 19,
                    'class' => 'App\\Models\\User',
                    'function' => 'resolveRouteBinding',
                ],
            ]);

        $exception = (new ModelNotFoundException)->setModel(User::class, [99]);
        $collector = new ExceptionCollector($stackFrameResolver, new Redactor);
        $signal = $collector->collect($exception, [
            new Evidence('query_summary', [
                'fingerprint' => 'select * from "users" where "id" = ?',
                'count' => 3,
                'duration_ms' => 4.2,
            ]),
        ]);

        $this->assertSame('exception_thrown', $signal->type);
        $this->assertSame(ModelNotFoundException::class, $signal->payload['exception_class']);
        $this->assertSame(404, $signal->payload['status_code']);
        $this->assertSame(User::class, $signal->payload['model']);
        $this->assertSame([99], $signal->payload['ids']);
        $this->assertSame(['exception', 'stack_frame', 'query_summary'], array_map(
            static fn (Evidence $evidence): string => $evidence->type,
            $signal->evidence
        ));
    }

    public function test_it_redacts_query_exception_messages_and_uses_resolved_exception_status_codes(): void
    {
        $stackFrameResolver = $this->createMock(StackFrameResolver::class);
        $stackFrameResolver->method('applicationFramesFromThrowable')->willReturn([]);

        $collector = new ExceptionCollector($stackFrameResolver, new Redactor(['sql_bindings' => true]));

        $queryException = new QueryException(
            'testing',
            'insert into "users" ("email", "password") values (?, ?)',
            ['taylor@example.com', 'secret-token'],
            new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation')
        );

        $querySignal = $collector->collect($queryException);
        /** @var string $queryMessage */
        $queryMessage = $querySignal->payload['message'];

        $this->assertStringNotContainsString('taylor@example.com', $queryMessage);
        $this->assertStringNotContainsString('secret-token', $queryMessage);
        $this->assertStringNotContainsString('insert into "users"', $queryMessage);
        $this->assertSame($queryMessage, $querySignal->evidence[0]->attributes['message']);

        $statusSignal = $collector->collect(new class('Conflict') extends \RuntimeException
        {
            public function status(): int
            {
                return 409;
            }
        });

        $this->assertSame(409, $statusSignal->payload['status_code']);

        $responseSignal = $collector->collect(
            new HttpResponseException(new Response('', 429))
        );

        $this->assertSame(429, $responseSignal->payload['status_code']);
    }

    public function test_it_extracts_model_context_from_wrapped_not_found_exceptions(): void
    {
        $stackFrameResolver = $this->createMock(StackFrameResolver::class);
        $stackFrameResolver->method('applicationFramesFromThrowable')->willReturn([]);

        $collector = new ExceptionCollector($stackFrameResolver, new Redactor);
        $signal = $collector->collect(
            new NotFoundHttpException(
                'Not found.',
                (new ModelNotFoundException)->setModel(User::class, [99])
            )
        );

        $this->assertSame(User::class, $signal->payload['model']);
        $this->assertSame([99], $signal->payload['ids']);
    }
}
