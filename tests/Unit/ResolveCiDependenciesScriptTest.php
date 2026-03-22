<?php

namespace LaravelRootCause\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ResolveCiDependenciesScriptTest extends TestCase
{
    public function test_ci_dependency_resolution_script_is_executable(): void
    {
        $path = dirname(__DIR__, 2).'/scripts/resolve-ci-dependencies.sh';

        $this->assertFileExists($path);
        $this->assertTrue(is_executable($path), 'CI dependency resolution script must be executable.');
    }
}
