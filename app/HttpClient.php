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



    public function get(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = []): array
    {
        try { return (new CurlGetTransport($this->allowPrivate, $this->resolve(...), $this->deadline))->request($url, $headers); }
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
        try {
            $transport = new CurlGetTransport($this->allowPrivate, $this->resolve(...), $this->deadline);
            return EndpointChain::run($url, $headers, $maxRedirects, min($deadline ?? PHP_INT_MAX, $this->deadline ?? PHP_INT_MAX), $transport->request(...));
        }
        catch (\PDOException | SharedKeyFailure $error) { throw $error; }
        catch (\Throwable $error) {
            $reason = $error instanceof HttpFailure ? $error->reason : 'check-error';
            unset($error);
            throw new HttpFailure($reason);
        }
    }







    public function postBefore(#[\SensitiveParameter] string $url, #[\SensitiveParameter] string $payload,
        string $eventId, #[\SensitiveParameter] string $bearer, int $deadline): int
    {
        set_error_handler(static function (): never { throw new WebhookFailure('network'); });
        try { return (new CurlWebhookTransport($this->webhookAddresses(...), $this->configureWebhook(...)))->request($url,$payload,$eventId,$bearer,$deadline); }
        catch (WebhookFailure $error) { throw new WebhookFailure($error->reason); }
        catch (\Throwable) { throw new WebhookFailure('network'); }
        finally { restore_error_handler(); }
    }


    protected function configureWebhook(#[\SensitiveParameter] \CurlHandle $curl): void {}
    protected function resolve(#[\SensitiveParameter] string $host): array { return PublicDns::addresses($host); }
    protected function webhookAddresses(string $host): array { return WebhookAddressAdmission::addresses($host, $this->resolve(...)); }
}
