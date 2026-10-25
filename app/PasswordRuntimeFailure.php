<?php
declare(strict_types=1);

namespace Tablo;

final class PasswordRuntimeFailure extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Password runtime unavailable.');
    }
}
