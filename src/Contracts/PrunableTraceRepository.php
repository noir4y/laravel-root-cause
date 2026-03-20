<?php

namespace LaravelRootCause\Contracts;

use DateTimeInterface;

interface PrunableTraceRepository
{
    public function pruneOlderThan(DateTimeInterface $threshold): int;
}
