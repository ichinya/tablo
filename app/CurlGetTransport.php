<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-type TransportResponse array{status: int, body: string, headers: array<string, ?string>, time_ms: int, location?: ?string, location_invalid?: bool}
 * @phpstan-import-type Chain from EndpointChain
 */
final class CurlGetTransport
{
    /** @param \Closure(string): list<string> $resolver */
    public function __construct(private readonly bool $allowPrivate, #[\SensitiveParameter] private readonly \Closure $resolver, private readonly ?int $deadline) {}
    /**
     * @param list<string> $headers
     * @param Chain|null $chain
     * @return TransportResponse
     */
    public function request(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers, ?array &$chain = null): array
    {
        HttpDeadline::remaining($this->deadline);
        $parts = GetTarget::parts($url);
        $resolve = (new GetAddressAdmission($this->allowPrivate, $this->resolver))->pin($parts, $chain);
        $remainingMs = HttpDeadline::remaining($chain['deadline'] ?? $this->deadline);
        $curl = null;
        $buffer = new HttpResponseBuffer($chain);
        try {
            $curl = curl_init($url);
            if ($curl === false) { throw new HttpFailure('check-error'); }
            $options = CurlGetOptions::build($headers, $resolve, $remainingMs, $buffer, $chain === null ? null : $parts);
            if (!curl_setopt_array($curl, $options)) { throw new HttpFailure('check-error'); }
            if (curl_exec($curl) === false) { CurlGetFailure::fail($curl, $buffer, $chain); }
            $status = HttpScalar::integer(curl_getinfo($curl, CURLINFO_RESPONSE_CODE));
            if ($status < 100 || $status > 599) { throw new HttpFailure('invalid-response'); }
            $time = HttpScalar::floating(curl_getinfo($curl, CURLINFO_TOTAL_TIME));
            $response = ['status' => $status, 'body' => $buffer->body, 'headers' => $buffer->headers->rate,
                'time_ms' => (int) round($time * 1000)];
            if ($chain !== null) { $response['location'] = $buffer->headers->location; $response['location_invalid'] = $buffer->headers->locationInvalid; }
            return $response;
        } finally { unset($options, $curl); }
    }

}
