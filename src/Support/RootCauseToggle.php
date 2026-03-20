<?php

namespace LaravelRootCause\Support;

class RootCauseToggle
{
    public static function enabled(): bool
    {
        try {
            return ! in_array(config('root_cause.enabled', true), [false, 0, '0', 'false', 'off'], true);
        } catch (\Throwable) {
            return true;
        }
    }
}
