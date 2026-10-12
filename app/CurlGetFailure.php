<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Chain from EndpointChain */
final class CurlGetFailure
{
    /** @param Chain|null $chain */
    public static function fail(#[\SensitiveParameter] \CurlHandle $curl, HttpResponseBuffer $buffer, ?array $chain): never
    {
                if ($buffer->tooLarge || ($chain['too_large'] ?? false)) { throw new HttpFailure('size'); }
                if ($chain !== null && $buffer->headers->locationInvalid) { throw new HttpFailure('redirect-location-invalid'); }
                // libcurl can reject conflicting Location before delivering the
                // second header to HEADERFUNCTION (identical duplicates reach it).
                if ($chain !== null && curl_errno($curl) === CURLE_WEIRD_SERVER_REPLY
                    && curl_error($curl) === 'Multiple Location headers') { throw new HttpFailure('redirect-location-invalid'); }
                if (CurlFraming::isMalformed($curl)) { throw new HttpFailure('invalid-response'); }
                throw HttpFailure::fromCurl(curl_errno($curl), (int) curl_getinfo($curl, CURLINFO_OS_ERRNO));
    }

}
