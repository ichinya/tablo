<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;

final class HealthCheckDiagnostics
{
    public static function capture(array $result, PDO $database): string
    {
        // Capture before the exit assertion. Never include credentials, last_error,
        // exception arguments or arbitrary child messages in the failure report.
        preg_match_all('/^\d+: (?:ok|attention)$/m', $result['stdout'], $stdout);
        preg_match_all('/PHP (?:Fatal error|Warning|Notice):|Uncaught (?:RuntimeException|PDOException|TypeError|Error)\b| on line \d+/', $result['stderr'], $stderr);
        return json_encode([
            'exit_code' => $result['exit_code'], 'stdout_status' => $stdout[0],
            'stderr' => ['safe_markers' => $stderr[0], 'bytes' => strlen($result['stderr']),
                'sha256' => hash('sha256', $result['stderr'])],
            'state' => $database->query('SELECT id,online,health_error_code,health_http_status,checked_at,config_revision FROM sites ORDER BY id')->fetchAll(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
