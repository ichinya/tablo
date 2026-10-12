<?php
declare(strict_types=1);

namespace Tablo;

final class HttpResponseBuffer
{
    public string $body = '';
    public bool $tooLarge = false;
    public readonly HttpResponseHeaders $headers;
    private ?array $chain;

    public function __construct(?array &$chain)
    {
        $this->chain =& $chain;
        $this->headers = new HttpResponseHeaders();
    }
    public function header(#[\SensitiveParameter] string $line): int { return $this->headers->consume($line, $this->chain); }
    public function write(#[\SensitiveParameter] string $chunk): int
    {
        if (!HttpSizeBudget::body($this->chain, strlen($chunk))) { return 0; }
        if (strlen($this->body) + strlen($chunk) > 1_048_576) { $this->tooLarge = true; return 0; }
        $this->body .= $chunk;
        return strlen($chunk);
    }

}
