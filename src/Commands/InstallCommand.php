<?php

namespace LaravelRootCause\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use LaravelRootCause\Support\ValueNormalizer;

class InstallCommand extends Command
{
    protected $signature = 'root-cause:install {--force : Overwrite published resources}';

    protected $description = 'Publish configuration/resources and prepare file storage for Root Cause';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'root-cause-config',
            '--force' => $this->option('force'),
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'root-cause-resources',
            '--force' => $this->option('force'),
        ]);

        $path = ValueNormalizer::string(config('root_cause.storage.path'), storage_path('app/root-cause'));
        $retentionDays = ValueNormalizer::int(config('root_cause.storage.retention_days', 7), 7);
        $this->files->ensureDirectoryExists($path);

        $this->components->info(sprintf('Root Cause storage ready: %s', $path));
        $this->components->twoColumnDetail('Middleware', 'auto-registered on web/api groups');
        $this->components->twoColumnDetail('Output', 'CLI + JSON export');
        $this->components->twoColumnDetail('Runtime toggle', 'ROOT_CAUSE_ENABLED (local default only)');
        $this->components->twoColumnDetail('Retention', sprintf('%d day default via ROOT_CAUSE_RETENTION_DAYS', $retentionDays));

        return self::SUCCESS;
    }
}
