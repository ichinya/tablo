<?php
declare(strict_types=1);

namespace Tablo;

final class HttpResponseHeaders
{
    public array $rate = [];
    public ?string $location = null;
    public bool $locationInvalid = false;
    private bool $inHeaders = false;

    public function consume(#[\SensitiveParameter] string $line, ?array &$chain): int
    {
        $length = strlen($line);
        if (!HttpSizeBudget::header($chain, $length)) { return 0; }
        if (str_starts_with($line, 'HTTP/')) {
            $this->rate = []; $this->location = null; $this->locationInvalid = false; $this->inHeaders = true;
            return $length;
        }
        if ($line === "\r\n" || $line === "\n") { $this->inHeaders = false; return $length; }
        if ($chain !== null && $this->inHeaders && ($line[0] === ' ' || $line[0] === "\t")) { $this->locationInvalid = true; }
        $colon = strpos($line, needle: ':');
        if ($colon === false) { return $length; }
        $name = strtolower(substr($line, offset: 0, length: $colon));
        if ($chain !== null && $this->inHeaders && $name === 'location') { HttpLocationHeader::consume($line, $colon, $this); }
        GitResponseHeaders::consume($name, $line, $colon, $this->rate);
        return $length;
    }

}
