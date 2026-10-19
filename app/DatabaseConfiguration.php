<?php
declare(strict_types=1);

namespace Tablo;

/**
 * Validated components only; TABLO_DB is never a server DSN.
 * @phpstan-type Values array{catalog: string, host: string, port: ?int, socket: string, user: string, password: string, tls: string, ca: string, certificate: string, key: string}
 */
final class DatabaseConfiguration
{
    public readonly string $catalog;
    public readonly string $host;
    public readonly ?int $port;
    public readonly string $socket;
    public readonly string $user;
    public readonly string $password;
    public readonly string $tls;
    public readonly string $ca;
    public readonly string $certificate;
    public readonly string $key;

    /** @param Values $values */
    private function __construct(public readonly string $engine, public readonly string $role, #[\SensitiveParameter] array $values)
    {
        $this->catalog = $values['catalog']; $this->host = $values['host']; $this->port = $values['port']; $this->socket = $values['socket'];
        $this->user = $values['user']; $this->password = $values['password']; $this->tls = $values['tls'];
        $this->ca = $values['ca']; $this->certificate = $values['certificate']; $this->key = $values['key'];
    }

    /** @param array<string, string>|null $environment */
    public static function fromEnvironment(string $role = 'writer', #[\SensitiveParameter] ?array $environment = null): self
    {
        if (!in_array($role, ['writer', 'reader', 'migrator', 'control'], strict: true)) { throw new DatabaseFailure('configuration'); }
        $source = new DatabaseEnvironment($environment);
        $engine = $source->engine();
        if ($engine === 'sqlite') {
            return new self($engine, $role, ['catalog' => '', 'host' => '', 'port' => null, 'socket' => '', 'user' => '', 'password' => '', 'tls' => 'local', 'ca' => '', 'certificate' => '', 'key' => '']);
        }
        $transport = ServerTransport::read($source);
        return new self($engine, $role, ['catalog' => $source->catalog()] + $transport + ServerCredentials::read($source, $role) + ServerTls::read($source, $transport));
    }

    public function server(): bool { return $this->engine !== 'sqlite'; }

    public function dsn(): string
    {
        if (!$this->server()) { throw new DatabaseFailure('configuration'); }
        $suffix = ';dbname=' . $this->catalog . ';charset=utf8mb4';
        if ($this->socket !== '') { return 'mysql:unix_socket=' . $this->socket . $suffix; }
        $port = $this->port ?? throw new DatabaseFailure('configuration');
        return 'mysql:host=' . $this->host . ';port=' . $port . $suffix;
    }
}
