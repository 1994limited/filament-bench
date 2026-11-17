<?php

/**
 * Tests the findings of the performance report (findings/report.md) against
 * the Filament this target has. Each finding measures what the report says
 * costs: the SQL a page runs, how often a function is called, storage round
 * trips, bytes, or the time with and without the thing the finding blames
 * (see App\Bench\Variant). It then says whether the problem shows on this
 * target.
 *
 *   php bench/findings.php [--only=#2,#4,3.1-b]
 *
 * Runs under Xdebug (bin/bench loads it) for the function counts; anything
 * timed runs in a child process without Xdebug. Prints one JSON line:
 * {"findings":{"#1":{"title":..,"shows":true|false|null,"summary":..,"data":{..}}, ...}}
 */

require __DIR__.'/lib.php';

use App\Models\Order;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Http\Middleware\PublishNotifications;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\DateConstraint\Operators\IsAfterOperator;
use Filament\QueryBuilder\Constraints\DateConstraint\Operators\IsYearOperator;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint\Operators\ContainsOperator;
use Filament\QueryBuilder\Constraints\TextConstraint\Operators\EqualsOperator;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;

use function Filament\Support\generate_search_column_expression;

$opts = getopt('', ['only:']);
$only = isset($opts['only']) ? explode(',', $opts['only']) : null;
bench_boot();
function_exists('xdebug_start_function_monitor') || bench_fail('findings.php needs Xdebug (bin/bench findings loads it)');
$m = bench_manifest();
$as = $m['user'];
$rows = 50;   // every list page shows 50 records

// ------------------------------------------------------------------ helpers

/** One request in a fresh process (probe.php): a page by name, or a Livewire scenario by name. */
function probe(string $name, array $flags = [], array $env = [], bool $xdebug = false): array
{
    $m = $GLOBALS['m'];
    if (isset($m['pages'][$name])) {
        [$url, , $signedIn] = $m['pages'][$name];
        $args = ["--url=$url", ...($signedIn ? ["--as={$m['user']}"] : [])];
    } else {
        $args = ["--scenario=$name", '--snapshot='.BENCH_ROOT."/storage/framework/bench/$name.snapshot.json", "--as={$m['user']}"];
    }
    $result = bench_child(['bench/probe.php', ...$args, ...$flags], $env, $xdebug);
    $result['status'] === ($m['pages'][$name][1] ?? 200) || throw new RuntimeException("$name returned {$result['status']}");

    return $result;
}

/** The same request with Xdebug's function monitor counting these functions. */
function calls(string $name, array $functions, array $env = []): array
{
    return probe($name, ['--monitor='.implode(',', $functions)], $env, true)['calls'];
}

/** The SQL a request ran. */
function sql(string $name, array $env = []): array
{
    return probe($name, ['--sql'], $env)['sql'];
}

/** The HTML a request returned (a page, or a Livewire component's new HTML). */
function html(string $name, array $env = []): string
{
    $file = tempnam(sys_get_temp_dir(), 'bench-html');
    probe($name, ["--html=$file"], $env);
    $html = file_get_contents($file);
    unlink($file);

    return $html;
}

/** Median time of a request over $n samples, after one untimed, in processes without Xdebug. */
function timed(string $name, array $env = [], int $n = 7): float
{
    probe($name, [], $env);
    $ms = [];
    for ($i = 0; $i < $n; $i++) {
        $ms[] = probe($name, [], $env)['ms'];
    }
    sort($ms);

    return $ms[intdiv($n, 2)];
}

