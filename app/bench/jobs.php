<?php

/**
 * Imports and exports, driven the way ImportAction and ExportAction drive
 * them (same classes, same chunking), without the modal in front.
 *
 *   php bench/jobs.php --import --rows=20000 [--dispatch-only]
 *       A CSV of products whose category is named, with few distinct names.
 *       --dispatch-only does what the HTTP request does (read the file, build
 *       the jobs, batch them) with a queue that drops them, so only the
 *       request's own cost is measured. Without it, the jobs run (sync).
 *   php bench/jobs.php --export --sort=placed_at|id
 *       Export the orders sorted by that column, every job run (sync).
 *
 * Writes to the database: bin/bench puts it back afterwards.
 * Prints one JSON line: time, peak memory, queries, and the queries by table.
 */

require __DIR__.'/lib.php';

use App\Bench\Counter;
use App\Filament\Exports\OrderExporter;
use App\Filament\Imports\ProductImporter;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Exports\ExportDispatcher;
use Filament\Actions\Exports\Jobs\PrepareCsvExport;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\ImportDispatcher;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Filament\Support\ChunkIterator;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader as CsvReader;
use League\Csv\Statement;
use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;

$opts = getopt('', ['import', 'export', 'rows:', 'dispatch-only', 'sort:']);
bench_boot();
$user = User::firstOrFail();
auth()->setUser($user);

/** What a run cost, and which tables it asked how often: select queries by table, writes by kind. */
$report = function (float $ms, array $extra = []): never {
    $byTable = [];
    foreach (Counter::$queries as $q) {
        $key = preg_match('/^\s*(select|insert|update|delete)\b.*?\b(?:from|into|update)\s+"?(\w+)"?/is', $q['sql'], $m) ? strtolower($m[1]).' '.$m[2] : 'other';
        $byTable[$key] = ($byTable[$key] ?? 0) + 1;
    }
    ksort($byTable);
    bench_emit(['ms' => round($ms, 3), 'peak_mem_mb' => round(memory_get_peak_usage() / 1048576, 2), 'queries' => Counter::$counts['queries'] ?? 0,
        'by_table' => $byTable, 'offset_queries' => count(array_filter(Counter::$queries, fn ($q) => stripos($q['sql'], ' offset ') !== false))] + $extra);
    exit(0);
};

if (isset($opts['import'])) {
    $rows = (int) ($opts['rows'] ?? 1000);
    $categories = DB::table('categories')->orderBy('id')->limit(10)->pluck('name')->all();
    $file = storage_path('app/bench-import.csv');
    $out = fopen($file, 'w');
    fputcsv($out, ['sku', 'name', 'category', 'price', 'stock', 'is_visible'], escape: '');
    for ($i = 1; $i <= $rows; $i++) {
        fputcsv($out, [sprintf('IMP-%07d', $i), "Imported $i", $categories[$i % count($categories)], 100 + $i % 900, $i % 50, $i % 2 ? 'yes' : 'no'], escape: '');
    }
    fclose($out);
    $columnMap = ['sku' => 'sku', 'name' => 'name', 'category' => 'category', 'price' => 'price', 'stock' => 'stock', 'is_visible' => 'is_visible'];

    if (isset($opts['dispatch-only'])) {
        // the jobs are built and batched as in the request, then dropped instead of queued
        config(['queue.default' => 'null']);
    }
    gc_collect_cycles();
    memory_reset_peak_usage();
    Counter::reset();
    $started = hrtime(true);

    // ImportAction's action body, from opening the file to dispatching (ImportAction.php, \"$csvReader = CsvReader::from\" on)
    $reader = CsvReader::from(fopen($file, 'r'));
    $reader->setHeaderOffset(0);
    $results = (new Statement)->process($reader);
    $import = app(Import::class);
    $import->user()->associate($user);
    $import->file_name = basename($file);
    $import->file_path = $file;
    $import->importer = ProductImporter::class;
    $import->total_rows = $results->count();
    $import->save();
    app(ImportDispatcher::class)->dispatch(
        import: $import,
        importChunks: (new ChunkIterator($results->getRecords(), chunkSize: 100))->get(),
        columnMap: $columnMap,
        options: [],
        job: ImportCsv::class,
        authGuard: 'web',
    );
    $ms = (hrtime(true) - $started) / 1e6;
    @unlink($file);
    $report($ms, ['rows' => $rows, 'distinct_categories' => count($categories), 'imported' => DB::table('products')->where('sku', 'like', 'IMP-%')->count()]);
}

if (isset($opts['export'])) {
    $sort = $opts['sort'] ?? 'id';
    in_array($sort, ['id', 'placed_at'], true) || bench_fail('--sort is id or placed_at');
    $columnMap = ['number' => 'Number', 'customer.name' => 'Customer', 'status' => 'Status', 'total' => 'Total', 'placed_at' => 'Placed at'];
    Counter::reset();
    $started = hrtime(true);

    // ExportAction's action body (Concerns/CanExportRecords.php), with the table's query sorted as the user sorted it
    $query = Order::query()->orderBy($sort);
    $export = app(Export::class);
    $export->user()->associate($user);
    $export->exporter = OrderExporter::class;
    $export->total_rows = (clone $query)->count();
    $export->file_disk = 'local';
    $export->save();
    $export->file_name = 'bench-export-'.$export->getKey();
    $export->save();
    app(ExportDispatcher::class)->dispatch(
        export: $export,
        serializedQuery: EloquentSerializeFacade::serialize($query),
        columnMap: $columnMap,
        options: [],
        formats: [\Filament\Actions\Exports\Enums\ExportFormat::Csv],
        records: null,
        job: PrepareCsvExport::class,
        chunkSize: 100,
        jobQueue: null,
        jobConnection: null,
        jobBatchName: null,
        authGuard: 'web',
    );
    $ms = (hrtime(true) - $started) / 1e6;
    $export->refresh();
    $export->deleteFileDirectory();
    $report($ms, ['sort' => $sort, 'total_rows' => $export->total_rows, 'exported' => $export->successful_rows]);
}

bench_fail('--import or --export');
