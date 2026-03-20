<?php

namespace LaravelRootCause\Data;

use LaravelRootCause\Support\ValueNormalizer;

class DiagnosisReport
{
    /**
     * @param  array<int, Evidence>  $supportingEvidence
     * @param  array<int, string>  $affectedFiles
     * @param  array<int, string>  $candidateFixes
     * @param  array<string, mixed>  $repro
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $summary,
        public readonly string $rootCauseCategory,
        public readonly float $confidence,
        public readonly array $supportingEvidence = [],
        public readonly array $affectedFiles = [],
        public readonly array $candidateFixes = [],
        public readonly array $repro = [],
        public readonly string $tokenBudgetHint = 'small',
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'root_cause_category' => $this->rootCauseCategory,
            'confidence' => $this->confidence,
            'supporting_evidence' => array_map(static fn (Evidence $evidence) => $evidence->toArray(), $this->supportingEvidence),
            'affected_files' => array_values(array_unique($this->affectedFiles)),
            'candidate_fixes' => array_values($this->candidateFixes),
            'repro' => $this->repro,
            'token_budget_hint' => $this->tokenBudgetHint,
            'metadata' => $this->metadata === [] ? (object) [] : ValueNormalizer::assoc($this->metadata),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $supportingEvidence = array_map(
            static fn (array $item): Evidence => Evidence::fromArray($item),
            ValueNormalizer::listOfAssoc($payload['supporting_evidence'] ?? [])
        );

        return new self(
            ValueNormalizer::string($payload['summary'] ?? null),
            ValueNormalizer::string($payload['root_cause_category'] ?? null, 'unknown'),
            ValueNormalizer::float($payload['confidence'] ?? null),
            $supportingEvidence,
            ValueNormalizer::stringList($payload['affected_files'] ?? []),
            ValueNormalizer::stringList($payload['candidate_fixes'] ?? []),
            ValueNormalizer::assoc($payload['repro'] ?? []),
            ValueNormalizer::string($payload['token_budget_hint'] ?? null, 'small'),
            ValueNormalizer::assoc($payload['metadata'] ?? [])
        );
    }
}
