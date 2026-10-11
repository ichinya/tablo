<?php
declare(strict_types=1);

namespace Tablo;

class HttpClient
{
    private ?int $deadline = null;

    public function __construct(private readonly bool $allowPrivate = false) {}

    public function getBefore(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers, int $deadline): array
    {
        $previous = $this->deadline;
        $this->deadline = $deadline;
        try { return $this->get($url, $headers); }
        finally { $this->deadline = $previous; }
    }

    private function remainingMs(): int
    {
        if ($this->deadline === null) { return 6000; }
        $remaining = (int) floor(($this->deadline - hrtime(true)) / 1000000);
        if ($remaining < 1) { throw new HttpFailure('timeout'); }
        return min(6000, $remaining);
    }

    public function get(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = []): array
    {
        try { return $this->request($url, $headers); }
        catch (\PDOException | SharedKeyFailure $error) { throw $error; }
        catch (\Throwable $error) {
            // Native frames/options/callback captures are discarded, including previous chains.
            // Only a genuine typed contract reason crosses this boundary.
            $reason = $error instanceof HttpFailure ? $error->reason : 'check-error';
            unset($error);
            throw new HttpFailure($reason);
        }
    }

    public function getEndpoint(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = [],
        int $maxRedirects = 3, ?int $deadline = null): array
    {
        try { return $this->endpoint($url, $headers, $maxRedirects, $deadline); }
        catch (\PDOException | SharedKeyFailure $error) { throw $error; }
        catch (\Throwable $error) {
            $reason = $error instanceof HttpFailure ? $error->reason : 'check-error';
            unset($error);
            throw new HttpFailure($reason);
        }
    }

    private function endpoint(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers,
        int $maxRedirects, ?int $deadline): array
    {
        if ($maxRedirects < 1 || $maxRedirects > 3) { throw new HttpFailure('check-error'); }
        // Endpoint permission exposes only accepted38's single Bearer header,
        // not arbitrary caller headers, cookies, Host or proxy overrides.
        if ($headers !== [] && (count($headers) !== 1 || !is_string($headers[0] ?? null)
            || !preg_match('/\AAuthorization: Bearer [\x21-\x7e]{1,512}\z/', $headers[0]))) {
            throw new HttpFailure('check-error');
        }
        $start = hrtime(true);
        $chain = ['deadline' => min($start + 6_000_000_000, $deadline ?? PHP_INT_MAX, $this->deadline ?? PHP_INT_MAX),
            'body_bytes' => 0, 'header_bytes' => 0, 'too_large' => false];
        $target = EndpointTarget::parse($url);
        $origin = $target['origin'];
        $visited = [];
        for ($transitions = 0; ; ++$transitions) {
            self::endpointRemaining($chain);
            $identity = hash('sha256', $target['url']);
            if (isset($visited[$identity])) { throw new HttpFailure('redirect-cycle'); }
            $visited[$identity] = true;
            $response = $this->request($target['url'], $headers, $chain);
            self::endpointRemaining($chain);
            if (!in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                unset($response['location'], $response['location_invalid']);
                $response['time_ms'] = (int) round((hrtime(true) - $start) / 1_000_000);
                return $response;
            }
            if ($response['location_invalid']) { throw new HttpFailure('redirect-location-invalid'); }
            if ($response['location'] === null || $response['location'] === '') { throw new HttpFailure('redirect-location-missing'); }
            $next = EndpointTarget::redirect($target, $response['location']);
            unset($response);
            if ($next['origin'] !== $origin) {
                $headers = []; // Detach the entire credential set before rejecting.
                throw new HttpFailure('redirect-origin');
            }
            if (isset($visited[hash('sha256', $next['url'])])) { throw new HttpFailure('redirect-cycle'); }
            if ($transitions >= $maxRedirects) { throw new HttpFailure('redirect-limit'); }
            $target = $next;
        }
    }

    private static function endpointRemaining(array $chain): int
    {
        $remaining = (int) floor(($chain['deadline'] - hrtime(true)) / 1_000_000);
        if ($remaining < 1) { throw new HttpFailure('timeout'); }
        return min(6000, $remaining);
    }

