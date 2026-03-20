<?php

namespace LaravelRootCause\Tests\Feature;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Routing\Router;
use LaravelRootCause\Collectors\QueryCollector;
use LaravelRootCause\Collectors\RequestCollector;
use LaravelRootCause\Support\RootCause;
use LaravelRootCause\Support\RootCauseContext;
use LaravelRootCause\Tests\TestCase;

class RootCauseServiceProviderTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $config = $app->make(ConfigRepository::class);
        $config->set('root_cause.collectors.request.enabled', false);
        $config->set('root_cause.collectors.request.auto_register_middleware', true);
    }

    public function test_it_does_not_prepend_request_collector_when_request_collection_is_disabled(): void
    {
        $this->assertNotNull($this->app);

        $router = $this->app->make(Router::class);
        $groups = $router->getMiddlewareGroups();
        /** @var array<int, string> $web */
        $web = $groups['web'] ?? [];
        /** @var array<int, string> $api */
        $api = $groups['api'] ?? [];

        $this->assertNotContains(RequestCollector::class, $web);
        $this->assertNotContains(RequestCollector::class, $api);
    }

    public function test_it_scopes_stateful_runtime_services_to_the_current_lifecycle(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);

        $rootCause = $app->make(RootCause::class);
        $queryCollector = $app->make(QueryCollector::class);
        $context = $app->make(RootCauseContext::class);

        $app->forgetScopedInstances();

        $this->assertNotSame($rootCause, $app->make(RootCause::class));
        $this->assertNotSame($queryCollector, $app->make(QueryCollector::class));
        $this->assertNotSame($context, $app->make(RootCauseContext::class));
    }
}
