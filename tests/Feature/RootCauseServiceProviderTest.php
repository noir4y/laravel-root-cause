<?php

namespace LaravelRootCause\Tests\Feature;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Routing\Router;
use LaravelRootCause\Collectors\RequestCollector;
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
}
