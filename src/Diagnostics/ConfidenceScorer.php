<?php

namespace LaravelRootCause\Diagnostics;

class ConfidenceScorer
{
    /**
     * @param  array<int, string>  $failedFields
     * @param  array<int, string>  $inputKeys
     */
    public function validationContractMismatch(array $failedFields, ?string $formRequest, array $inputKeys): float
    {
        $score = 0.55;
        $score += min(count($failedFields) * 0.08, 0.2);
        $score += $formRequest ? 0.08 : 0.0;
        $score += $inputKeys !== [] ? 0.05 : 0.0;
        $score -= count($failedFields) > 3 ? 0.05 : 0.0;

        return round(min($score, 0.95), 2);
    }

    public function missingRouteBinding(?string $model, bool $hasController): float
    {
        $score = 0.62;
        $score += $model ? 0.13 : 0.0;
        $score += $hasController ? 0.05 : 0.0;

        return round(min($score, 0.93), 2);
    }

    public function queryPathology(string $classification, int $count, bool $hasFrame): float
    {
        $score = $classification === 'n_plus_one_suspected' ? 0.68 : 0.61;
        $score += min(($count - 2) * 0.04, 0.18);
        $score += $hasFrame ? 0.05 : 0.0;

        return round(min($score, 0.94), 2);
    }

    public function unhandledException(bool $hasFrames, bool $hasQueryEvidence): float
    {
        $score = 0.48;
        $score += $hasFrames ? 0.09 : 0.0;
        $score += $hasQueryEvidence ? 0.05 : 0.0;

        return round(min($score, 0.8), 2);
    }
}
