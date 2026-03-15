<?php

namespace LaravelRootCause\Commands;

use Illuminate\Console\Command;
use LaravelRootCause\Export\CliTraceFormatter;
use LaravelRootCause\Support\TraceFinder;

class QueryPathologyCommand extends Command
{
    protected $signature = 'root-cause:query-pathology';

    protected $description = 'Show the latest query pathology diagnosis';

    public function __construct(
        protected TraceFinder $finder,
        protected CliTraceFormatter $formatter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $trace = $this->finder->latestQueryPathology();

        if (! $trace) {
            $this->components->error('No query pathology traces found.');

            return self::FAILURE;
        }

        $this->line($this->formatter->format($trace));

        return self::SUCCESS;
    }
}
