<?php

/**
 * One Livewire round trip in a fresh PHP process: what a browser sends after
 * the page has loaded (a search, a sort, a page change, a live field, a poll).
 *
 *   php bench/livewire.php --prepare --scenario=customers_search --as=... --snapshot=file.json
 *       Load the page, find the component, load it if it is lazy, and keep its snapshot. Untimed.
 *   php bench/livewire.php --scenario=customers_search --as=... --snapshot=file.json [--html=out.html]
 *       Send the scenario's updates and calls with that snapshot, timed.
 *
 * Prints one JSON line like request.php's.
 */

$started = hrtime(true);
require __DIR__.'/lib.php';

$opts = getopt('', ['scenario:', 'as:', 'snapshot:', 'prepare', 'html:']);
isset($opts['scenario'], $opts['snapshot']) || bench_fail("--scenario and --snapshot are required");
[$url, $component, $updates, $calls] = bench_manifest()['livewire'][$opts['scenario']] ?? bench_fail("no Livewire scenario {$opts['scenario']}");

if (isset($opts['prepare'])) {
    $kernel = bench_boot($url);
    isset($opts['as']) && bench_sign_in($opts['as']);
    [$status, $html] = bench_request($kernel, $url);
    $status === 200 || bench_fail("$url returned $status");
    $snapshot = bench_snapshots($html)[$component] ?? bench_fail("no component $component on $url (found: ".implode(', ', array_keys(bench_snapshots($html))).")");
    // a lazy component (Filament's widgets and notifications are) is a placeholder until the browser sees it and asks for it
    $id = json_decode($snapshot, true)['memo']['id'];
    $lazy = preg_match('/wire:id="'.preg_quote($id, '/').'"[^>]*?__lazyLoad\(&#039;([^&]+)&#039;\)/', $html, $match) ? $match[1] : null;
    if ($lazy && ($calls[0]['method'] ?? null) !== '__lazyLoad') {
        [$status, $body] = bench_livewire($kernel, $snapshot, [], [['method' => '__lazyLoad', 'params' => [$lazy]]]);
        $status === 200 || bench_fail("loading the lazy $component returned $status");
        $snapshot = bench_livewire_result($body)[0];
    }
    file_put_contents($opts['snapshot'], json_encode(['snapshot' => $snapshot, 'lazy' => $lazy]));
    bench_emit(['prepared' => $component]);
    exit(0);
}

$prepared = json_decode(file_get_contents($opts['snapshot']), true);
// the lazy-load scenarios send what the page gave the browser to send
$calls = array_map(fn ($c) => $c['method'] === '__lazyLoad' ? ['method' => '__lazyLoad', 'params' => [$prepared['lazy'] ?? bench_fail("{$opts['scenario']}: $component isn't lazy")]] : $c, $calls);

$kernel = bench_boot($url, 'POST');
isset($opts['as']) && bench_sign_in($opts['as']);
App\Bench\Counter::reset();
$booted = hrtime(true);

[$status, $body] = bench_livewire($kernel, $prepared['snapshot'], $updates, $calls);
$done = hrtime(true);
[, $html, $effects] = bench_livewire_result($body);

if (isset($opts['html'])) {
    // what changed on the page: the component's new HTML (a component that skipped rendering has none) and the other effects
    unset($effects['html']);
    file_put_contents($opts['html'], bench_normalise($html."\n<!-- effects -->\n".json_encode($effects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), [json_decode($prepared['snapshot'], true)['memo']['id']]));
}

bench_emit([
    'status' => $status,
    'boot_ms' => round(($booted - $started) / 1e6, 3),
    'ms' => round(($done - $booted) / 1e6, 3),
    'peak_mem_mb' => round(memory_get_peak_usage() / 1048576, 2),
    'bytes' => strlen($body),
    'rendered' => $html !== '',
] + bench_counts());

exit($status === 200 ? 0 : 1);
