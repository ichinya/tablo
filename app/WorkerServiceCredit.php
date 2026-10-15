<?php
declare(strict_types=1);

namespace Tablo;

final class WorkerServiceCredit
{
    public function __construct(private readonly SiteCheckWriter $writes) {}
    public function apply(#[\SensitiveParameter] array $site,array $state): void
    {
                $service = $state['worker_service'] ?? null;
                if (!is_array($service) || array_diff(array_keys($service), WorkerStateRepository::FIELDS) !== []
                    || array_filter($service, static fn (mixed $value): bool => !is_bool($value)) !== []) {
                    throw new \InvalidArgumentException('Invalid worker service.');
                }
                $this->writes->creditCheck($site['id'], $site['config_revision'], $service);
    }
}
