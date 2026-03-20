<?php

namespace LaravelRootCause\Tests\Unit;

use Illuminate\Database\QueryException;
use LaravelRootCause\Redaction\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_it_redacts_sensitive_input_and_headers_and_describes_input_shape(): void
    {
        $redactor = new Redactor([
            'request_keys' => ['password', 'token'],
            'headers' => ['authorization', 'cookie'],
        ]);

        $input = [
            'email' => 'taylor@example.com',
            'password' => 'secret',
            'meta' => ['team' => 'framework'],
            'user' => (object) ['id' => 1],
            'attempts' => 3,
        ];
        $headers = [
            'Authorization' => ['Bearer token'],
            'X-Trace' => ['abc123'],
            'Cookie' => ['session=1'],
            'Accept' => ['application/json'],
        ];

        $this->assertSame(['email', 'meta', 'user', 'attempts'], $redactor->sanitizeInputKeys($input));
        $this->assertSame([
            'email' => 'string',
            'meta' => 'array',
            'user' => 'stdClass',
            'attempts' => 'integer',
        ], $redactor->inputShape($input));
        $this->assertSame(['x-trace', 'accept'], $redactor->sanitizeHeaderKeys($headers));
        $this->assertSame(2, $redactor->sanitizeBindingsCount([1, 'two']));
    }

    public function test_it_redacts_query_exception_messages_when_sql_binding_redaction_is_enabled(): void
    {
        $redactor = new Redactor(['sql_bindings' => true]);
        $exception = new QueryException(
            'testing',
            'insert into "users" ("email", "password") values (?, ?)',
            ['taylor@example.com', 'secret-token'],
            new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation')
        );

        $message = $redactor->sanitizeExceptionMessage($exception);

        $this->assertStringNotContainsString('taylor@example.com', $message);
        $this->assertStringNotContainsString('secret-token', $message);
        $this->assertStringNotContainsString('insert into "users"', $message);
        $this->assertStringContainsString('2 bindings redacted', $message);
    }

    public function test_it_redacts_inline_sql_literals_when_sql_redaction_is_enabled(): void
    {
        $redactor = new Redactor(['sql_bindings' => true]);
        $exception = new QueryException(
            'testing',
            'select * from "users" where email = \'taylor@example.com\'',
            [],
            new \RuntimeException('SQLSTATE[42000]: Syntax error or access violation')
        );

        $message = $redactor->sanitizeExceptionMessage($exception);

        $this->assertStringNotContainsString('taylor@example.com', $message);
        $this->assertStringNotContainsString('select * from "users"', $message);
        $this->assertSame('Database query failed; SQL text redacted', $message);
    }

    public function test_it_redacts_common_secret_like_values_from_plain_exception_messages(): void
    {
        $redactor = new Redactor;
        $exception = new \RuntimeException(
            'Invite failed for taylor@example.com token=super-secret-value https://example.com/reset/abc.def.ghi'
        );

        $message = $redactor->sanitizeExceptionMessage($exception);

        $this->assertStringNotContainsString('taylor@example.com', $message);
        $this->assertStringNotContainsString('super-secret-value', $message);
        $this->assertStringNotContainsString('https://example.com/reset/abc.def.ghi', $message);
        $this->assertStringContainsString('[redacted-email]', $message);
        $this->assertStringContainsString('token [redacted]', $message);
        $this->assertStringContainsString('[redacted-url]', $message);
    }
}
