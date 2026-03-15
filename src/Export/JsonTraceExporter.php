<?php

namespace LaravelRootCause\Export;

use LaravelRootCause\Data\TraceEnvelope;

class JsonTraceExporter
{
    public function export(TraceEnvelope $trace): string
    {
        return json_encode($trace->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
