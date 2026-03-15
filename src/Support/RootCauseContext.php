<?php

namespace LaravelRootCause\Support;

use Illuminate\Support\Facades\Context;

class RootCauseContext
{
    protected ?string $traceId = null;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function begin(string $traceId, array $metadata = []): void
    {
        $this->traceId = $traceId;

        $this->addToLaravelContext('root_cause.trace_id', $traceId);

        foreach ($metadata as $key => $value) {
            $this->addToLaravelContext('root_cause.'.$key, $value);
        }
    }

    public function traceId(): ?string
    {
        if ($this->traceId) {
            return $this->traceId;
        }

        $traceId = Context::get('root_cause.trace_id');

        return is_string($traceId) ? $traceId : null;
    }

    public function clear(): void
    {
        $this->traceId = null;
    }

    protected function addToLaravelContext(string $key, mixed $value): void
    {
        Context::add($key, $value);
    }
}
