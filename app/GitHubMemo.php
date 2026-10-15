<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Branches from GitHubBranches
 * @phpstan-type MemoValue string|int|null|Branches
 */
final class GitHubMemo
{
    /** @var array<string, MemoValue> */
    private array $memo = [];
    private readonly string $salt;
    public function __construct(private readonly int $maxEntries) { $this->salt = random_bytes(32); }
    public function identity(#[\SensitiveParameter] string $token, string $revision = ''): string
    {
        return hash_hmac('sha256', $token . "\0" . $revision, $this->salt);
    }
    public function invalidate(string $identity): void
    {
        foreach (array_keys($this->memo) as $key) {
            if (!(str_starts_with($key, $identity . ':'))) { continue; }
 unset($this->memo[$key]);
        }
    }
    /**
     * @param \Closure(): MemoValue $load
     * @return MemoValue
     */
    public function remember(string $identity, string $metric, \Closure $load): mixed
    {
        $key = $identity . ':' . hash('sha256', $metric);
        if (array_key_exists($key, $this->memo)) { return $this->memo[$key]; }
        $value = $load(); // Only a validated semantic result; exceptions never enter the cache.
        if (count($this->memo) >= $this->maxEntries) { array_shift($this->memo); }
        $this->memo[$key] = $value;
        return $value;
    }

}
