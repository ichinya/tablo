<?php
declare(strict_types=1);
$frame=stream_get_contents(STDIN,2049);
if (is_string($frame) && strlen($frame)<=2048) { echo '{"code":"sent","http_status":204}'; }
