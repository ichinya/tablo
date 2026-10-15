<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-type Input array{name: string, url: string, enabled: int, online: ?int, checked_at: ?string, deployed_version: ?string, latest_release: ?string, deployed_commit: ?string, latest_commit: ?string, comparison_mode: string, version_path?: string, last_error: ?string, health_error_code?: ?string, health_http_status?: ?int, ...}
 * @phpstan-type Header array{name: string, url: string, enabled: int, online: ?int, checked_at: ?string, deployed_version: ?string, latest_release: ?string, deployed_commit: ?string, latest_commit: ?string, comparison_mode: string, version_path?: string, last_error: ?string, health_error_code?: ?string, health_http_status?: ?int, host: string, initial: string, ...}
 * @phpstan-type Health array{name: string, url: string, enabled: int, online: ?int, checked_at: ?string, deployed_version: ?string, latest_release: ?string, deployed_commit: ?string, latest_commit: ?string, comparison_mode: string, version_path?: string, last_error: ?string, health_error_code?: ?string, health_http_status?: ?int, host: string, initial: string, tone: string, status: string, health_reason_label: string, ...}
 * @phpstan-type Version array{name: string, url: string, enabled: int, online: ?int, checked_at: ?string, deployed_version: ?string, latest_release: ?string, deployed_commit: ?string, latest_commit: ?string, comparison_mode: string, version_path?: string, last_error: ?string, health_error_code?: ?string, health_http_status?: ?int, host: string, initial: string, tone: string, status: string, health_reason_label: string, comparison: string, comparison_tone: string, deployed_label: string, latest_label: string, ...}
 * @phpstan-type View array{name: string, url: string, enabled: int, online: ?int, checked_at: ?string, deployed_version: ?string, latest_release: ?string, deployed_commit: ?string, latest_commit: ?string, comparison_mode: string, version_path?: string, last_error: ?string, health_error_code?: ?string, health_http_status?: ?int, host: string, initial: string, tone: string, status: string, health_reason_label: string, comparison: string, comparison_tone: string, deployed_label: string, latest_label: string, checked_label: string, stale: bool, tracks_version: bool, attention: bool, ...}
 */
final class Presenter
{

    /**
     * @param Input $site
     * @return View
     */
    public static function site(#[\SensitiveParameter] array $site): array
    {
        // This projection removes credential keys and preserves every presentation input field.
        /** @var Input $site */
        $site = SiteProjection::publicSite($site);
        // This fixed parse_url component returns string, false or null, never a port integer.
        /** @var string|false|null $host */
        $host = parse_url($site['url'], PHP_URL_HOST);
        $site['host'] = $host ? $host : $site['url'];
        $site['initial'] = mb_strtoupper(mb_substr($site['name'], start: 0, length: 1));
        $site = HealthPresentation::apply($site);
        $site = VersionPresentation::apply($site);
        return FreshnessPresentation::apply($site);
    }
}
