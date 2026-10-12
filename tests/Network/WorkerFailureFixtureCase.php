<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;

abstract class WorkerFailureFixtureCase
{
    protected const FAILURE = "Worker failed. Check installation configuration and local storage.\n";

    protected static function sites(\PDO $db, TemporaryDirectory $directory, string $base, string $health = '/up'): void
    {
        (new TokenVault($directory->path . '/github-token.key'))->encrypt(bin2hex(random_bytes(24)));
        $sites = new SiteRepository($db);
        for ($i = 0; $i < 2; ++$i) {
            $sites->save(array_replace(UnitFixtures::site(), ['url' => $base, 'health_path' => $health,
                'version_path' => '', 'repository' => 'fixture/project' . $i]));
        }
        $db->exec("UPDATE sites SET github_token = 'one-damaged-individual-ciphertext'");
    }

    protected static function requests(TemporaryDirectory $directory): array
    {
        $path = $directory->path . '/requests.jsonl';
        return is_file($path) ? array_map(static fn (string $line): string => \Tablo\SqlColumn::string(\Tablo\JsonRecord::checked(json_decode($line, associative: true, depth: 16, flags: JSON_THROW_ON_ERROR)), 'path'),
            \Tablo\Tests\Support\FixtureValue::fetched(file($path, FILE_IGNORE_NEW_LINES))) : [];
    }
}
