<?php

namespace App\Providers;

use App\Bench\Counter;
use App\Bench\CountingDisk;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

/**
 * Wires up what the bench counts. It changes no behaviour of Filament's: the
 * listeners only count, and the `media` disk behaves like a private bucket.
 */
class BenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config(['filesystems.disks.media' => [
            'driver' => 'bench',
            'root' => storage_path('app/media'),
            'visibility' => 'private',
            'throw' => false,
        ]]);
    }

    public function boot(): void
    {
        Storage::extend('bench', function ($app, array $config) {
            $local = $app['filesystem']->createLocalDriver($config);

            return new CountingDisk($local->getDriver(), $local->getAdapter(), $config);
        });

        DB::listen(function (QueryExecuted $query) {
            Counter::add('queries');
            Counter::$queryMs += $query->time;
            Counter::$queries[] = ['sql' => $query->sql, 'ms' => $query->time];
        });

        Event::listen('eloquent.retrieved: *', function (string $event) {
            Counter::add('hydrated.'.class_basename(substr($event, strlen('eloquent.retrieved: '))));
        });
    }
}
