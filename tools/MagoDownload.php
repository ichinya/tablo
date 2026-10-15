<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;

final class MagoDownload
{
    public static function archive(array $pin): void
    {
        $file = fopen($pin['archive_path'], mode: 'x+b');
        if ($file === false) { throw new RuntimeException('Cannot reserve Mago archive.'); }
        $curl = curl_init($pin['url']);
        try {
            if ($curl === false) { throw new RuntimeException('Cannot initialize pinned Mago download.'); }
            curl_setopt_array($curl, [CURLOPT_FILE => $file, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45, CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FAILONERROR => true, CURLOPT_PROXY => '']);
            if (curl_exec($curl) === false || curl_getinfo($curl, option: CURLINFO_RESPONSE_CODE) !== 200) {
                throw new RuntimeException('Pinned Mago archive download failed.');
            }
            if (!fflush($file)) { throw new RuntimeException('Cannot flush Mago archive.'); }
        } finally {
            if ($curl !== false) { curl_close($curl); }
            fclose($file);
        }
        MagoPin::digest($pin['archive_path'], $pin['archive_sha256']);
    }
}
