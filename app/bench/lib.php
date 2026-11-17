<?php

/**
 * Shared by the bench scripts: boot the app the way a web server does, send
 * a request (signed in or not, page or Livewire round trip) through the HTTP
 * kernel, report what was counted, normalise HTML.
 */

use App\Bench\Counter;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

const BENCH_ROOT = __DIR__.'/..';
const BENCH_HOST = 'http://bench.test';

/** Settings that would hide or distort what the bench measures. */
const BENCH_CONFIG = [
    'app.debug' => false,
    'session.driver' => 'array',
    'cache.default' => 'array',
    'queue.default' => 'sync',
];

/**
 * Boot the app with the request it is about to handle already bound, as a
 * web server would, because providers read it while booting.
 */
function bench_boot(string $uri = '/', string $method = 'GET'): Kernel
{
    require_once BENCH_ROOT.'/vendor/autoload.php';
    $app = require BENCH_ROOT.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $app->instance('request', Request::create(BENCH_HOST.$uri, $method));
    $kernel->bootstrap();
    foreach (BENCH_CONFIG as $key => $want) {
        if (config($key) !== $want) {
            fwrite(STDERR, "bench: config $key must be ".var_export($want, true).', got '.var_export(config($key), true)."\n");
            exit(2);
        }
    }

    return $kernel;
}

/** Sign in for this process's requests: the user is put on the panel's guard, as the session would. */
function bench_sign_in(string $email): void
{
    $user = User::where('email', $email)->first() ?? bench_fail("no user $email");
    auth()->guard('web')->setUser($user);
    auth()->shouldUse('web');
}

/**
 * One request through the kernel, with the headers a browser sends on a
 * same-origin request (so CSRF protection stays on and passes, as it does
 * for a real user). Returns [status, body].
 */
function bench_request(Kernel $kernel, string $uri, string $method = 'GET', array $server = [], ?string $content = null): array
{
    $request = Request::create(BENCH_HOST.$uri, $method, server: ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_ACCEPT' => 'text/html'] + $server, content: $content);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return [$response->getStatusCode(), (string) $response->getContent()];
}

/** One Livewire round trip for one component: its snapshot from the last response, then updates and calls. */
function bench_livewire(Kernel $kernel, string $snapshot, array $updates, array $calls): array
{
    $payload = json_encode(['components' => [[
        'snapshot' => $snapshot,
        'updates' => (object) $updates,
        'calls' => array_map(fn ($c) => ['path' => '', 'method' => $c['method'], 'params' => $c['params'], 'metadata' => (object) []], $calls),
    ]]], JSON_UNESCAPED_SLASHES);

    return bench_request($kernel, EndpointResolver::updatePath(), 'POST', [
        'HTTP_X_LIVEWIRE' => '1', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], $payload);
}

/** Every Livewire component's snapshot in a page, by component name (the first of each name). */
function bench_snapshots(string $html): array
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $m);
    $out = [];
    foreach ($m[1] as $raw) {
        $json = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);
        $name = json_decode($json, true)['memo']['name'] ?? null;
        $name === null || isset($out[$name]) || $out[$name] = $json;
    }

    return $out;
}

/** The snapshot and HTML a Livewire response returned for its (only) component. */
function bench_livewire_result(string $body): array
{
    $data = json_decode($body, true);
    $component = $data['components'][0] ?? [];

    return [$component['snapshot'] ?? null, $component['effects']['html'] ?? '', $component['effects'] ?? []];
}

/** What the request did, beyond its time: queries, storage round trips, models hydrated. */
function bench_counts(): array
{
    return [
        'queries' => Counter::$counts['queries'] ?? 0,
        'query_ms' => round(Counter::$queryMs, 3),
        'disk' => Counter::prefixed('disk.'),
        'disk_by_folder' => Counter::prefixed('diskdir.'),
        'hydrated' => Counter::prefixed('hydrated.'),
    ];
}

