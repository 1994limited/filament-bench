<?php

/**
 * One request in a fresh process, counted in more detail than a timed run:
 * the findings use it to see what a page or a Livewire round trip does.
 *
 *   php bench/probe.php --url=/admin/customers [--as=...] [--monitor=Fn,Class->method] [--resolve=Class,...] [--sql] [--html=file]
 *   php bench/probe.php --scenario=customers_search --snapshot=file [...]
 *
 * --monitor counts calls to the named functions and methods with Xdebug's
 * function monitor (the process must have Xdebug loaded; times are then
 * meaningless). A trait's method is named after the class that uses the
 * trait, e.g. Filament\Support\Components\Component->evaluate.
 * --resolve counts how often the container builds each class (app()->make,
 * Class::make() in Filament): Schema, Get, Set and the state casts go that way.
 * --sql lists every query.
 */

require __DIR__.'/lib.php';

use App\Bench\Counter;

$opts = getopt('', ['url:', 'scenario:', 'snapshot:', 'as:', 'monitor:', 'resolve:', 'sql', 'html:']);
$monitor = isset($opts['monitor']) ? explode(',', $opts['monitor']) : [];
$monitor && ! function_exists('xdebug_start_function_monitor') && bench_fail('--monitor needs Xdebug');

if (isset($opts['scenario'])) {
    [$url, , $updates, $calls] = bench_manifest()['livewire'][$opts['scenario']] ?? bench_fail("no Livewire scenario {$opts['scenario']}");
    $prepared = json_decode(file_get_contents($opts['snapshot'] ?? bench_fail('--scenario needs --snapshot')), true);
    $calls = array_map(fn ($c) => $c['method'] === '__lazyLoad' ? ['method' => '__lazyLoad', 'params' => [$prepared['lazy']]] : $c, $calls);
    $kernel = bench_boot($url, 'POST');
} else {
    $url = $opts['url'] ?? bench_fail('--url or --scenario is required');
    $kernel = bench_boot($url);
}
isset($opts['as']) && bench_sign_in($opts['as']);

$resolved = [];
foreach (isset($opts['resolve']) ? explode(',', $opts['resolve']) : [] as $class) {
    $resolved[$class] = 0;
    app()->resolving($class, function () use (&$resolved, $class) {
        $resolved[$class]++;
    });
}
Counter::reset();
$monitor && xdebug_start_function_monitor($monitor);
$started = hrtime(true);

if (isset($opts['scenario'])) {
    [$status, $body] = bench_livewire($kernel, $prepared['snapshot'], $updates, $calls);
    [, $html] = bench_livewire_result($body);
} else {
    [$status, $html] = bench_request($kernel, $url);
    $body = $html;
}

$ms = (hrtime(true) - $started) / 1e6;
$called = array_fill_keys($monitor, 0);
if ($monitor) {
    foreach (xdebug_get_monitored_functions() as $call) {
        $called[$call['function']] = ($called[$call['function']] ?? 0) + 1;
    }
    xdebug_stop_function_monitor();
}
isset($opts['html']) && file_put_contents($opts['html'], $html);

bench_emit([
    'status' => $status,
    'ms' => round($ms, 3),
    'bytes' => strlen($body),
    'html_bytes' => strlen($html),
    'calls' => $called,
    'resolved' => $resolved,
] + bench_counts() + (isset($opts['sql']) ? ['sql' => array_column(Counter::$queries, 'sql')] : []));
