<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Transport from ServerTransport */
final class ServerTls
{
    /**
     * @param Transport $transport
     * @return array{tls: string, ca: string, certificate: string, key: string}
     */
    public static function read(DatabaseEnvironment $environment, array $transport): array
    {
        $tls = $environment->read('TABLO_DB_TLS');
        if ($tls === false) { $tls = $transport['socket'] === '' ? 'verify' : 'local'; }
        if (!in_array($tls, ['verify', 'local'], strict: true)) { throw new DatabaseFailure('configuration'); }
        if ($tls === 'verify') {
            if ($transport['socket'] !== '') { throw new DatabaseFailure('configuration'); }
            return self::verified($environment);
        }
        if (!in_array($transport['host'], ['', '127.0.0.1', '::1'], strict: true)) { throw new DatabaseFailure('configuration'); }
        foreach (['CA', 'CERT', 'KEY'] as $field) {
            if ($environment->optional('TABLO_DB_TLS_' . $field) !== false) { throw new DatabaseFailure('configuration'); }
        }
        return ['tls' => 'local', 'ca' => '', 'certificate' => '', 'key' => ''];
    }
    /** @return array{tls: string, ca: string, certificate: string, key: string} */
    private static function verified(DatabaseEnvironment $environment): array
    {
        $ca = self::file($environment->optional('TABLO_DB_TLS_CA'));
        $certificate = $environment->optional('TABLO_DB_TLS_CERT'); $key = $environment->optional('TABLO_DB_TLS_KEY');
        if (($certificate === false) !== ($key === false)) { throw new DatabaseFailure('configuration'); }
        return ['tls' => 'verify', 'ca' => $ca, 'certificate' => $certificate === false ? '' : self::file($certificate), 'key' => $key === false ? '' : self::file($key)];
    }
    private static function file(#[\SensitiveParameter] string|false $value): string
    {
        if ($value === false || $value === '' || str_contains($value, "\0")) { throw new DatabaseFailure('configuration'); }
        return $value;
    }
}
