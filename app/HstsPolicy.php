<?php
declare(strict_types=1);

namespace Tablo;

// Pure definitions only: this owner is usable before Composer and Dotenv.
require_once __DIR__ . '/HstsConfigurationError.php';
require_once __DIR__ . '/ClientAddress.php';
require_once __DIR__ . '/IpAddress.php';
require_once __DIR__ . '/ProxyConfiguration.php';

final readonly class HstsPolicy
{
    private string $mode;
    private ?string $value;
    /** @var list<string> */
    private array $proxies;

    public function __construct(
        #[\SensitiveParameter] string|false $mode = false,
        #[\SensitiveParameter] string|false $age = false,
        #[\SensitiveParameter] string|false $trust = false,
    ) {
        $this->mode = in_array($mode, [false, ''], strict: true) ? 'off' : $mode;
        if (!in_array($this->mode, ['off', 'direct', 'proxy'], strict: true)) {
            throw new HstsConfigurationError('TABLO_HSTS_MODE');
        }
        $this->value = hsts_age($age, $this->mode);
        $this->proxies = $this->mode === 'proxy' ? hsts_proxies($trust) : [];
    }

    /** Take exactly one local-only process snapshot, before any Composer work. */
    public static function fromEnvironment(): self
    {
        return new self(\getenv('TABLO_HSTS_MODE', local_only: true),
            \getenv('TABLO_HSTS_MAX_AGE', local_only: true), \getenv('TABLO_TRUSTED_PROXIES', local_only: true));
    }

    public function headerValue(#[\SensitiveParameter] array $server): ?string
    {
        if ($this->mode === 'off') { return null; }
        if ($this->mode === 'direct') {
            return in_array($server['HTTPS'] ?? null, ['on', '1'], strict: true) ? $this->value : null;
        }
        $peer = IpAddress::canonical($server['REMOTE_ADDR'] ?? null);
        if (!in_array($peer, $this->proxies, strict: true) || !is_string($server['HTTP_X_FORWARDED_PROTO'] ?? null)) {
            return null;
        }
        $proto = $server['HTTP_X_FORWARDED_PROTO'];
        if (strlen($proto) > 64) { return null; }
        return strtolower(trim($proto, characters: " \t")) === 'https' ? $this->value : null;
    }

}

function hsts_age(#[\SensitiveParameter] string|false $age, string $mode): ?string
{
    if (in_array($age, [false, ''], strict: true)) {
        if ($mode !== 'off') { throw new HstsConfigurationError('TABLO_HSTS_MAX_AGE'); }
        return null;
    }
    if (strlen($age) > 8 || preg_match('/^(0|[1-9][0-9]{0,7})$/D', $age) !== 1 || (int) $age > 31_536_000) {
        throw new HstsConfigurationError('TABLO_HSTS_MAX_AGE');
    }
    return 'max-age=' . $age;
}

/** @return non-empty-list<string> */
function hsts_proxies(#[\SensitiveParameter] string|false $trust): array
{
    try { $proxies = ProxyConfiguration::parse($trust); }
    catch (\Throwable) { throw new HstsConfigurationError('TABLO_TRUSTED_PROXIES'); }
    if ($proxies === []) { throw new HstsConfigurationError('TABLO_TRUSTED_PROXIES'); }
    return $proxies;
}
