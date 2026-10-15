<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

final class CommandOptions
{
    public function __construct(public readonly float $timeout = 8, #[\SensitiveParameter] public readonly string $input = '', public readonly ?string $cwd = null, public readonly StderrCapture $stderr = StderrCapture::File) {}
}
