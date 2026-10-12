<?php
declare(strict_types=1);

namespace Tablo;

final class CurlFraming
{
    public static function isMalformed(#[\SensitiveParameter] \CurlHandle $curl): bool
    {
        // libcurl's HTTP chunk decoder also uses errno 56 for framing errors.
        // Match only its known decoder diagnostics (lib/http_chunks.c). Never
        // pass the raw buffer to an exception, stored result, or logger.
        return curl_errno($curl) === CURLE_RECV_ERROR && preg_match(
            '/\A(?:chunk hex-length char not a hex digit: 0x[0-9a-f]+|chunk hex-length longer than [0-9]+'
            . '|invalid chunk size: \'[0-9a-fA-F]+\'|(?:Illegal or missing hexadecimal sequence'
            . '|Too long hexadecimal number|Malformed encoding found) in chunked-encoding)\z/',
            curl_error($curl),
        ) === 1;
    }
}
