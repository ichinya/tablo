<?php
declare(strict_types=1);

final class PasswordOutputFailure extends php_user_filter
{
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            if (str_contains($bucket->data, 'Administrator password changed.')) {
                throw new RuntimeException('Injected output transport failure after COMMIT');
            }
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
stream_filter_register('password-output-failure', PasswordOutputFailure::class);
stream_filter_append(STDOUT, 'password-output-failure');
require dirname(__DIR__, 2) . '/bin/admin-password.php';
