<?php
declare(strict_types=1);
namespace Tablo;
enum KeyRequirement
{
    case Available;
    case Existing;
    public static function forDatabase(\PDO $db): self { return SiteKeyState::hasEstablishedKeyState($db) ? self::Existing : self::Available; }
}
