<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

enum StderrCapture
{
    case File;
    case ClosedReader;
}
