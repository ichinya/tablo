<?php
declare(strict_types=1);

// No scheme or forwarding override: the native SAPI supplies all request inputs.
$source = getenv('TABLO_HSTS_SOURCE', local_only: true);
if (!$source) { throw new RuntimeException('Missing owned HSTS source.'); }
require $source . '/public/index.php';