/** Two timings, A then B, interleaved so drift affects both; medians and the difference. */
function ab(string $name, array $envA, array $envB, int $n = 7): array
{
    probe($name, [], $envA);
    probe($name, [], $envB);
    [$a, $b] = [[], []];
    for ($i = 0; $i < $n; $i++) {
        $a[] = probe($name, [], $envA)['ms'];
        $b[] = probe($name, [], $envB)['ms'];
    }
    sort($a);
    sort($b);
    [$ma, $mb] = [$a[intdiv($n, 2)], $b[intdiv($n, 2)]];

    return ['a_ms' => round($ma, 1), 'b_ms' => round($mb, 1), 'diff_ms' => round($ma - $mb, 1), 'diff_pct' => round(100 * ($ma - $mb) / $mb, 1),
        'noise_ms' => round(max($a[$n - 2] - $a[1], $b[$n - 2] - $b[1]) / 2, 1)];
}

function count_matching(array $sql, string $pattern): int
{
    return count(preg_grep($pattern, $sql));
}

/** How often each function was called while $run ran, in this process. */
function monitored(array $functions, callable $run): array
{
    xdebug_start_function_monitor($functions);
    $run();
    $counts = array_fill_keys($functions, 0);
    foreach (xdebug_get_monitored_functions() as $call) {
        $counts[$call['function']] = ($counts[$call['function']] ?? 0) + 1;
    }
    xdebug_stop_function_monitor();

    return $counts;
}

function variant(string $name): array
{
    return ['BENCH_VARIANT' => $name];
}

function restore_database(): void
{
    DB::purge();
    copy(BENCH_ROOT.'/bench/pristine.sqlite', BENCH_ROOT.'/database/database.sqlite');
}

/** SQLite's plan for a query, one line. */
function plan(string $sql, array $bindings): string
{
    return implode(' / ', array_map(fn ($r) => $r->detail, DB::select("explain query plan $sql", $bindings)));
}

/** A query on another database's grammar, never run (the connection is lazy), to see the SQL Filament writes there. */
function sql_on(string $connection, callable $build): string
{
    $query = (new Order)->setConnection($connection)->newQuery();
    $build($query);

    return $query->toSql();
}

$findings = [];
$finding = function (string $id, string $title, callable $measure) use (&$findings, $only) {
    if ($only && ! in_array($id, $only, true)) {
        return;
    }
    fwrite(STDERR, "  $id $title\n");
    $started = hrtime(true);
    try {
        [$shows, $summary, $data] = $measure() + [2 => []];
        $findings[$id] = ['title' => $title, 'shows' => $shows, 'summary' => $summary, 'data' => $data];
    } catch (Throwable $e) {
        $findings[$id] = ['title' => $title, 'shows' => null, 'summary' => 'ERROR: '.$e->getMessage(), 'data' => ['at' => basename($e->getFile()).':'.$e->getLine()]];
    }
    $findings[$id]['seconds'] = round((hrtime(true) - $started) / 1e9, 1);
};

const REFLECTION = 'ReflectionFunction->__construct';
const EVALUATE = 'Filament\Support\Components\Component->evaluate';

// ============================================================ top 20

$finding('#1', 'evaluate() builds a ReflectionFunction per closure evaluation', function () {
    $component = TextInput::make('x');
    $closure = fn () => 'X';
    $micro = monitored([REFLECTION], function () use ($component, $closure) {
        for ($i = 0; $i < 100; $i++) {
            $component->evaluate($closure);
        }
    })[REFLECTION];
    $page = calls('customers_index', [REFLECTION, EVALUATE]);
    $form = calls('big_form', [REFLECTION, EVALUATE]);
    // the same form with its 200 visibility closures replaced by booleans
    $cost = ab('big_form', [], variant('no_visible_closures'));

    return [$micro >= 100, "the same closure evaluated 100× built $micro ReflectionFunctions; customers_index builds {$page[REFLECTION]}, big_form {$form[REFLECTION]}; big_form's 200 visible() closures cost {$cost['diff_ms']} ms ({$cost['diff_pct']}%)",
        ['same_closure_100x' => $micro, 'customers_index' => $page, 'big_form' => $form, 'big_form_closures_vs_booleans' => $cost]];
});

