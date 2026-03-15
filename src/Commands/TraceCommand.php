<?php

namespace LaravelRootCause\Commands;

use Illuminate\Console\Command;
use LaravelRootCause\Export\CliTraceFormatter;
use LaravelRootCause\Support\TraceFinder;
use LaravelRootCause\Support\ValueNormalizer;

class TraceCommand extends Command
{
    protected $signature = 'root-cause:trace {trace_id=latest : Trace ID or "latest"}';

    protected $description = 'Show a stored trace diagnosis';

    public function __construct(
        protected TraceFinder $finder,
        protected CliTraceFormatter $formatter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $trace = $this->finder->resolve(ValueNormalizer::nullableString($this->argument('trace_id')));

        if (! $trace) {
            $this->components->error('Trace not found.');

            return self::FAILURE;
        }

        $this->line($this->formatter->format($trace));

        return self::SUCCESS;
    }
}
