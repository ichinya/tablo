<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

enum CookiePolicy
{
    case Retained;
    case Omitted;
}