$finding('#2', 'ImageColumn checks every image exists, and presigns it', function () use ($rows) {
    $default = probe('customers_index')['disk_by_folder'];
    $off = probe('customers_index', [], variant('no_exists_check'))['disk_by_folder'];
    // what it costs against a bucket 20 ms away
    $latency = ['BENCH_DISK_LATENCY_MS' => '20'];
    $cost = ab('customers_index', $latency, $latency + variant('no_exists_check'), 3);
    $exists = $default['avatars.exists'] ?? 0;

    return [$exists > 0, "$exists exists() and ".($default['avatars.temporaryUrl'] ?? 0)." temporaryUrl() for $rows rows (".($off['avatars.exists'] ?? 0).' with checkFileExistence(false)); at 20 ms per round trip that is '.$cost['diff_ms'].' ms per page',
        ['default' => $default, 'check_off' => $off, 'with_20ms_latency' => $cost]];
});

$finding('#3', 'Widgets poll every 5 s by default', function () {
    $polls = [];
    foreach (['stats_lazy_load', 'chart_lazy_load', 'notifications_lazy_load'] as $name) {
        preg_match_all('/wire:poll(?:\.[a-z]+)*\.(\d+m?s)/', html($name), $mm);
        $polls[$name] = array_values(array_unique($mm[1]));
    }
    $stats = probe('stats_poll');
    $chart = probe('chart_poll');
    $five = array_keys(array_filter($polls, fn ($p) => in_array('5s', $p, true)));

    return [(bool) $five, ($five ? implode(', ', $five).' poll every 5 s' : 'no 5 s poll').". Each poll: stats {$stats['queries']} queries, chart {$chart['queries']} queries (Livewire 4 batches pollers on one timer into one request per tick)",
        ['intervals' => $polls, 'stats_poll' => ['queries' => $stats['queries'], 'bytes' => $stats['bytes'], 'ms' => $stats['ms']], 'chart_poll' => ['queries' => $chart['queries'], 'bytes' => $chart['bytes']]]];
});

$finding('#4', 'Select-all count hydrates the whole table with checkIfRecordIsSelectableUsing', function () use ($m, $rows) {
    $with = probe('customers_index')['hydrated']['Customer'] ?? 0;
    $without = probe('customers_index', [], variant('no_selectable_check'))['hydrated']['Customer'] ?? 0;
    $total = $m['counts']['customers'];

    return [$with >= $total, "$with customers hydrated to show $rows ($without without the selectable check; the table has $total)", ['with_check' => $with, 'without_check' => $without, 'total' => $total]];
});

$finding('#5', 'getRedirectUrl() builds a second NavigationManager', function () {
    $n = calls('dashboard', ['Filament\Navigation\NavigationManager->__construct'])['Filament\Navigation\NavigationManager->__construct'];

    return [$n > 1, "$n NavigationManager per dashboard request. This panel has no tenancy; the review found the extra ones only on tenantDomain() panels and the / redirect", ['constructed' => $n]];
});

$finding('#6', 'Navigation badges run their COUNT on every page', function () {
    $badge = '/^select count\(\*\) as "aggregate" from "(?!notifications)/';
    $counts = [];
    foreach (['dashboard', 'customers_index', 'order_edit', 'customers_search', 'order_edit_live'] as $name) {
        $counts[$name] = count_matching(sql($name), $badge);
    }

    return [$counts['dashboard'] >= 8, "{$counts['dashboard']} badge COUNTs on the dashboard, {$counts['customers_index']} on a list page (8 resources have a badge); {$counts['customers_search']} on a table search and {$counts['order_edit_live']} on a live field update",
        ['count_queries' => $counts, 'note' => 'customers_index has one more count(*): the table\'s own pagination count']];
});

$finding('#7', 'ImportAction builds every job inside the HTTP request', function () {
    $small = bench_child(['bench/jobs.php', '--import', '--rows=1000', '--dispatch-only']);
    restore_database();
    $large = bench_child(['bench/jobs.php', '--import', '--rows=20000', '--dispatch-only']);
    restore_database();
    $perRows = ($large['peak_mem_mb'] - $small['peak_mem_mb']) / 19;

    return [$perRows > 0.2, sprintf('the request that starts an import: %.1f MB / %d ms for 1,000 rows, %.1f MB / %d ms for 20,000 (%.2f MB more per 1,000 rows)', $small['peak_mem_mb'], $small['ms'], $large['peak_mem_mb'], $large['ms'], $perRows),
        ['rows_1000' => $small, 'rows_20000' => $large]];
});

