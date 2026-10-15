<?php
declare(strict_types=1);

namespace Tablo;

/** Validated public deployment metadata, independently of its JSON layout. */
final class DeploymentFields
{
    public static function validated(#[\SensitiveParameter] mixed $version, #[\SensitiveParameter] mixed $commit): array
    {
        if ($version !== null && (!is_string($version) || $version === '' || strlen($version) > 200)) {
            throw new \RuntimeException('Некорректное поле version.');
        }
        if ($commit !== null && (!is_string($commit) || !preg_match('/^[a-f0-9]{7,64}$/iD', $commit))) {
            throw new \RuntimeException('Некорректное поле commit (нужен SHA, от 7 символов).');
        }
        if ($version === null && $commit === null) {
            throw new \RuntimeException('Нужны поля version и/или commit.');
        }
        return ['deployed_version' => $version, 'deployed_commit' => $commit === null ? null : strtolower($commit)];
    }
}
