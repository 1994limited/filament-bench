<?php

namespace App\Bench;

/**
 * What a bench process counts while it runs: queries, storage round trips,
 * models hydrated. The bench scripts read it when the request is done.
 */
final class Counter
{
    /** @var array<string, int> */
    public static array $counts = [];

    /** @var list<array{sql: string, ms: float}> */
    public static array $queries = [];

    public static float $queryMs = 0;

    public static function add(string $what, int $by = 1): void
    {
        self::$counts[$what] = (self::$counts[$what] ?? 0) + $by;
    }

    public static function reset(): void
    {
        self::$counts = [];
        self::$queries = [];
        self::$queryMs = 0;
    }

    /** Counts that start with a prefix, without it: `prefixed('disk.')` gives ['exists' => 50, ...]. */
    public static function prefixed(string $prefix): array
    {
        $out = [];
        foreach (self::$counts as $key => $n) {
            if (str_starts_with($key, $prefix)) {
                $out[substr($key, strlen($prefix))] = $n;
            }
        }
        ksort($out);

        return $out;
    }
}