$finding('#8', 'Import looks the relationship up again for every row', function () {
    $run = bench_child(['bench/jobs.php', '--import', '--rows=500']);
    restore_database();
    $lookups = $run['by_table']['select categories'] ?? 0;

    return [$lookups >= $run['rows'], "$lookups category lookups for {$run['rows']} rows over {$run['distinct_categories']} distinct categories ({$run['queries']} queries in all)", $run];
});

$finding('#9', 'Export sorted by a non-key column chunks with OFFSET', function () {
    $byId = bench_child(['bench/jobs.php', '--export', '--sort=id']);
    restore_database();
    $byDate = bench_child(['bench/jobs.php', '--export', '--sort=placed_at']);
    restore_database();

    return [$byDate['offset_queries'] > 0, "sorted by placed_at: {$byDate['offset_queries']} OFFSET queries, {$byDate['queries']} in all; sorted by id: {$byId['offset_queries']}, {$byId['queries']} in all ({$byDate['total_rows']} rows; OFFSET scans grow as n²/chunk)",
        ['sort_id' => $byId, 'sort_placed_at' => $byDate]];
});

$finding('#10', 'A live update walks the schema tree', function () {
    $fns = ['Filament\Schemas\Schema->callAfterStateUpdated', 'Filament\Schemas\Components\Component->getChildSchemas'];
    $first = calls('big_form_live_first', $fns) + probe('big_form_live_first', ['--resolve=Filament\Schemas\Schema'])['resolved'];
    $last = calls('big_form_live_last', $fns) + probe('big_form_live_last', ['--resolve=Filament\Schemas\Schema'])['resolved'];
    $time = ['first' => round(timed('big_form_live_first', [], 5), 1), 'last' => round(timed('big_form_live_last', [], 5), 1)];
    $walk = 'Filament\Schemas\Schema->callAfterStateUpdated';

    return [$last[$walk] > 2 * $first[$walk], "blur on the first of 200 fields: {$first[$walk]} schemas walked, {$time['first']} ms; on the last: {$last[$walk]}, {$time['last']} ms",
        ['first_field' => $first, 'last_field' => $last, 'ms' => $time]];
});

/** A schema of $n Sections of two fields each, on a bare Livewire component, as a page would hold it. */
function sections_schema(int $n): Schema
{
    $host = new class extends Livewire\Component implements Filament\Schemas\Contracts\HasSchemas
    {
        use Filament\Schemas\Concerns\InteractsWithSchemas;

        public ?array $data = [];

        public function render(): string
        {
            return '';
        }
    };

    return Schema::make($host)->statePath('data')->components(array_map(fn ($i) => Section::make("S$i")->schema([TextInput::make("a$i"), TextInput::make("b$i")]), range(1, $n)));
}

$finding('#11', "getChildSchema('default') is never cached", function () {
    $section = sections_schema(1)->getComponents(withHidden: true)[0];
    $same = $section->getChildSchema() === $section->getChildSchema();
    $page = probe('big_form', ['--resolve=Filament\Schemas\Schema'])['resolved']['Filament\Schemas\Schema'];

    return [! $same, 'two calls on one Section return '.($same ? 'the same Schema' : 'two different Schemas')."; big_form (24 layout components) resolves $page Schemas per render", ['same_instance' => $same, 'big_form_schemas' => $page]];
});

$finding('#12', 'clearCachedChildSchemas() builds the tree in order to clear it', function () {
    $schema = sections_schema(10);
    $schema->getComponents(withHidden: true);
    $built = 0;
    app()->resolving(Schema::class, function () use (&$built) {
        $built++;
    });
    $schema->clearCachedChildSchemas();

    return [$built > 0, "clearing a schema of 10 Sections built $built new Schemas", ['schemas_built' => $built]];
});

