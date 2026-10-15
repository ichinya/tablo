<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;
enum WalHolderPolicy
{
    case ReleaseBeforeRetry;
    case HoldThroughTimeout;
}
