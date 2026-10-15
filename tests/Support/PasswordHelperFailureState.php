<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

/** Actual exceptions from the real assertion/retention boundary; absent remains null. */
final class PasswordHelperFailureState
{
    public ?\Throwable $primary = null;
    public ?\Throwable $returned = null;
}