$finding('#13', 'Relationship Select runs its query twice when filling', function () {
    // the badges count these tables too; they are not the Select's
    $order = preg_grep('/^(?!select count\(\*\)).*from "customers"/', sql('order_edit'));
    $customer = preg_grep('/^(?!select count\(\*\)).*from "countries"/', sql('customer_edit'));

    return [count($order) >= 2, count($order).' queries on customers to fill the searchable BelongsTo Select on an order, '.count($customer).' on countries for the non-searchable one on a customer (the review says the double query needs preload() or multiple())',
        ['order_edit' => array_values($order), 'customer_edit' => array_values($customer)]];
});

$finding('#14', 'Each Repeater item runs the Select\'s queries again', function () {
    $products = preg_grep('/from "products"/', sql('order_edit'));
    $options = preg_grep('/^select "products"\."name", "products"\."id" from "products"/', $products);

    return [count($options) >= 20, count($options).' identical option queries for a Repeater of 20 items with a relationship Select ('.count($products).' queries on products in all)', ['option_queries' => count($options), 'product_queries' => array_values(array_unique($products))]];
});

$finding('#15', 'disableOptionsWhenSelectedInSiblingRepeaterItems() is O(items² × options)', function () {
    $cost = ab('order_edit', [], variant('no_sibling_disable'));

    return [$cost['diff_ms'] > max(5, 2 * $cost['noise_ms']), "with it {$cost['a_ms']} ms, without {$cost['b_ms']} ms for 20 items × {$GLOBALS['m']['counts']['products']} options ({$cost['diff_pct']}%)", $cost];
});

$finding('#16', 'Postgres search and text constraints become lower(col::text) like', function () {
    $eq = fn ($q) => EqualsOperator::make()->constraint(TextConstraint::make('number'))->settings(['text' => 'ORD-0000001'])->inverse(false)->apply($q, 'orders.number');
    $contains = fn ($q) => ContainsOperator::make()->constraint(TextConstraint::make('number'))->settings(['text' => '0001'])->inverse(false)->apply($q, 'orders.number');
    $sql = [
        'equals_pgsql' => sql_on('pgsql', $eq), 'equals_mysql' => sql_on('mysql', $eq), 'equals_sqlite' => sql_on('sqlite', $eq),
        'contains_pgsql' => sql_on('pgsql', $contains),
        'table_search_column_pgsql' => (fn ($e) => is_string($e) ? $e : $e->getValue(DB::connection('pgsql')->getQueryGrammar()))(generate_search_column_expression('name', null, DB::connection('pgsql'))),
    ];
    // on SQLite, the plan for Equals against the unique index on orders.number, and for a plain =
    $query = Order::query();
    $eq($query);
    $plans = ['equals' => plan($query->toSql(), $query->getBindings()), 'plain_equals' => plan('select * from orders where number = ?', ['ORD-0000001'])];
    $shows = str_contains($sql['equals_pgsql'], 'lower(') && str_contains($sql['equals_pgsql'], '::text') && str_contains($sql['equals_pgsql'], ' like ');

    return [$shows, "Equals on Postgres: {$sql['equals_pgsql']}; on SQLite its plan is \"{$plans['equals']}\" where = gives \"{$plans['plain_equals']}\"", ['sql' => $sql, 'sqlite_plans' => $plans]];
});

