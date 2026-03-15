<?php

namespace LaravelRootCause\Data;

use LaravelRootCause\Support\ValueNormalizer;

class Signal
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, Evidence>  $evidence
     */
    public function __construct(
        public readonly string $type,
        public readonly string $capturedAt,
        public readonly array $payload = [],
        public readonly array $evidence = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'captured_at' => $this->capturedAt,
            'payload' => $this->payload,
            'evidence' => array_map(static fn (Evidence $evidence) => $evidence->toArray(), $this->evidence),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $evidence = array_map(
            static fn (array $item): Evidence => Evidence::fromArray($item),
            ValueNormalizer::listOfAssoc($payload['evidence'] ?? [])
        );

        return new self(
            ValueNormalizer::string($payload['type'] ?? null, 'unknown'),
            ValueNormalizer::string($payload['captured_at'] ?? null, now()->toAtomString()),
            ValueNormalizer::assoc($payload['payload'] ?? []),
            $evidence
        );
    }
}
