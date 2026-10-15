<?php
declare(strict_types=1);

namespace Tablo;

final class WorkerServiceCredit
{
    public function __construct(private readonly SiteCheckWriter $writes) {}
    /** @param array{id: int, config_revision: int, ...} $site */
    public function apply(#[\SensitiveParameter] array $site,array $state): void
    {
                $service = self::service($state['worker_service'] ?? null);
                if (array_diff(array_keys($service), WorkerStateRepository::FIELDS) !== []
                    || array_filter($service, static fn (mixed $value): bool => !is_bool($value)) !== []) {
                    throw new \InvalidArgumentException('Invalid worker service.');
                }
                $this->writes->creditCheck($site['id'], $site['config_revision'], $service);
    }

    private static function service(mixed $value): array
    {
        if (!is_array($value)) { throw new \InvalidArgumentException('Invalid worker service.'); }
        return $value;
    }
}
