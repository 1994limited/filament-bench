<?php

namespace App\Bench;

/**
 * Switches for the findings: `BENCH_VARIANT=no_exists_check,plain_enum` runs
 * the same panel with the thing a finding blames turned off, so the probe
 * can measure what that thing costs on the same target. Read from the
 * process environment, not config, so a cached config doesn't fix it.
 * Timed runs never set it.
 */
final class Variant
{
    public static function on(string $name): bool
    {
        return in_array($name, explode(',', (string) getenv('BENCH_VARIANT')), true);
    }
}
