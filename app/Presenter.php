<?php
declare(strict_types=1);

namespace Tablo;

final class Presenter
{

    public static function site(#[\SensitiveParameter] array $site): array
    {
        $site = SiteProjection::publicSite($site);
        $host = parse_url($site['url'], PHP_URL_HOST);
        $site['host'] = $host ? $host : $site['url'];
        $site['initial'] = mb_strtoupper(mb_substr($site['name'], start: 0, length: 1));
        HealthPresentation::apply($site);
        VersionPresentation::apply($site);
        FreshnessPresentation::apply($site);
        return $site;
    }
}
