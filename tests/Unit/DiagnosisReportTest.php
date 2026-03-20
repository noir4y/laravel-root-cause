<?php

namespace LaravelRootCause\Tests\Unit;

use LaravelRootCause\Data\DiagnosisReport;
use PHPUnit\Framework\TestCase;

class DiagnosisReportTest extends TestCase
{
    public function test_it_serializes_empty_metadata_as_a_json_object(): void
    {
        $report = new DiagnosisReport(
            summary: 'An Unhandled RuntimeException returned a 500 error.',
            rootCauseCategory: 'unhandled_exception',
            confidence: 0.62,
        );

        $json = json_encode($report->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"metadata":{}', $json);
    }
}
