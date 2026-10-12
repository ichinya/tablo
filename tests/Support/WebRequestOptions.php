<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

final class WebRequestOptions
{
    public function __construct(public readonly CookiePolicy $cookies = CookiePolicy::Retained, public readonly array $headers = [], public readonly ?string $source = null, public readonly ?string $base = null, public readonly string $jar = 'cookies') {}
}
