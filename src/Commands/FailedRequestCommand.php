<?php

namespace LaravelRootCause\Commands;

use Illuminate\Console\Command;
use LaravelRootCause\Export\CliTraceFormatter;
use LaravelRootCause\Support\TraceFinder;

class FailedRequestCommand extends Command
{
    protected $signature = 'root-cause:failed-request';

    protected $description = 'Show the latest failed request diagnosis';

    public function __construct(
        protected TraceFinder $finder,
        protected CliTraceFormatter $formatter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $trace = $this->finder->latestFailed();

        if (! $trace) {
            $this->components->error('No failed request traces found.');

            return self::FAILURE;
        }

        $this->line($this->formatter->format($trace));

        return self::SUCCESS;
    }
}
