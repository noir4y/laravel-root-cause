<?php

namespace LaravelRootCause\Data;

use LaravelRootCause\Support\ValueNormalizer;

class Evidence
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $type,
        public readonly array $attributes = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['type' => $this->type] + $this->attributes;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $type = ValueNormalizer::string($payload['type'] ?? null, 'generic');
        unset($payload['type']);

        return new self($type, $payload);
    }
}