    private function request(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers,
        ?array &$chain = null): array
    {
        $this->remainingMs();
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new HttpFailure('invalid-url');
        }
        $host = trim($parts['host'], '[]');
        $resolve = [];
        if (!$this->allowPrivate || $chain !== null) {
            $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
            if (!$addresses) {
                throw new HttpFailure('dns');
            }
            foreach ($addresses as $address) {
                if (!$this->allowPrivate && ($chain !== null ? !EndpointTarget::isPublicAddress($address)
                    : (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                    || str_starts_with(strtolower($address), '::ffff:')))) {
                    throw new HttpFailure('ssrf');
                }
            }
            if (!filter_var($host, FILTER_VALIDATE_IP)) {
                $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
                $address = $addresses[0];
                $resolve[] = "$host:$port:" . (str_contains($address, ':') ? "[$address]" : $address);
            }
        }
        // Synchronous SSRF preflight DNS has an OS resolver bound, not a PHP deadline.
        // Re-check afterwards so an overrun never starts the subsequent cURL request.
        $remainingMs = $chain === null ? $this->remainingMs() : self::endpointRemaining($chain);
        $curl = null;
        $body = '';
        $tooLarge = false;
        $responseHeaders = [];
        $location = null;
        $locationInvalid = false;
        $inHeaders = false;
        try {
            $curl = curl_init($url);
            if ($curl === false) { throw new HttpFailure('check-error'); }
            $options = [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remainingMs),
                CURLOPT_TIMEOUT_MS => $remainingMs,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'Tablo/0.1',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RESOLVE => $resolve,
                CURLOPT_PROXY => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function (#[\SensitiveParameter] $curl, #[\SensitiveParameter] string $line)
                    use (&$responseHeaders, &$chain, &$location, &$locationInvalid, &$inHeaders): int {
                    $length = strlen($line);
                    if ($chain !== null) {
                        $chain['header_bytes'] += $length;
                        if ($chain['header_bytes'] > 65536 || $chain['header_bytes'] + $chain['body_bytes'] > 1048576) {
                            $chain['too_large'] = true;
                            return 0;
                        }
                    }
                    // Discard interim header blocks and never retain arbitrary headers or credentials.
                    if (str_starts_with($line, 'HTTP/')) {
                        $responseHeaders = [];
                        $location = null;
                        $locationInvalid = false;
                        $inHeaders = true;
                        return $length;
                    }
                    if ($line === "\r\n" || $line === "\n") { $inHeaders = false; return $length; }
                    if ($chain !== null && $inHeaders && ($line[0] === ' ' || $line[0] === "\t")) { $locationInvalid = true; }
                    $colon = strpos($line, ':');
                    if ($colon !== false) {
                        $name = strtolower(substr($line, 0, $colon));
                        if ($chain !== null && $inHeaders && $name === 'location') {
                            $value = trim(substr($line, $colon + 1), " \t\r\n");
                            if ($location !== null || strlen($value) > 8192 || preg_match('/[\x00-\x20\x7f]/', $value)) { $locationInvalid = true; }
                            $location = $value;
                        }
                        if (in_array($name, ['retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining',
                            'x-ratelimit-used', 'x-ratelimit-reset', 'x-ratelimit-resource'], true)) {
                            $value = trim(substr($line, $colon + 1));
                            $valid = $name === 'x-ratelimit-resource' ? in_array($value, ['core', 'search'], true)
                                : (bool) preg_match('/^[0-9]{1,18}$/D', $value);
                            // Duplicate/conflicting values fail closed, including identical duplicates.
                            $responseHeaders[$name] = !array_key_exists($name, $responseHeaders) && $valid ? $value : null;
                        }
                    }
                    return $length;
                },
                CURLOPT_WRITEFUNCTION => static function (#[\SensitiveParameter] $curl, #[\SensitiveParameter] string $chunk) use (&$body, &$tooLarge, &$chain): int {
                    if ($chain !== null) {
                        $chain['body_bytes'] += strlen($chunk);
                        if ($chain['header_bytes'] + $chain['body_bytes'] > 1048576) { $chain['too_large'] = true; return 0; }
                    }
                    if (strlen($body) + strlen($chunk) > 1048576) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                ];
            if (!curl_setopt_array($curl, $options)) { throw new HttpFailure('check-error'); }
            $ok = curl_exec($curl);
            if ($ok === false) {
                if ($tooLarge || ($chain['too_large'] ?? false)) { throw new HttpFailure('size'); }
                if ($chain !== null && $locationInvalid) { throw new HttpFailure('redirect-location-invalid'); }
                // libcurl can reject conflicting Location before delivering the
                // second header to HEADERFUNCTION (identical duplicates reach it).
                if ($chain !== null && curl_errno($curl) === CURLE_WEIRD_SERVER_REPLY
                    && curl_error($curl) === 'Multiple Location headers') { throw new HttpFailure('redirect-location-invalid'); }
                if (self::hasMalformedChunkFraming($curl)) { throw new HttpFailure('invalid-response'); }
                throw HttpFailure::fromCurl(curl_errno($curl), (int) curl_getinfo($curl, CURLINFO_OS_ERRNO));
            }
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status < 100 || $status > 599) { throw new HttpFailure('invalid-response'); }
            $response = ['status' => $status, 'body' => $body, 'headers' => $responseHeaders,
                'time_ms' => (int) round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000)];
            if ($chain !== null) { $response['location'] = $location; $response['location_invalid'] = $locationInvalid; }
            return $response;
        } finally {
            // The handle is released by PHP when it leaves scope.
            unset($options, $curl);
        }
    }

    private static function hasMalformedChunkFraming(#[\SensitiveParameter] \CurlHandle $curl): bool
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

    protected function resolve(#[\SensitiveParameter] string $host): array
    {
        $addresses = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            if (isset($record['ip']) || isset($record['ipv6'])) {
                $addresses[] = $record['ip'] ?? $record['ipv6'];
            }
        }
        return array_unique($addresses);
    }
}