$finding('#17', 'Query-builder date operators wrap the column, so no index is used', function () {
    $after = fn ($q) => IsAfterOperator::make()->constraint(DateConstraint::make('placed_at'))->settings(['date' => '2026-01-01'])->inverse(false)->apply($q, 'orders.placed_at');
    $year = fn ($q) => IsYearOperator::make()->constraint(DateConstraint::make('placed_at'))->settings(['year' => 2025])->inverse(false)->apply($q, 'orders.placed_at');
    $sql = ['is_after_sqlite' => sql_on('sqlite', $after), 'is_after_mysql' => sql_on('mysql', $after), 'is_after_pgsql' => sql_on('pgsql', $after), 'is_year_pgsql' => sql_on('pgsql', $year)];
    $query = Order::query();
    $after($query);
    $plans = ['is_after' => plan($query->toSql(), $query->getBindings()), 'range' => plan('select * from orders where placed_at >= ?', ['2026-01-01 00:00:00'])];
    $time = fn (string $q, array $b) => (function () use ($q, $b) {
        DB::select($q, $b);
        $t = hrtime(true);
        for ($i = 0; $i < 5; $i++) {
            DB::select($q, $b);
        }

        return round((hrtime(true) - $t) / 5e6, 2);
    })();
    $ms = ['is_after' => $time($query->toSql(), $query->getBindings()), 'range' => $time('select * from orders where placed_at >= ?', ['2026-01-01 00:00:00'])];

    return [str_contains($plans['is_after'], 'SCAN') && ! str_contains($plans['is_after'], 'INDEX'), "IsAfter on SQLite: \"{$plans['is_after']}\" ({$ms['is_after']} ms); a range: \"{$plans['range']}\" ({$ms['range']} ms)",
        ['sql' => $sql, 'sqlite_plans' => $plans, 'sqlite_ms' => $ms]];
});

$finding('#18', 'Echo + Pusher loads on every panel page', function () {
    preg_match('/<script[^>]+src="([^"]*echo\.js[^"]*)"[^>]*>/', html('dashboard'), $tag);
    $file = BENCH_ROOT.'/public/js/filament/filament/echo.js';
    $bytes = is_file($file) ? filesize($file) : 0;

    return [(bool) $tag, ($tag ? 'echo.js is loaded on the dashboard' : 'echo.js is not on the dashboard').' with broadcasting off ('.number_format($bytes).' bytes, '.number_format(strlen(gzencode((string) @file_get_contents($file), 9))).' gzipped)',
        ['script' => $tag[0] ?? null, 'bytes' => $bytes, 'broadcasting' => config('broadcasting.default')]];
});

$finding('#19', 'Code editor bundles every CodeMirror language', function () {
    $file = BENCH_ROOT.'/public/js/filament/forms/components/code-editor.js';
    $js = (string) file_get_contents($file);
    $lazy = preg_match_all('/\bimport\(/', $js);

    return [strlen($js) > 300000 && ! $lazy, sprintf('code-editor.js is %s bytes (%s gzipped) with %d dynamic imports', number_format(strlen($js)), number_format(strlen(gzencode($js, 9))), $lazy), ['bytes' => strlen($js), 'gzip' => strlen(gzencode($js, 9)), 'dynamic_imports' => $lazy]];
});

$finding('#20', 'SpatieTagsInput loads every tag as a suggestion', function () {
    $tags = preg_grep('/from "tags"/', sql('product_edit'));
    $unlimited = preg_grep('/^select "name" from "tags"(?!.* limit )/', $tags) ?: preg_grep('/from "tags"(?!.* limit )(?!.*where)/', $tags);
    preg_match_all('/\b[A-Z][a-z]+-\d+\b/', html('product_edit'), $names);
    $inPage = count(array_unique($names[0]));
    $total = $GLOBALS['m']['counts']['tags'];

    return [$inPage >= $total, "$inPage of $total tags are written into the edit page; ".count($unlimited).' tag query without a limit', ['queries' => array_values($tags), 'tags_in_html' => $inPage, 'tags_total' => $total]];
});

// ============================================================ tables

$finding('3.1-b', 'SelectFilter on a BelongsTo uses whereHas', function () {
    $listing = preg_grep('/^select \* from "customers"/', sql('customers_filter_country'));
    $exists = count(preg_grep('/exists \(select/', $listing));

    return [$exists > 0, $exists ? 'filtering by country runs a correlated EXISTS instead of where country_id in (…)' : 'filtering by country uses the foreign key', ['listing_sql' => array_values($listing)]];
});

