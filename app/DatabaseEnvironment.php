<?php
declare(strict_types=1);

namespace Tablo;

final class DatabaseEnvironment
{
    private const FIELDS = ['NAME', 'HOST', 'PORT', 'SOCKET', 'USER', 'PASSWORD', 'READ_USER', 'READ_PASSWORD',
        'MIGRATE_USER', 'MIGRATE_PASSWORD', 'TLS', 'TLS_CA', 'TLS_CERT', 'TLS_KEY'];
    /** @param array<string, string>|null $values */
    public function __construct(#[\SensitiveParameter] private readonly ?array $values) {}
    public function read(string $name): string|false { return $this->values === null ? getenv($name) : ($this->values[$name] ?? false); }
    public function optional(string $name): string|false { $value = $this->read($name); return $value === '' ? false : $value; }
    public function engine(): string
    {
        $engine = $this->read('TABLO_DB_DRIVER');
        if ($engine === false) { $engine = 'sqlite'; }
        if (!in_array($engine, ['sqlite', 'mysql', 'mariadb'], strict: true)) { throw new DatabaseFailure('configuration'); }
        if ($engine === 'sqlite') {
            foreach (self::FIELDS as $field) {
                if (!in_array($this->read('TABLO_DB_' . $field), [false, ''], strict: true)) { throw new DatabaseFailure('configuration'); }
            }
            return $engine;
        }
        if (!DatabaseInteger::platform64() || !in_array($this->read('TABLO_DB'), [false, ''], strict: true)) { throw new DatabaseFailure('configuration'); }
        return $engine;
    }
    public function catalog(): string
    {
        $catalog = $this->read('TABLO_DB_NAME');
        if (!is_string($catalog) || preg_match('/\A[a-zA-Z0-9_]{1,64}\z/D', $catalog) !== 1) { throw new DatabaseFailure('configuration'); }
        return $catalog;
    }
}
