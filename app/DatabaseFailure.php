<?php
declare(strict_types=1);

namespace Tablo;

/** Fresh failures retain a fixed category and validated codes, without native exception, DSN or diagnostic text. */
final class DatabaseFailure extends \RuntimeException
{
    public readonly ?string $sqlState;
    public readonly ?int $nativeCode;
    public readonly string $nativeReason;
    private const REASONS = ['connection-refused', 'name-resolution', 'certificate-verification', 'ssl-handshake', 'unknown'];

    public function __construct(public readonly string $category, #[\SensitiveParameter] ?string $sqlState = null, ?int $nativeCode = null, #[\SensitiveParameter] string $nativeReason = 'unknown')
    {
        $codes = self::codes($sqlState, $nativeCode);
        $this->sqlState = $codes['sqlState']; $this->nativeCode = $codes['nativeCode'];
        $this->nativeReason = in_array($nativeReason, self::REASONS, strict: true) ? $nativeReason : 'unknown';
        parent::__construct(match ($category) {
            'configuration' => 'Database configuration invalid.',
            'driver' => 'Selected PDO driver unavailable.',
            'engine' => 'Unsupported database engine.',
            'schema' => 'Database schema incompatible or in progress.',
            'key' => 'Installation key unavailable.',
            'busy' => 'Database busy.',
            'uncertain' => 'Storage outcome uncertain.',
            'constraint' => 'Database constraint refused.',
            default => 'Database unavailable.',
        });
    }

    public static function fromNative(#[\SensitiveParameter] \PDOException $error): self
    {
        /** @var mixed $native Only an actual integer is retained, never a string conversion or zero fallback. */
        $native = $error->errorInfo[1] ?? null;
        /** @var mixed $state Only an actual validated SQLSTATE string is retained. */
        $state = $error->errorInfo[0] ?? null;
        return new self(match (true) {
            in_array($native, [1205, 1213], strict: true) || $state === '40001' => 'busy',
            in_array($native, [1062, 1451, 1452, 3819, 4025], strict: true) => 'constraint',
            default => 'unavailable',
        }, sqlState: is_string($state) ? $state : null, nativeCode: is_int($native) ? $native : null, nativeReason: database_failure_native_reason($error));
    }

    /** @return array{sqlState: ?string, nativeCode: ?int} */
    private static function codes(#[\SensitiveParameter] ?string $state, ?int $native): array
    {
        return ['sqlState' => $state !== null && preg_match('/\A[0-9A-Z]{5}\z/D', $state) === 1 ? $state : null,
            'nativeCode' => $native !== null && $native >= 0 && $native <= 65_535 ? $native : null];
    }

    /** Preserve SQLite's existing error contract and remove server diagnostics at the boundary. */
    public static function rethrow(\PDO $db, #[\SensitiveParameter] \Throwable $error): never
    {
        if (DatabaseSession::server($db) && $error instanceof \PDOException) { throw self::fromNative($error); }
        throw $error;
    }
}

/** Classify only the same native driver's bounded diagnostic; no exception/message is retained. */
function database_failure_native_reason(#[\SensitiveParameter] \PDOException $error): string
{
    if (($error->errorInfo[0] ?? null) !== 'HY000' || !in_array($error->errorInfo[1] ?? null, [2002, 2026], strict: true)
        || !is_string($error->errorInfo[2] ?? null)) { return 'unknown'; }
    return database_failure_native_reason_text($error->errorInfo[2]);
}

function database_failure_native_reason_text(#[\SensitiveParameter] string $message): string
{
    if (strlen($message) > 4_096) { return 'unknown'; }
    $message = rtrim($message, characters: "\r\n");
    if (preg_match('~\Aphp_network_getaddresses: getaddrinfo for [^\r\n]{1,253} failed: (?:Name or service not known|No such host is known\.?)\z~D', $message) === 1) {
        return 'name-resolution';
    }
    return match ($message) {
        'Connection refused', 'No connection could be made because the target machine actively refused it',
        'No connection could be made because the target machine actively refused it.' => 'connection-refused',
        'SSL connection error: certificate verify failed' => 'certificate-verification',
        'Cannot connect to MySQL using SSL', 'Cannot connect to MySQL by using SSL' => 'ssl-handshake',
        default => 'unknown',
    };
}
