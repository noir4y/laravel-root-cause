<?php

namespace LaravelRootCause\Commands;

use Illuminate\Console\Command;
use LaravelRootCause\Contracts\PrunableTraceRepository;
use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Support\ValueNormalizer;

class PruneTracesCommand extends Command
{
    protected $signature = 'root-cause:prune
        {--days= : Retain traces newer than this many days}';

    protected $description = 'Delete stored traces older than the configured retention window';

    public function __construct(protected TraceRepository $repository)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = ValueNormalizer::int($this->option('days'), ValueNormalizer::int(config('root_cause.storage.retention_days', 7), 7));

        if ($days < 1) {
            $this->components->error('Retention days must be at least 1.');

            return self::FAILURE;
        }

        if (! $this->repository instanceof PrunableTraceRepository) {
            $this->components->error('The configured trace repository does not support pruning.');

            return self::FAILURE;
        }

        $deleted = $this->repository->pruneOlderThan(now()->subDays($days));

        $this->components->info(sprintf('Pruned %d trace(s) older than %d day(s).', $deleted, $days));

        return self::SUCCESS;
    }
}
