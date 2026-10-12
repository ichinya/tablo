<?php
declare(strict_types=1);

namespace Tablo;

final class InstallationException extends \RuntimeException
{
    public const UNSUPPORTED_CURL = 1;
    public const CURL_DIAGNOSTIC = 'Unsupported cURL platform: Tablo requires libcurl >= 7.55.0 with CURLOPT_REQUEST_TARGET. Run composer check-platform-reqs with the PHP runtime used by Tablo.';
}
