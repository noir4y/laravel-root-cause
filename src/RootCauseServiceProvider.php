<?php

namespace LaravelRootCause;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use LaravelRootCause\Collectors\QueryCollector;
use LaravelRootCause\Collectors\RequestCollector;
use LaravelRootCause\Commands\ExportTraceCommand;
use LaravelRootCause\Commands\FailedRequestCommand;
use LaravelRootCause\Commands\InstallCommand;
use LaravelRootCause\Commands\QueryPathologyCommand;
use LaravelRootCause\Commands\TraceCommand;
use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Diagnostics\CandidateFixGenerator;
use LaravelRootCause\Diagnostics\ConfidenceScorer;
use LaravelRootCause\Diagnostics\RuleEngine;
use LaravelRootCause\Redaction\Redactor;
use LaravelRootCause\Storage\FileTraceRepository;
use LaravelRootCause\Support\RootCause;
use LaravelRootCause\Support\RootCauseContext;
use LaravelRootCause\Support\TraceFinder;
use LaravelRootCause\Support\ValueNormalizer;

class RootCauseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/root_cause.php', 'root_cause');

        $this->app->singleton(Redactor::class, fn (Application $app): Redactor => new Redactor(
            ValueNormalizer::assoc($app->make(ConfigRepository::class)->get('root_cause.redact', []))
        ));
        $this->app->singleton(RootCauseContext::class);
        $this->app->singleton(ConfidenceScorer::class);
        $this->app->singleton(CandidateFixGenerator::class);
        $this->app->singleton(RuleEngine::class);
        $this->app->singleton(TraceRepository::class, function (Application $app): TraceRepository {
            $files = $app->make(Filesystem::class);
            $config = $app->make(ConfigRepository::class);

            return new FileTraceRepository(
                $files,
                ValueNormalizer::string($config->get('root_cause.storage.path'), storage_path('app/root-cause'))
            );
        });
        $this->app->singleton(RootCause::class);
        $this->app->singleton(QueryCollector::class);
        $this->app->singleton(TraceFinder::class);

        $this->commands([
            InstallCommand::class,
            TraceCommand::class,
            FailedRequestCommand::class,
            QueryPathologyCommand::class,
            ExportTraceCommand::class,
        ]);
    }

    public function boot(): void
    {
        $config = $this->config();

        $this->publishes([
            __DIR__.'/../config/root_cause.php' => config_path('root_cause.php'),
        ], 'root-cause-config');

        $this->publishes([
            __DIR__.'/../resources/prompts' => resource_path('vendor/root-cause/prompts'),
            __DIR__.'/../resources/rules' => resource_path('vendor/root-cause/rules'),
        ], 'root-cause-resources');

        if ($config->get('root_cause.collectors.request.enabled', true)
            && $config->get('root_cause.collectors.request.auto_register_middleware', true)
            && $this->app->bound('router')) {
            $this->registerMiddleware($this->app->make(Router::class));
        }

        if ($config->get('root_cause.collectors.query.enabled', true) && $this->app->bound('db')) {
            DB::listen(function (QueryExecuted $query): void {
                $this->app->make(QueryCollector::class)->record($query);
            });
        }
    }

    protected function registerMiddleware(Router $router): void
    {
        $middleware = RequestCollector::class;

        foreach (['web', 'api'] as $group) {
            $router->prependMiddlewareToGroup($group, $middleware);
        }
    }

    protected function config(): ConfigRepository
    {
        return $this->app->make(ConfigRepository::class);
    }
}
