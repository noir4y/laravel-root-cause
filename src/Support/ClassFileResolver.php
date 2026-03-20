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

        if (! $file) {
            return null;
        }

        $basePath = rtrim($this->basePath(), DIRECTORY_SEPARATOR);

        if ($basePath !== '' && str_starts_with($file, $basePath)) {
            $relative = substr($file, strlen($basePath));

            return '/'.ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relative), '/');
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $file);
    }

    protected function basePath(): string
    {
        if (function_exists('base_path')) {
            try {
                return (string) base_path();
            } catch (\Throwable) {
                // Fall back to cwd when the helper exists but no application is booted.
            }
        }

        return (string) getcwd();
    }
}
