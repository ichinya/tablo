<?php
declare(strict_types=1);
namespace Tablo;
enum KeyCreation
{
    case Allowed;
    case Forbidden;
    public static function forDatabase(\PDO $db): self { return SiteKeyState::hasEstablishedKeyState($db) ? self::Forbidden : self::Allowed; }
}
