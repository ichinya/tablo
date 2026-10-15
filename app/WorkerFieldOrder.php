<?php
declare(strict_types=1);

namespace Tablo;

final class WorkerFieldOrder
{
    public function __construct(private readonly WorkerStateRepository $state) {}
    public function fieldOrder(#[\SensitiveParameter] array $site, GitHubProvider $provider): array
    {
        $turns = $this->state->turns($site['id'], $site['config_revision']);
        $eligible = $provider->eligibleResources();
        $order = WorkerStateRepository::FIELDS;
        $key = static fn (string $field): array => [
            (int) !$eligible[in_array($field, ['open_issues', 'open_prs'], strict: true) ? 'search' : 'core'],
            $turns[$field], array_search($field, WorkerStateRepository::FIELDS, strict: true)];
        usort($order, static fn (string $left, string $right): int => $key($left) <=> $key($right));
        return $order;
    }
}
