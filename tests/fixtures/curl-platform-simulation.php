<?php
declare(strict_types=1);

namespace Tablo\Tests\CurlPlatformSimulation;

// Namespace shims simulate capability combinations; they do not change linked libcurl.
final class Capabilities
{
    public static bool $available = true;
    public static bool $option = true;
    public static ?int $version = 0x073700;
}
function function_exists(string $name): bool { return $name === 'curl_version' ? Capabilities::$available : \function_exists($name); }
function defined(string $name): bool { return $name === 'CURLOPT_REQUEST_TARGET' ? Capabilities::$option : \defined($name); }
function curl_version(): array { return ['version_number' => Capabilities::$version]; }