/** Strip what legitimately differs between two renders of the same data. */
function bench_normalise(string $html, array $ids = []): string
{
    // Livewire makes up component ids per request, and Filament builds keys from them, often glued to other text
    preg_match_all('/wire:id=\"([A-Za-z0-9]{20})\"/', $html, $found);
    foreach (array_unique([...$ids, ...$found[1]]) as $id) {
        $html = str_replace($id, 'LWID', $html);
    }
    $html = preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="CSRF"', $html);
    $html = preg_replace('/<meta name="csrf-token" content="[^"]*"/', '<meta name="csrf-token" content="CSRF"', $html);
    $html = preg_replace('/data-csrf="[^"]*"/', 'data-csrf="CSRF"', $html);
    $html = preg_replace('/wire:snapshot="[^"]*"/', 'wire:snapshot="X"', $html);
    $html = preg_replace('/wire:effects="[^"]*"/', 'wire:effects="X"', $html);
    $html = preg_replace('/\b[A-Za-z0-9]{20}\b/', 'LWID', $html);
    // ids derived from those (md5s of component keys), Livewire's child keys and script keys, which hash the compiled view's path
    $html = preg_replace('/\b[0-9a-f]{32}\b/', 'MD5', $html);
    $html = preg_replace('/\blw-\d+-\d+-\d*/', 'lw-N', $html);
    $html = preg_replace('/"\d{5,}-\d+"/', '"N-0"', $html);
    // a lazy component's payload carries its id and a checksum; some keys end in Str::random(16)
    $html = preg_replace('/__lazyLoad\(&#039;[^&]*&#039;\)/', '__lazyLoad(X)', $html);
    $html = preg_replace('/(wire:key="[^"]*\.)[A-Za-z0-9]{16}"/', '$1RANDOM"', $html);
    // asset URLs carry the installed version
    $html = preg_replace('/\?v=[0-9a-zA-Z.\-+]+/', '?v=V', $html);
    $html = preg_replace('/[ \t]+$/m', '', $html);
    $html = preg_replace('/\n{2,}/', "\n", $html);

    return trim($html)."\n";
}

function bench_manifest(): array
{
    return json_decode(file_get_contents(BENCH_ROOT.'/bench/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
}

function bench_emit(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES)."\n";
}

/** Collects named pass/fail checks; an exception inside one is a failure, not a crash. */
final class BenchChecks
{
    public array $results = [];

    public function check(string $name, callable $test): void
    {
        $started = hrtime(true);
        try {
            $detail = $test();
            $result = ['name' => $name, 'pass' => $detail === null, 'detail' => (string) $detail];
        } catch (Throwable $e) {
            $result = ['name' => $name, 'pass' => false, 'detail' => get_class($e).': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()];
        }
        $this->results[] = $result + ['seconds' => round((hrtime(true) - $started) / 1e9, 1)];
    }
}

function bench_fail(string $message): never
{
    fwrite(STDERR, "bench: $message\n");
    exit(1);
}

/**
 * Run another bench script in its own PHP process, with the same PHP settings
 * as this one (bin/bench passes the commands in BENCH_CHILD and
 * BENCH_CHILD_XDEBUG), and decode the JSON line it prints.
 */
function bench_child(array $script, array $env = [], bool $xdebug = false): array
{
    $php = json_decode((string) getenv($xdebug ? 'BENCH_CHILD_XDEBUG' : 'BENCH_CHILD'), true) ?: [PHP_BINARY];
    $xdebug && array_push($php, '-d', 'xdebug.mode=develop');
    $proc = proc_open([...$php, ...$script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BENCH_ROOT, [...getenv(), ...$env]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $code = proc_close($proc);
    $lines = array_filter(explode("\n", trim($out)));
    $data = json_decode((string) end($lines), true);
    if ($code !== 0 || ! is_array($data)) {
        throw new RuntimeException(implode(' ', $script).' failed (exit '.$code.'): '.substr(trim($err.' '.$out), 0, 800));
    }

    return $data;
}
