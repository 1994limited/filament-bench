<?php

/**
 * One page request in a fresh PHP process, the way a PHP-FPM worker serves
 * it: nothing from an earlier request survives but what is on disk and the
 * compiled code (opcache, through its file cache).
 *
 *   php bench/request.php --url=/admin/customers [--as=admin@bench.test] [--expect=200] [--html=out.html]
 *
 * Booting the app and handling the request are timed separately. Prints one
 * JSON line with the times, peak memory, response size and what was counted.
 */

$started = hrtime(true);
require __DIR__.'/lib.php';

$opts = getopt('', ['url:', 'expect:', 'html:', 'as:']);
isset($opts['url']) || bench_fail("--url is required");

$kernel = bench_boot($opts['url']);
if (isset($opts['as'])) {
    bench_sign_in($opts['as']);
}
App\Bench\Counter::reset();
$booted = hrtime(true);

[$status, $html] = bench_request($kernel, $opts['url']);
$done = hrtime(true);

if (isset($opts['html'])) {
    file_put_contents($opts['html'], bench_normalise($html));
}

bench_emit([
    'status' => $status,
    'boot_ms' => round(($booted - $started) / 1e6, 3),
    'ms' => round(($done - $booted) / 1e6, 3),
    'peak_mem_mb' => round(memory_get_peak_usage() / 1048576, 2),
    'bytes' => strlen($html),
] + bench_counts());

exit(isset($opts['expect']) && (int) $opts['expect'] !== $status ? 1 : 0);
