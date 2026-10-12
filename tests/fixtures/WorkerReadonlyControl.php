<?php
declare(strict_types=1);

// A real serving-boundary control: make bin/worker.php's actual PDO query-only
// after generation startup. No production flag, replacement PDO or worker implementation.
final class WorkerReadonlyControl extends php_user_filter
{
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while (true) {
            $bucket = stream_bucket_make_writeable($in);
            if (!$bucket) { break; }
            if (str_contains($bucket->data, 'Worker started.')) {
                (\Tablo\Tests\Support\FixtureNative::callback($this->params))();
            }
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
stream_filter_register('tablo.readonly-control', WorkerReadonlyControl::class);
stream_filter_append(STDOUT, filter_name: 'tablo.readonly-control', params: static function () use (&$db): void {
    if (!$db instanceof PDO) { throw new RuntimeException('Owned serving database is unavailable'); }
    $db->exec('PRAGMA query_only = ON');
});
