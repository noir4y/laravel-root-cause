<?php

namespace LaravelRootCause\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use LaravelRootCause\Export\JsonTraceExporter;
use LaravelRootCause\Support\TraceFinder;
use LaravelRootCause\Support\ValueNormalizer;

class ExportTraceCommand extends Command
{
    protected $signature = 'root-cause:export
        {trace_id=latest : Trace ID or "latest"}
        {--format=json : Export format}
        {--path= : Optional destination file}';

    protected $description = 'Export a stored trace for AI agents or external tools';

    public function __construct(
        protected TraceFinder $finder,
        protected JsonTraceExporter $exporter,
        protected Filesystem $files,
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

        if (ValueNormalizer::string($this->option('format'), 'json') !== 'json') {
            $this->components->error('Only JSON export is supported in v0.1.');

            return self::FAILURE;
        }

        $json = $this->exporter->export($trace);
        $path = ValueNormalizer::nullableString($this->option('path'));

        if (is_string($path) && $path !== '') {
            $this->files->put($path, $json);
            $this->components->info(sprintf('Exported trace to %s', $path));

            return self::SUCCESS;
        }

        $this->line($json);

        return self::SUCCESS;
    }
}
