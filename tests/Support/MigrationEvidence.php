<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;
use RuntimeException;

final class MigrationEvidence
{
    public static function retain(MigrationChild $state): void
    {
        // Retain original child evidence outside the disposable scenario directory, including failed runs.
        $archive = sys_get_temp_dir() . '/tablo-database-process-evidence';
        if (!is_dir($archive) && !mkdir($archive, permissions: 0o700, recursive: true)) { throw new RuntimeException('Cannot retain database process evidence'); }
        $record = $archive . '/' . bin2hex(random_bytes(8));
        if (!copy($state->stdout, $record . '.out') || !copy($state->stderr, $record . '.err')
            || file_put_contents($record . '.json', json_encode($state->receipt, JSON_THROW_ON_ERROR) . "\n") === false) {
            throw new RuntimeException('Cannot retain database process streams');
        }
        if (is_file($state->directory->path . '/' . $state->name . '.events')) {
            copy($state->directory->path . '/' . $state->name . '.events', $record . '.events');
        }
    }
}
