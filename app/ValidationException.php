<?php
declare(strict_types=1);

namespace Tablo;

final class ValidationException extends \RuntimeException
{
    public static function field(string $field, string $message): self
    {
        return new self(self::errors($field, $message));
    }

    public static function errors(string $field, string $message): array
    {
        return [$field => $message];
    }

    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
