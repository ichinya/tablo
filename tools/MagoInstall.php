<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;

/** @phpstan-import-type Pin from MagoPin */
final class MagoInstall
{
    /** @param list<string> $arguments */
    public static function run(string $root, array $arguments): void
    {
        $pin = MagoPin::selected($root);
        $source = self::archiveArgument($arguments);
        MagoCache::directory($root, $pin['directory']);
        if (file_exists($pin['binary_path'])) {
            MagoPin::verify($pin, $root);
            fwrite(STDOUT, data: "Verified cached Mago 1.54.0 archive, executable and version.\n");
            return;
        }
        self::provision($pin, $source);
        MagoPin::digest($pin['archive_path'], $pin['archive_sha256']);
        $staging = $pin['directory'] . '/extract-' . bin2hex(random_bytes(length: 8));
        if (!mkdir($staging, permissions: 0o700)) { throw new RuntimeException('Cannot reserve Mago extraction directory.'); }
        $result = MagoProcess::capture(['tar', '-xf', $pin['archive_path'], '-C', $staging, $pin['member']], $root);
        if ($result['exit'] !== 0) { throw new RuntimeException('Pinned Mago archive extraction failed.'); }
        $binary = $staging . '/' . $pin['member'];
        MagoPin::digest($binary, $pin['binary_sha256']);
        if (!copy($binary, $pin['binary_path']) || !chmod($pin['binary_path'], permissions: 0o700)) {
            throw new RuntimeException('Cannot install project Mago executable.');
        }
        MagoPin::verify($pin, $root);
        fwrite(STDOUT, data: "Installed verified Mago 1.54.0 in the project cache.\n");
    }

    /** @param list<string> $arguments */
    private static function archiveArgument(array $arguments): ?string
    {
        if ($arguments === []) { return null; }
        if (array_key_exists(0, $arguments) && count($arguments) === 1 && str_starts_with($arguments[0], '--archive=') && strlen($arguments[0]) > 10) {
            return substr($arguments[0], offset: 10);
        }
        throw new RuntimeException('Usage: php tools/install-mago.php [--archive=verified-local-archive]');
    }

    /** @param Pin $pin */
    private static function provision(array $pin, ?string $source): void
    {
        if (file_exists($pin['archive_path'])) { return; }
        if ($source === null) { MagoDownload::archive($pin); return; }
        MagoPin::digest($source, $pin['archive_sha256']);
        if (!copy($source, $pin['archive_path'])) { throw new RuntimeException('Cannot copy pinned Mago archive.'); }
    }
}
