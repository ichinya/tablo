<?php
declare(strict_types=1);

namespace Tablo;

/** Configuration diagnostics contain a setting name, never its value or cause. */
final class HstsConfigurationError extends \RuntimeException
{
    public function __construct(string $setting)
    {
        parent::__construct('Invalid ' . $setting . ' configuration.');
    }
}