$finding('3.1-e', 'Search adds a whereHas per relationship column per word', function () {
    $sql = sql('customers_search_words');
    $per = array_map(fn ($q) => substr_count($q, 'exists ('), array_values(preg_grep('/from "customers"/', $sql)));

    return [max($per ?: [0]) >= 3, 'a 3-word search runs '.implode(' and ', $per).' EXISTS subqueries (one relationship column)', ['exists_per_query' => $per]];
});

$finding('3.1-h', 'SelectColumn evaluates its options for every cell', function () use ($rows) {
    $closure = count(preg_grep('/from "categories"/', sql('products_index')));
    $static = count(preg_grep('/from "categories"/', sql('products_index', variant('static_options'))));

    return [$closure >= $rows, "$closure category queries for $rows rows with options(fn () => …), $static with an array", ['closure' => $closure, 'array' => $static]];
});

$finding('3.2-b', 'SelectColumn writes its whole option list into every row', function () use ($rows) {
    $html = html('products_index');
    $options = preg_match_all('/<option\b/', $html);

    return [$options >= $rows * $GLOBALS['m']['counts']['categories'], sprintf('%d <option>s for %d rows × %d categories; page %s KB', $options, $rows, $GLOBALS['m']['counts']['categories'], number_format(strlen($html) / 1024)), ['options' => $options, 'bytes' => strlen($html)]];
});

$finding('3.2-f', 'A new NumberFormatter for every money cell', function () use ($rows) {
    $n = calls('customers_index', ['NumberFormatter->__construct'])['NumberFormatter->__construct'];

    return [$n >= $rows, "$n NumberFormatters for $rows rows with one money column", ['constructed' => $n]];
});

$finding('3.2-g', 'A new CommonMark converter for every markdown cell', function () use ($rows) {
    $fn = 'League\CommonMark\Environment\Environment->__construct';
    $n = calls('products_index', [$fn])[$fn];

    return [$n >= $rows, "$n CommonMark environments for $rows rows with one markdown column", ['constructed' => $n]];
});

$finding('3.2-h', 'TextColumn leaves its fast path for any HasIcon enum', function () {
    $cost = ab('customers_index', [], variant('plain_enum'));
    $bytes = [strlen(html('customers_index')), strlen(html('customers_index', variant('plain_enum')))];

    return [$cost['diff_ms'] > max(2, 2 * $cost['noise_ms']), "status enum with HasIcon (no icon): {$cost['a_ms']} ms; without HasIcon: {$cost['b_ms']} ms ({$cost['diff_pct']}%); HTML ".number_format($bytes[0]).' vs '.number_format($bytes[1]).' bytes',
        $cost + ['bytes' => $bytes]];
});

$finding('3.2-j', 'The loading-targets string is repeated in every control', function () {
    $html = html('customers_index');
    preg_match_all('/wire:target="[^"]{200,}"/', $html, $mm);
    $bytes = array_sum(array_map('strlen', $mm[0]));

    return [count($mm[0]) > 10, count($mm[0]).' copies, '.number_format($bytes).' bytes of '.number_format(strlen($html)).' on the customers list', ['copies' => count($mm[0]), 'bytes' => $bytes]];
});

// ============================================================ schemas and forms

$finding('3.4-a', 'RichEditor checks every embedded image exists', function () {
    $disk = probe('product_edit')['disk_by_folder'];

    return [($disk['attachments.exists'] ?? 0) >= 10, ($disk['attachments.exists'] ?? 0).' exists() and '.($disk['attachments.temporaryUrl'] ?? 0).' temporaryUrl() for a description with 10 images', ['disk' => $disk]];
});

