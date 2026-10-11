<?php
declare(strict_types=1);

namespace Tablo;

// Installation custody failure, distinct from one damaged encrypted credential.
final class SharedKeyFailure extends \RuntimeException
{
    public const UNVERIFIED = 13;
    public const UNVERIFIED_DIAGNOSTIC = 'External token key is unverified: no stored credential authenticated. Restore the matching key or repair credentials offline.';
}
