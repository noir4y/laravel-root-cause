<?php

namespace LaravelRootCause\Support;

use Illuminate\Support\Facades\Context;

class RootCauseContext
{
    /**
     * @var array<int, string>
     */
    protected array $keys = [];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function begin(string $traceId, array $metadata = []): void
    {
        $this->clear();

        $this->addToLaravelContext('root_cause.trace_id', $traceId);

        foreach ($metadata as $key => $value) {
            $this->addToLaravelContext('root_cause.'.$key, $value);
        }
    }

    public function traceId(): ?string
    {
        $traceId = Context::get('root_cause.trace_id');

        return is_string($traceId) ? $traceId : null;
    }

    public function clear(): void
    {
        if ($this->keys !== []) {
            Context::forget($this->keys);
            $this->keys = [];
        }
    }

    protected function addToLaravelContext(string $key, mixed $value): void
    {
        Context::add($key, $value);
        $this->keys[] = $key;
    }
}
