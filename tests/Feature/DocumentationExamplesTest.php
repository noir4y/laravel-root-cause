<?php

namespace LaravelRootCause\Tests\Feature;

use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Diagnostics\RuleEngine;
use LaravelRootCause\Export\CliTraceFormatter;
use LaravelRootCause\Export\JsonTraceExporter;
use LaravelRootCause\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DocumentationExamplesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function cliSnippetProvider(): array
    {
        return [
            'readme validation snippet' => ['README.md', 'validation-failure', 'text', 0],
            'validation incident snippet' => ['docs/incidents/validation-failure.md', 'validation-failure', 'text', 0],
            'exception incident snippet' => ['docs/incidents/exception.md', 'exception', 'text', 0],
            'query pathology snippet' => ['docs/incidents/query-pathology.md', 'query-pathology', 'text', 0],
        ];
    }

    #[DataProvider('cliSnippetProvider')]
    public function test_public_cli_snippets_match_generated_output(string $path, string $fixture, string $language, int $index): void
    {
        $this->storeFixtureTrace($fixture);

        $trace = $this->latestTrace();
        $this->assertNotNull($this->app);

        $output = $this->app->make(CliTraceFormatter::class)->format($trace);
        $snippet = $this->markdownCodeBlock($path, $language, $index);

        $this->assertSame($snippet, $output);
    }

    public function test_quickstart_cli_excerpt_matches_the_generated_validation_output(): void
    {
        $this->storeFixtureTrace('validation-failure');

        $trace = $this->latestTrace();
        $this->assertNotNull($this->app);

        $output = $this->app->make(CliTraceFormatter::class)->format($trace);
        $expected = implode(PHP_EOL, array_slice(explode(PHP_EOL, $output), 0, 3));

        $this->assertSame($this->markdownCodeBlock('docs/quickstart.md', 'text', 0), $expected);
    }

    public function test_public_json_snippets_match_generated_exports(): void
    {
        $this->storeFixtureTrace('exception');

        $trace = $this->latestTrace();
        $this->assertNotNull($this->app);

        /** @var array<string, mixed> $actual */
        $actual = json_decode($this->app->make(JsonTraceExporter::class)->export($trace), true, 512, JSON_THROW_ON_ERROR);

        foreach ([
            ['README.md', 0],
            ['docs/quickstart.md', 0],
        ] as [$path, $index]) {
            /** @var array<string, mixed> $expected */
            $expected = json_decode($this->markdownCodeBlock($path, 'json', $index), true, 512, JSON_THROW_ON_ERROR);
            $this->assertJsonSubset($expected, $actual);
        }
    }

    protected function storeFixtureTrace(string $fixture): void
    {
        $path = __DIR__.'/../../docs/incidents/fixtures/'.$fixture.'.trace.json';
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotNull($this->app);

        $repository = $this->app->make(TraceRepository::class);
        $trace = TraceEnvelope::fromArray($payload);
        $trace->diagnosis = $this->app->make(RuleEngine::class)->diagnose($trace);

        $repository->save($trace);
    }

    protected function latestTrace(): TraceEnvelope
    {
        $this->assertNotNull($this->app);

        $repository = $this->app->make(TraceRepository::class);
        $trace = $repository->latest();

        $this->assertInstanceOf(TraceEnvelope::class, $trace);

        return $trace;
    }

    protected function markdownCodeBlock(string $path, string $language, int $index): string
    {
        $contents = (string) file_get_contents(__DIR__.'/../../'.$path);
        preg_match_all('/```'.preg_quote($language, '/')."\n(.*?)```/s", $contents, $matches);

        /** @var array<int, string> $blocks */
        $blocks = $matches[1];

        $this->assertArrayHasKey($index, $blocks, sprintf('Missing %s code block %d in %s', $language, $index, $path));

        return rtrim($blocks[$index]);
    }

    /**
     * @param  array<array-key, mixed>  $expected
     * @param  array<array-key, mixed>  $actual
     */
    protected function assertJsonSubset(array $expected, array $actual): void
    {
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $actual);

            if (is_array($value)) {
                $this->assertIsArray($actual[$key]);
                /** @var array<array-key, mixed> $actualValue */
                $actualValue = $actual[$key];
                /** @var array<array-key, mixed> $expectedValue */
                $expectedValue = $value;
                $this->assertJsonSubset($expectedValue, $actualValue);

                continue;
            }

            $this->assertSame($value, $actual[$key]);
        }
    }
}
