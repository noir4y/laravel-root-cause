<?php

namespace LaravelRootCause\Tests\Unit;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use LaravelRootCause\Collectors\QueryCollector;
use LaravelRootCause\Data\Signal;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Redaction\Redactor;
use LaravelRootCause\Support\RootCause;
use LaravelRootCause\Support\StackFrameResolver;
use PHPUnit\Framework\TestCase;

class QueryCollectorTest extends TestCase
{
    public function test_it_records_query_signals_for_an_active_trace(): void
    {
        $rootCause = $this->createMock(RootCause::class);
        $stackFrameResolver = $this->createMock(StackFrameResolver::class);
        $connection = $this->createMock(Connection::class);

        $rootCause->expects($this->once())
            ->method('currentTrace')
            ->willReturn($this->makeTrace());

        $stackFrameResolver->expects($this->once())
            ->method('firstApplicationFrameFromTrace')
            ->willReturn([
                'file' => '/app/Models/User.php',
                'line' => 44,
                'class' => 'App\\Models\\User',
                'function' => 'loadPosts',
            ]);

        $rootCause->expects($this->once())
            ->method('recordSignal')
            ->with($this->callback(function (Signal $signal): bool {
                $callerFrame = $signal->payload['caller_frame'] ?? null;

                $this->assertSame('query_executed', $signal->type);
                $this->assertSame('select * from users where id = ?', $signal->payload['fingerprint']);
                $this->assertSame(['users'], $signal->payload['table_candidates']);
                $this->assertSame(1, $signal->payload['bindings_count']);
                $this->assertSame('testing', $signal->payload['connection']);
                $this->assertIsArray($callerFrame);
                $this->assertSame('/app/Models/User.php', $callerFrame['file'] ?? null);

                return true;
            }));

        $connection->method('getName')->willReturn('testing');

        $collector = new QueryCollector($rootCause, new Redactor, $stackFrameResolver);
        $collector->record(new QueryExecuted(
            'select * from users where id = 42',
            [42],
            12.345,
            $connection,
        ));
    }

    public function test_it_ignores_queries_when_no_trace_is_active(): void
    {
        $rootCause = $this->createMock(RootCause::class);
        $stackFrameResolver = $this->createMock(StackFrameResolver::class);
        $connection = $this->createMock(Connection::class);

        $rootCause->expects($this->once())
            ->method('currentTrace')
            ->willReturn(null);

        $rootCause->expects($this->never())
            ->method('recordSignal');

        $stackFrameResolver->expects($this->never())
            ->method('firstApplicationFrameFromTrace');

        $connection->method('getName')->willReturn('testing');

        $collector = new QueryCollector($rootCause, new Redactor, $stackFrameResolver);
        $collector->record(new QueryExecuted(
            'select * from users where id = 42',
            [42],
            12.345,
            $connection,
        ));
    }

    protected function makeTrace(): TraceEnvelope
    {
        return new TraceEnvelope(
            traceId: 'trc_query',
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: null,
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: ['method' => 'GET', 'uri' => '/users'],
            context: ['request_url' => 'http://localhost/users'],
        );
    }
}
