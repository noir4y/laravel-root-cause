<?php

namespace LaravelRootCause\Tests\Feature;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use LaravelRootCause\Contracts\TraceRepository;
use LaravelRootCause\Tests\TestCase;

class RootCauseGlobalToggleTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $config = $app->make(ConfigRepository::class);
        $config->set('root_cause.enabled', false);
    }

    public function test_it_does_not_persist_traces_when_root_cause_is_globally_disabled(): void
    {
        $this->withExceptionHandling()
            ->getJson('/explode')
            ->assertStatus(500);

        $this->assertNotNull($this->app);

        $repository = $this->app->make(TraceRepository::class);

        $this->assertNull($repository->latest());
    }
}