$finding('3.4-d', 'FileUpload makes round trips per file', function () {
    $disk = probe('product_edit')['disk_by_folder'];
    $products = array_filter($disk, fn ($k) => str_starts_with($k, 'products.'), ARRAY_FILTER_USE_KEY);

    // the size and type calls the report counts are in getUploadedFiles, a later round trip the bench doesn't send
    return [array_sum($products) > 6, array_sum($products).' storage calls to show 6 existing files when the edit page loads ('.json_encode($products).'); the size and MIME type calls come in a later round trip, not measured', ['disk' => $products]];
});

$finding('3.4-f', 'A relationship Repeater re-queries its records on every Livewire request', function () {
    $items = preg_grep('/from "order_items"/', sql('order_edit_live'));

    return [count($items) > 0, count($items).' order_items queries when an unrelated live field changes', ['sql' => array_values($items)]];
});

$finding('3.8-inf-a', 'RepeatableEntry over a relationship is N+1', function () {
    $lazy = preg_grep('/^select \* from "products" where "products"\."id" = \?/', sql('order_view'));

    return [count($lazy) >= 20, count($lazy).' single-product queries to show 20 order items', ['queries' => count($lazy)]];
});

// ============================================================ panels and support

$finding('3.5-g', 'The record is fetched twice on record pages', function () {
    $orders = preg_grep('/^select \* from "orders" where "orders"\."id" = \?/', sql('order_edit_live'));

    return [count($orders) >= 2, count($orders).' queries for the order on one live update', ['queries' => count($orders)]];
});

$finding('3.6-c', 'The loading spinner SVG is inlined in every control', function () {
    $html = html('customers_index');
    preg_match_all('/<svg[^>]*fi-loading-indicator.*?<\/svg>/s', $html, $mm);
    $bytes = array_sum(array_map('strlen', $mm[0]));

    return [count($mm[0]) > 10, count($mm[0]).' spinners, '.number_format($bytes).' bytes of '.number_format(strlen($html)).' on the customers list', ['spinners' => count($mm[0]), 'bytes' => $bytes]];
});

$finding('3.8-w-a', 'ChartWidget re-renders on every poll', function () {
    $poll = probe('chart_poll');
    $html = html('chart_poll');

    return [$html !== '', ($html !== '' ? 'every poll re-renders the chart' : 'a poll with unchanged data skips rendering')." ({$poll['bytes']} bytes, {$poll['queries']} queries)", ['bytes' => $poll['bytes'], 'queries' => $poll['queries']]];
});

$finding('3.8-n-a', 'The notifications poll counts unread with a JSON filter, and checks exists() too', function () {
    $sql = array_values(preg_grep('/from "notifications"/', sql('notifications_poll')));
    $exists = count(preg_grep('/^select exists/', $sql));

    return [$exists > 0, count($sql)." notification queries per poll ($exists exists())", ['sql' => $sql]];
});

$finding('3.8-n-c', 'PublishNotifications is in the global middleware stack', function () {
    $global = app(Kernel::class)->hasMiddleware(PublishNotifications::class);

    return [$global, $global ? 'every request goes through it; it decodes every Livewire response' : 'it is not global', ['global' => $global]];
});

// ============================================================ frontend payload

$finding('3.9', 'Bundle sizes', function () {
    $sizes = [];
    foreach (['js', 'css'] as $type) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BENCH_ROOT."/public/$type/filament", FilesystemIterator::SKIP_DOTS)) as $file) {
            if (in_array($file->getExtension(), ['js', 'css']) && $file->getSize() > 50000) {
                $sizes[substr($file->getPathname(), strlen(BENCH_ROOT.'/public/'))] = ['bytes' => $file->getSize(), 'gzip' => strlen(gzencode(file_get_contents($file->getPathname()), 9))];
            }
        }
    }
    uasort($sizes, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
    $top = array_slice($sizes, 0, 3, true);

    return [null, implode(', ', array_map(fn ($k, $v) => basename($k).' '.round($v['bytes'] / 1024).' KB', array_keys($top), $top)).'; '.count($sizes).' files over 50 KB', $sizes];
});

bench_emit(['findings' => $findings]);
