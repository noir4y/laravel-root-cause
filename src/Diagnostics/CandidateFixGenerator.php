<?php

namespace LaravelRootCause\Diagnostics;

class CandidateFixGenerator
{
    /**
     * @param  array<int, string>  $failedFields
     * @param  array<int, string>  $inputKeys
     * @return array<int, string>
     */
    public function forValidationMismatch(array $failedFields, array $inputKeys, ?string $formRequest): array
    {
        $fixes = [];

        foreach ($failedFields as $field) {
            $closest = $this->closestMatch($field, $inputKeys);

            if ($closest) {
                $fixes[] = sprintf('Rename payload key "%s" to "%s" or update the validation contract.', $closest, $field);

                continue;
            }

            $fixes[] = sprintf('Include the required field "%s" in the request payload or make the rule nullable.', $field);
        }

        if ($formRequest) {
            $fixes[] = sprintf('Review %s to confirm the expected payload keys still match the frontend contract.', class_basename($formRequest));
        }

        return array_values(array_unique($fixes));
    }

    /**
     * @param  array<int, string>  $routeParameterNames
     * @return array<int, string>
     */
    public function forMissingRouteBinding(?string $model, array $routeParameterNames): array
    {
        $fixes = [
            'Ensure the route parameter value exists before linking to this endpoint.',
            'Override route model binding if the lookup should use a slug or alternate key.',
        ];

        if ($model) {
            $fixes[] = sprintf('Confirm the %s model resolves the expected route key.', class_basename($model));
        }

        if ($routeParameterNames !== []) {
            $fixes[] = sprintf('Verify the route parameters [%s] match the controller signature.', implode(', ', $routeParameterNames));
        }

        return array_values(array_unique($fixes));
    }

    /**
     * @param  array<string, mixed>  $pathology
     * @return array<int, string>
     */
    public function forQueryPathology(array $pathology): array
    {
        $fixes = [
            'Add eager loading for the repeated relation path if this query happens inside a collection render.',
            'Move repeated lookups out of loops and batch them before rendering or serialization.',
        ];

        if (($pathology['classification'] ?? null) === 'duplicate_query_burst') {
            $fixes[] = 'Cache or memoize the repeated lookup when the same fingerprint is executed many times in one trace.';
        }

        return $fixes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $frames
     * @return array<int, string>
     */
    public function forUnhandledException(string $exceptionClass, array $frames): array
    {
        $fixes = [
            sprintf('Inspect the first application frame and add a focused regression test for %s.', class_basename($exceptionClass)),
            'Reproduce the failure with the exported payload keys and route metadata before changing behavior.',
        ];

        if ($frames !== []) {
            $frame = $frames[0];
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : 'unknown';
            $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0;
            $fixes[] = sprintf('Start from %s:%d to confirm the thrown branch is intended.', $file, $line);
        }

        return $fixes;
    }

    /**
     * @param  array<int, string>  $candidates
     */
    protected function closestMatch(string $needle, array $candidates): ?string
    {
        $closest = null;
        $closestDistance = null;

        foreach ($candidates as $candidate) {
            $distance = levenshtein($needle, $candidate);

            if ($closestDistance === null || $distance < $closestDistance) {
                $closestDistance = $distance;
                $closest = $candidate;
            }
        }

        return $closestDistance !== null && $closestDistance <= 3 ? $closest : null;
    }
}
