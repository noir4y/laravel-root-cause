<?php

namespace LaravelRootCause\Support;

use ReflectionClass;

class ClassFileResolver
{
    public function resolve(?string $class): ?string
    {
        if (! $class || ! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        $file = $reflection->getFileName();

        return $file ?: null;
    }
}
