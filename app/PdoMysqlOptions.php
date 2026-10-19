<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class PdoMysqlOptions
{
    /** @return array<int, int|string|bool> */
    public static function forConfiguration(#[\SensitiveParameter] DatabaseConfiguration $configuration): array
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), strict: true)) { throw new DatabaseFailure('driver'); }
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_PERSISTENT => false,
            self::attribute('FOUND_ROWS') => true, self::attribute('MULTI_STATEMENTS') => false,
            self::attribute('LOCAL_INFILE') => false, PDO::ATTR_TIMEOUT => 5];
        if ($configuration->tls === 'verify') {
            $options[self::attribute('SSL_CA')] = $configuration->ca;
            $options[self::attribute('SSL_VERIFY_SERVER_CERT')] = true;
            if ($configuration->certificate !== '') {
                $options[self::attribute('SSL_CERT')] = $configuration->certificate;
                $options[self::attribute('SSL_KEY')] = $configuration->key;
            }
        }
        return $options;
    }

    private static function attribute(string $name): int
    {
        return SqlColumn::integer(['option' => constant((PHP_VERSION_ID >= 80_400 ? 'Pdo\\Mysql::ATTR_' : 'PDO::MYSQL_ATTR_') . $name)], 'option');
    }

    /** A transfer owns its dedicated session; credential scans must not buffer all ciphertexts. */
    public static function stream(PDO $db): void
    {
        if (!$db->setAttribute(self::attribute('USE_BUFFERED_QUERY'), false)) { throw new DatabaseFailure('engine'); }
    }
}
