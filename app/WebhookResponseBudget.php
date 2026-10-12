<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookResponseBudget
{
    public bool $large = false;
    private int $bodyBytes = 0;
    private int $headerBytes = 0;
    public function header(#[\SensitiveParameter] string $line): int
    {
        $this->headerBytes += strlen($line);
        if ($this->headerBytes > 16_384) { $this->large = true; return 0; }
        return strlen($line);
    }
    public function write(#[\SensitiveParameter] string $chunk): int
    {
        $this->bodyBytes += strlen($chunk);
        if ($this->bodyBytes > 4096) { $this->large = true; return 0; }
        return strlen($chunk);
    }

}
