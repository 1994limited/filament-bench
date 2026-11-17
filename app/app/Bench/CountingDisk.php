<?php

namespace App\Bench;

use Illuminate\Filesystem\FilesystemAdapter;

/**
 * The `media` disk: files on local disk, behaving like a private S3 bucket.
 * Every call that would be a round trip to the bucket (exists, size, mime
 * type, reads) is counted, and can be made to wait BENCH_DISK_LATENCY_MS
 * milliseconds, which is what it costs against a real bucket. Temporary URLs
 * are signed locally, as S3's SDK does, and counted too.
 */
class CountingDisk extends FilesystemAdapter
{
    private function roundTrip(string $what, string $path): void
    {
        Counter::add("disk.$what");
        // and by top-level folder, so a page with several kinds of file says which field asked
        Counter::add('diskdir.'.strtok(ltrim($path, '/'), '/').".$what");
        if ($ms = (float) getenv('BENCH_DISK_LATENCY_MS')) {
            usleep((int) ($ms * 1000));
        }
    }

    public function exists($path)
    {
        $this->roundTrip('exists', $path);

        return parent::exists($path);
    }

    public function fileExists($path)
    {
        $this->roundTrip('exists', $path);

        return parent::fileExists($path);
    }

    public function size($path)
    {
        $this->roundTrip('size', $path);

        return parent::size($path);
    }

    public function mimeType($path)
    {
        $this->roundTrip('mimeType', $path);

        return parent::mimeType($path);
    }

    public function lastModified($path)
    {
        $this->roundTrip('lastModified', $path);

        return parent::lastModified($path);
    }

    public function get($path)
    {
        $this->roundTrip('get', $path);

        return parent::get($path);
    }

    public function readStream($path)
    {
        $this->roundTrip('readStream', $path);

        return parent::readStream($path);
    }

    public function url($path)
    {
        Counter::add('disk.url');
        Counter::add('diskdir.'.strtok(ltrim($path, '/'), '/').'.url');

        return 'https://media.bench.test/'.ltrim($path, '/');
    }

    public function providesTemporaryUrls()
    {
        return true;
    }

    public function temporaryUrl($path, $expiration, array $options = [])
    {
        Counter::add('disk.temporaryUrl');
        Counter::add('diskdir.'.strtok(ltrim($path, '/'), '/').'.temporaryUrl');
        // the expiry is left out of the URL, so the same page renders the same bytes at any time of day

        return 'https://media.bench.test/'.ltrim($path, '/').'?signature='.hash_hmac('sha256', $path, 'bench');
    }
}
