<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\TokenVault;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;

final class ExternalRestoreAssertions
{
    public function __construct(private readonly TemporaryDirectory $directory) {}

    public function restored(array $before, #[\SensitiveParameter] string $cipher, #[\SensitiveParameter] string $secret): void
    {
            mkdir($this->directory->path.'/restore'); copy($this->directory->path.'/backup.sqlite',$this->directory->path.'/restore/db.sqlite');
            copy($this->directory->path.'/key-backup',$this->directory->path.'/restore/key');
            putenv('TABLO_TOKEN_KEY_FILE='.$this->directory->path.'/restore/key');
            $db=Database::connect($this->directory->path.'/restore/db.sqlite');
            Assert::same($db->query('SELECT * FROM github_cooldowns ORDER BY scope,resource')->fetchAll(),$before);
            Assert::same(TokenVault::forDatabase($db)->decrypt($cipher),$secret); $db=null;
            // Compatible schema4 rollback uses the original bytes and predecessor policy.
            copy($this->directory->path.'/key-backup',$this->directory->path.'/restore/github-token.key');
            putenv('TABLO_TOKEN_KEY_FILE='); $db=Database::connect($this->directory->path.'/restore/db.sqlite');
            Assert::same(TokenVault::forDatabase($db)->decrypt($cipher),$secret);

    }
}
