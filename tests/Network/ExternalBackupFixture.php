<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\TemporaryDirectory;

final class ExternalBackupFixture
{
    public static function transfer(TemporaryDirectory $directory, string $path): void
    {
            $backup=new \PDO('sqlite:'.$path);
            $backup->exec("VACUUM INTO '".str_replace("'",replace: "''",subject: $directory->path.'/backup.sqlite')."'"); $backup=null;
            copy($directory->path.'/github-token.key',$directory->path.'/key-backup');
            rename($directory->path.'/github-token.key',$directory->path.'/external');
            putenv('TABLO_TOKEN_KEY_FILE='.$directory->path.'/external');

    }
}
