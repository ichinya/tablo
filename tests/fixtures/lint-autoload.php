<?php
declare(strict_types=1);

// Only loaded by the copied CLI in LintCommandTest's temporary project.
namespace Composer;

final class InstalledVersions
{
    public static function getPrettyVersion(string $package): string
    {
        if ($package !== 'carthage-software/mago') { throw new \RuntimeException('Unexpected package'); }
        return '1.51.2';
    }
}
