<?php
declare(strict_types=1);

namespace Tablo;

/** @return list<array> */
function read_api_rows(\PDO $db, string $afterId, int $limit): array
{
    ReadApiRequest::decimal($afterId);
    if ($limit < 1 || $limit > 100) { throw new \InvalidArgumentException('Invalid request.'); }
    $table = ServerDialect::choose($db, 'main.sites', 'sites');
    $statement = SqlStatement::checked($db->prepare('SELECT ' . ReadApiRepository::projectionFor($db) . ' FROM ' . $table . ' WHERE ' . $table . '.id > :after_id ORDER BY ' . $table . '.id ASC LIMIT :limit_plus_one'));
    try {
        $statement->bindValue(':after_id', (int) $afterId, \PDO::PARAM_INT);
        $statement->bindValue(':limit_plus_one', $limit + 1, \PDO::PARAM_INT);
        $statement->execute();
        return SqlRows::all($statement->fetchAll(\PDO::FETCH_ASSOC), static fn (array $row): array => $row);
    } finally { $statement->closeCursor(); }
}

function read_api_row(\PDO $db, string $id): ?array
{
    ReadApiRequest::decimal($id, minimum: '1');
    $statement = SqlStatement::checked($db->prepare('SELECT ' . ReadApiRepository::projectionFor($db) . ' FROM ' . ServerDialect::choose($db, 'main.sites', 'sites') . ' WHERE id = :id LIMIT 1'));
    try {
        $statement->bindValue(':id', (int) $id, \PDO::PARAM_INT); $statement->execute();
        /** @var array|false $row Native associative SELECT result or no row. */
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } finally { $statement->closeCursor(); }
}

/** @return \Tablo\ApiSiteDto::Dto */
function read_api_dto(#[\SensitiveParameter] array $row, string $generatedAt): array
{
    $dto = read_api_identity($row) + read_api_configuration($row) + read_api_dates($row);
    $dto['latest'] = ApiSiteState::fromRow($row, ['enabled' => $dto['enabled'], 'tracks' => $dto['tracks_version'], 'mode' => $dto['comparison_mode']], $generatedAt);
    return $dto;
}

/** @return array{id: string, config_revision: string, name: string, origin: ?string, provider: string} */
function read_api_identity(#[\SensitiveParameter] array $row): array
{
    $name = ApiSiteDto::text($row['name'] ?? null, 320);
    if ($name === '' || mb_strlen($name, encoding: 'UTF-8') > 80) { throw new \RuntimeException('Service unavailable.'); }
    return ['id' => ReadApiRequest::decimal($row['id'] ?? null, minimum: '1'), 'config_revision' => ReadApiRequest::decimal($row['config_revision'] ?? null),
        'name' => $name, 'origin' => ApiSiteDto::origin($row['url'] ?? null), 'provider' => ApiSiteDto::choice($row['provider'] ?? null, ['github'])];
}

/** @return array{repository: string, branch: string, enabled: bool, sort_order: int, comparison_mode: string, health_check_mode: string, health_target: string, tracks_version: bool} */
function read_api_configuration(#[\SensitiveParameter] array $row): array
{
    $branch = ApiSiteDto::text($row['branch'] ?? null, 128);
    if ($branch === '') { throw new \RuntimeException('Service unavailable.'); }
    return ['repository' => read_api_repository_name($row['repository'] ?? null), 'branch' => $branch, 'enabled' => ApiSiteDto::flag($row['enabled'] ?? null),
        'sort_order' => ApiSiteDto::integer($row['sort_order'] ?? null, 9999),
        'comparison_mode' => ApiSiteDto::choice($row['comparison_mode'] ?? null, ['release','branch']),
        'health_check_mode' => ApiSiteDto::choice($row['health_check_mode'] ?? null, ['http','json']),
        'health_target' => ApiSiteDto::flag($row['health_target_configured'] ?? null) ? 'endpoint' : 'homepage',
        'tracks_version' => ApiSiteDto::flag($row['tracks_version'] ?? null)];
}

function read_api_repository_name(#[\SensitiveParameter] mixed $value): string
{
    $repository = ApiSiteDto::text($value, 140);
    if (preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repository) !== 1) { throw new \RuntimeException('Service unavailable.'); }
    foreach (explode('/', $repository) as $part) { if (in_array($part, ['.','..'], strict: true)) { throw new \RuntimeException('Service unavailable.'); } }
    return $repository;
}

/** @return array{created_at: string, updated_at: string} */
function read_api_dates(#[\SensitiveParameter] array $row): array
{
    $created = ApiSiteDto::timestamp($row['created_at'] ?? null); $updated = ApiSiteDto::timestamp($row['updated_at'] ?? null);
    if ($created === null || $updated === null) { throw new \RuntimeException('Service unavailable.'); }
    return ['created_at' => $created, 'updated_at' => $updated];
}

function read_api_text(#[\SensitiveParameter] mixed $value, int $maximum): string
    {
        if (!is_string($value) || strlen($value) > $maximum || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) { throw new \RuntimeException('Service unavailable.'); }
        return $value;
    }

/** @param list<string> $allowed */
function read_api_choice(mixed $value, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, strict: true)) { throw new \RuntimeException('Service unavailable.'); }
        return $value;
    }

function read_api_integer(mixed $value, int $maximum = 2_147_483_647): int
    {
        if (!is_int($value) || $value < 0 || $value > $maximum) { throw new \RuntimeException('Service unavailable.'); }
        return $value;
    }

function read_api_flag(mixed $value): bool
    {
        if (!in_array($value, [0, 1], strict: true)) { throw new \RuntimeException('Service unavailable.'); }
        return $value === 1;
    }

function read_api_timestamp(mixed $value): ?string
    {
        if ($value === null) { return null; }
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $value) !== 1) { throw new \RuntimeException('Service unavailable.'); }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $value) { throw new \RuntimeException('Service unavailable.'); }
        return $value;
    }

function read_api_origin(#[\SensitiveParameter] mixed $value): ?string
{
    if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1 || preg_match('//u', $value) !== 1) { return null; }
    $url = parse_url($value);
    if (!is_array($url) || !in_array($url['scheme'] ?? null, ['http','https'], strict: true)
        || ($url['host'] ?? null) === null || array_intersect(['user','pass','query','fragment'], array_keys($url)) !== []) { return null; }
    if (!read_api_origin_host($url['host']) || ($url['port'] ?? null) === 0) { return null; }
    $port = $url['port'] ?? null;
    return $url['scheme'] . '://' . strtolower($url['host']) . ($port === null ? '' : ':' . $port);
}

function read_api_origin_host(string $host): bool
{
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        return filter_var(substr($host, offset: 1, length: -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }
    return strlen($host) <= 253 && preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?$/D', $host) === 1;
}


/** @param array{enabled: bool, tracks: bool, mode: string} $config
 * @return \Tablo\ApiSiteDto::Latest */
function read_api_state(#[\SensitiveParameter] array $row, array $config, string $generatedAt): array
    {
        $checked = ApiSiteDto::timestamp($row['checked_at'] ?? null);
        $now = ApiSiteDto::timestamp($generatedAt);
        if ($now === null) { throw new \RuntimeException('Service unavailable.'); }
        read_api_observation_admission($row, $checked);
        $values = read_api_observations($row);
        $comparison = match (true) {
            !$config['tracks'] => 'not_tracked', $checked === null => 'unknown', default => ApiSiteState::comparison($row, $config['mode']),
        };
        $known = count(array_filter([$values['online'] !== null, $values['open_issues'] !== null, $values['open_prs'] !== null,
            $config['tracks'] && $comparison !== 'unknown']));
        $status = match (true) {
            !$config['enabled'] => 'paused', $checked === null || $known === 0 => 'unknown',
            $known === ($config['tracks'] ? 4 : 3) => 'complete', default => 'partial',
        };
        return ['status' => $status, 'checked_at' => $checked, 'freshness' => read_api_freshness($checked, $now)] + $values + ['comparison' => $comparison];
    }

function read_api_observation_admission(#[\SensitiveParameter] array $row, ?string $checked): void
{
    if ($checked !== null) { return; }
    foreach (['online', 'health_error_code', 'health_http_status', 'response_time_ms', 'open_issues', 'open_prs', 'deployed_version', 'latest_release', 'deployed_commit', 'latest_commit'] as $field) {
        if (($row[$field] ?? null) !== null) { throw new \RuntimeException('Service unavailable.'); }
    }
}

/** @return array{online: ?bool, health_error_code: ?string, health_http_status: ?int, response_time_ms: ?int, open_issues: ?int, open_prs: ?int} */
function read_api_observations(#[\SensitiveParameter] array $row): array
{
    $online = read_api_optional_integer($row['online'] ?? null, maximum: 1);
    $http = read_api_optional_integer($row['health_http_status'] ?? null, maximum: 599);
    if ($http !== null && $http < 100) { throw new \RuntimeException('Service unavailable.'); }
    $error = ($row['health_error_code'] ?? null) === null ? null : ApiSiteDto::choice($row['health_error_code'], [...array_keys(HttpFailure::MESSAGES), 'http']);
    return ['online' => $online === null ? null : $online === 1, 'health_error_code' => $error, 'health_http_status' => $http,
        'response_time_ms' => read_api_optional_integer($row['response_time_ms'] ?? null),
        'open_issues' => read_api_optional_integer($row['open_issues'] ?? null), 'open_prs' => read_api_optional_integer($row['open_prs'] ?? null)];
}

function read_api_optional_integer(mixed $value, int $maximum = 2_147_483_647): ?int
{ return $value === null ? null : ApiSiteDto::integer($value, $maximum); }

function read_api_freshness(?string $checked, string $now): string
{
    if ($checked === null) { return 'never_checked'; }
    $age = ApiSiteState::epoch($now) - ApiSiteState::epoch($checked);
    return match (true) { $age < 0 => 'clock_invalid', $age > 900 => 'stale', default => 'fresh' };
}

function read_api_epoch(string $value): int { return (new \DateTimeImmutable($value))->getTimestamp(); }

function read_api_operand(#[\SensitiveParameter] mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 256 || preg_match('/^[\x21-\x7e]+$/D', $value) !== 1) { return null; }
        return $value;
    }

function read_api_comparison(#[\SensitiveParameter] array $row, string $mode): string
{
    $a = ApiSiteState::operand($row[$mode === 'release' ? 'deployed_version' : 'deployed_commit'] ?? null);
    $b = ApiSiteState::operand($row[$mode === 'release' ? 'latest_release' : 'latest_commit'] ?? null);
    if ($a === null || $b === null) { return 'unknown'; }
    if ($mode === 'branch') {
        if (preg_match('/^[a-f0-9]{7,64}$/Di', $a) !== 1 || preg_match('/^[a-f0-9]{7,64}$/Di', $b) !== 1) { return 'unknown'; }
        return str_starts_with(strtolower($b), strtolower($a)) ? 'current' : 'different';
    }
    return read_api_release_comparison($a, $b);
}

function read_api_release_comparison(#[\SensitiveParameter] string $a, #[\SensitiveParameter] string $b): string
{
    $a = preg_match('/^v(?=[0-9])/i', $a) === 1 ? substr($a, offset: 1) : $a;
    $b = preg_match('/^v(?=[0-9])/i', $b) === 1 ? substr($b, offset: 1) : $b;
    if ($a === $b) { return 'current'; }
    if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+].*)?$/D', $a) === 1 && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+].*)?$/D', $b) === 1) {
        return version_compare($a, $b, operator: '<') ? 'update_available' : 'ahead';
    }
    return 'different';
}


function read_api_bearer(#[\SensitiveParameter] array $server): ?string
    {
        /** @var mixed $header Untrusted normalized SAPI header. */
        $header = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        $matches = [];
        if (!is_string($header) || strlen($header) > 256
            || preg_match('/^Bearer (tablo_read_v1_[0-9a-f]{64})$/Di', $header, $matches) !== 1) { return null; }
        // Only the scheme is case insensitive; the issued token is canonical lowercase.
        $token = $matches[1] ?? '';
        if (preg_match('/^tablo_read_v1_[0-9a-f]{64}$/D', $token) !== 1) { return null; }
        if (($server['HTTP_AUTHORIZATION'] ?? null) !== null && ($server['REDIRECT_HTTP_AUTHORIZATION'] ?? null) !== null
            && $server['HTTP_AUTHORIZATION'] !== $server['REDIRECT_HTTP_AUTHORIZATION']) { return null; }
        return $token;
    }

function read_api_decimal(mixed $value, string $minimum = '0'): string
    {
        if (!is_string($value) || preg_match($minimum === '0' ? '/^(?:0|[1-9][0-9]{0,18})$/D' : '/^[1-9][0-9]{0,18}$/D', $value) !== 1
            || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)) {
            throw new \InvalidArgumentException('Invalid request.');
        }
        return $value;
    }

/** Route errors precede parameter errors, after authentication. */
function read_api_parse(#[\SensitiveParameter] array $server, int $bodyBytes = 0): ReadApiRequest|ReadApiResponse
{
    /** @var mixed $target Untrusted raw SAPI target. */
    $target = $server['REQUEST_URI'] ?? '';
    if (!is_string($target)) { return ReadApiResponse::error('invalid_parameter'); }
    if (strlen($target) > 1281) { return ReadApiResponse::error('invalid_parameter'); }
    $parts = explode('?', $target, limit: 2);
    $path = $parts[0]; $query = $parts[1] ?? '';
    $route = read_api_route($path, $query, $server, $bodyBytes);
    if ($route !== null) { return $route; }
    try { return read_api_parameters($path, $query, count($parts)); }
    catch (\InvalidArgumentException) { return ReadApiResponse::error('invalid_parameter'); }
}

function read_api_route(string $path, string $query, #[\SensitiveParameter] array $server, int $bodyBytes): ?ReadApiResponse
{
    if (strlen($path) > 256 || strlen($query) > 1024) { return ReadApiResponse::error('invalid_parameter'); }
    if ($path !== '/api/v1/sites' && !str_starts_with($path, '/api/v1/sites/')) { return ReadApiResponse::error('not_found'); }
    if (($server['REQUEST_METHOD'] ?? '') !== 'GET') { return ReadApiResponse::error('method_not_allowed'); }
    if ($bodyBytes !== 0 || !in_array($server['CONTENT_LENGTH'] ?? '0', ['0',''], strict: true)
        || array_key_exists('HTTP_TRANSFER_ENCODING', $server)) { return ReadApiResponse::error('invalid_parameter'); }
    return null;
}

function read_api_parameters(string $path, string $query, int $parts): ReadApiRequest
{
    if ($path !== '/api/v1/sites') {
        if ($parts !== 1) { throw new \InvalidArgumentException('Invalid request.'); }
        return new ReadApiRequest(ReadApiRequest::decimal(substr($path, offset: 14), minimum: '1'));
    }
    if ($query === '' && $parts !== 1) { throw new \InvalidArgumentException('Invalid request.'); }
    $values = read_api_paging($query);
    return new ReadApiRequest(null, $values['limit'], $values['after_id']);
}

/** @return array{limit: int, after_id: string} */
function read_api_paging(string $query): array
{
    $values = [];
    foreach ($query === '' ? [] : explode('&', $query) as $pair) {
        $match = [];
        if (preg_match('/^(limit|after_id)=([0-9]+)$/D', $pair, $match) !== 1) { throw new \InvalidArgumentException('Invalid request.'); }
        $key = $match[1] ?? ''; $value = $match[2] ?? '';
        if (array_key_exists($key, $values)) { throw new \InvalidArgumentException('Invalid request.'); }
        $values[$key] = ReadApiRequest::decimal($value, minimum: $key === 'after_id' ? '0' : '1');
    }
    $limit = (int) ($values['limit'] ?? '50');
    if ($limit < 1 || $limit > 100) { throw new \InvalidArgumentException('Invalid request.'); }
    return ['limit' => $limit, 'after_id' => $values['after_id'] ?? '0'];
}


function read_api_authentication_shape(\PDO $db): void
    {
        ReadApiSchemaAdmission::table($db, 'users', ['id' => 'INTEGER', 'password_hash' => 'TEXT']);
        ReadApiSchemaAdmission::table($db, 'read_api_token', ['id' => 'INTEGER', 'secret_hash' => 'TEXT', 'administrator_fingerprint' => 'TEXT', 'created_at' => 'TEXT']);
        if (DatabaseSession::server($db)) { return; }
        $statement = SqlStatement::checked($db->query("SELECT substr(sql,1,4097) FROM main.sqlite_schema WHERE name='read_api_token' AND type='table'"));
        try {
            /** @var string|null|false $sql Native schema SQL or no-row sentinel. */
            $sql = $statement->fetchColumn();
        }
        finally { $statement->closeCursor(); }
        $normalize = static fn (string $value): string => str_replace([" ", "\r", "\n", "\t"], replace: '', subject: $value);
        if (!is_string($sql) || $normalize($sql) !== $normalize(ReadApiTokenSchema::sql())) { throw new \RuntimeException('Service unavailable.'); }
    }

function read_api_sites_shape(\PDO $db): void
    {
        $columns = array_fill_keys(['id', 'config_revision', 'enabled', 'sort_order', 'online', 'health_http_status', 'response_time_ms', 'open_issues', 'open_prs'], value: 'INTEGER');
        foreach (['name', 'url', 'provider', 'repository', 'branch', 'comparison_mode', 'health_check_mode', 'health_path', 'version_path',
            'created_at', 'updated_at', 'checked_at', 'health_error_code', 'deployed_version', 'latest_release', 'deployed_commit', 'latest_commit'] as $column) { $columns[$column] = 'TEXT'; }
        ReadApiSchemaAdmission::table($db, 'sites', $columns);
    }

/** @param array<string, string> $required */
function read_api_table_shape(\PDO $db, string $table, array $required): void
    {
        if (DatabaseSession::server($db)) { read_api_server_shape($db, $table, array_keys($required)); return; }
        read_api_table_object($db, $table);
        $statement = SqlStatement::checked($db->prepare("SELECT substr(name,1,65) AS name,substr(type,1,33) AS type,pk,hidden FROM pragma_table_xinfo(:name,'main') LIMIT 129"));
        try { $statement->execute([':name' => $table]); $rows = SqlRows::all($statement->fetchAll(\PDO::FETCH_ASSOC), static fn (array $row): array => $row); }
        finally { $statement->closeCursor(); }
        if (count($rows) > 128) { throw new \RuntimeException('Service unavailable.'); }
        $found = []; $keys = [];
        foreach ($rows as $row) {
            if (!is_string($row['name'] ?? null) || !is_string($row['type'] ?? null)) { throw new \RuntimeException('Service unavailable.'); }
            if (($row['pk'] ?? 0) !== 0) { $keys[] = $row['name']; }
            if (($row['hidden'] ?? null) === 0) { $found[$row['name']] = strtoupper($row['type']); }
        }
        if ($keys !== ['id'] || array_diff_assoc($required, $found) !== []) { throw new \RuntimeException('Service unavailable.'); }
    }


/** Exact selected-server projection admission lives beside the existing SQLite reader admission.
 * @param list<string> $required */
function read_api_server_shape(\PDO $db, string $name, array $required): void
{
    $table = read_api_server_table($db, $name);
    $found = read_api_server_columns($db, $name);
    $expected = array_column($table['columns'], 'type', 'name');
    foreach ($required as $column) {
        $type = $expected[$column] ?? null;
        if ($type === null || ($found[$column] ?? null) !== $type) { throw new DatabaseFailure('schema'); }
    }
}

/** @return \Tablo\ServerSchema::Table */
function read_api_server_table(\PDO $db, string $name): array
{
    if (!in_array($name, ['users', 'read_api_token', 'sites'], strict: true)) { throw new DatabaseFailure('schema'); }
    $tables = array_filter(ServerSchema::layout($db)['tables'], static fn (array $table): bool => $table['name'] === $name);
    $table = array_values($tables)[0] ?? throw new DatabaseFailure('schema');
    $objects = ServerSchema::rows($db, 'SELECT TABLE_TYPE AS kind, ENGINE AS engine, TABLE_COLLATION AS collation FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name]);
    if (count($objects) !== 1 || ($objects[0]['kind'] ?? null) !== 'BASE TABLE' || ($objects[0]['engine'] ?? null) !== 'InnoDB'
        || ($objects[0]['collation'] ?? null) !== DatabaseSession::collation($db)) { throw new DatabaseFailure('schema'); }
    return $table;
}

/** @return array<string, string> */
function read_api_server_columns(\PDO $db, string $name): array
{
    $columns = ServerSchema::rows($db, 'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, COLUMN_KEY AS key_kind FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name]);
    $found = [];
    foreach ($columns as $column) {
        if (!is_string($column['name'] ?? null) || !is_string($column['type'] ?? null)) { throw new DatabaseFailure('schema'); }
        $found[$column['name']] = $column['type'] === 'bigint(20)' ? 'bigint' : $column['type'];
        if ($column['name'] === 'id' && ($column['key_kind'] ?? null) !== 'PRI') { throw new DatabaseFailure('schema'); }
    }
    return $found;
}

function read_api_serve(): void
    {
        $depth = ob_get_level(); $headers = headers_list();
        $sink = new class {
            public bool $output = false;
            public bool $closed = false;
            public bool $cleaning = false;
            public function consume(#[\SensitiveParameter] string $bytes, ?int $phase): string
            {
                $this->output = $this->output || $bytes !== '';
                if ((($phase ?? 0) & PHP_OUTPUT_HANDLER_FINAL) !== 0 && !$this->cleaning) { $this->closed = true; }
                return '';
            }
        };
        ob_start($sink->consume(...), chunk_size: 4096);
        $response = ReadApiResponse::error('unavailable');
        http_response_code(500);
        try {
            $response = read_api_dispatch($_SERVER);
        } catch (\Throwable) { $response = ReadApiResponse::error('unavailable'); }
        $sink->cleaning = true;
        $sink->output = read_api_clean_capture($depth) || $sink->output;
        if ($sink->output || $sink->closed || ob_get_level() !== $depth || serialize(headers_list()) !== serialize($headers) || http_response_code() !== 500) {
            $response = ReadApiResponse::error('unavailable');
        }
        // Ordinary output/exceptions are contained. Destroyed buffers, transport flush and process exit cannot be undone.
        if (headers_sent() || ob_get_level() !== $depth) { return; }
        header_remove();
        $response->send();
    }

function read_api_dispatch(#[\SensitiveParameter] array $server): ReadApiResponse
{
    $token = ReadApiRequest::bearer($server);
    if ($token === null) { return ReadApiResponse::error('unauthorized'); }
    require dirname(__DIR__) . '/vendor/autoload.php';
    return ReadApi::read($token, $server);
}

function read_api_response(#[\SensitiveParameter] string $token, #[\SensitiveParameter] array $server): ReadApiResponse
    {
        $db = ReadApiConnection::open();
        $serverEngine = DatabaseSession::server($db);
        $db->exec($serverEngine ? 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' : 'BEGIN');
        $primary = null;
        try {
            // Pin schema admission to the same snapshot as authentication and site reads.
            if ($serverEngine) { ServerSessionAdmission::pinReader($db); }
            if (!$serverEngine && SchemaVersion::schemaVersion($db) !== Database::CURRENT_SCHEMA_VERSION) { throw new \RuntimeException('Service unavailable.'); }
            ReadApiSchemaAdmission::authentication($db);
            if (!(new ReadApiTokenRepository($db))->authenticate($token)) { return ReadApiResponse::error('unauthorized'); }
            $body = file_get_contents('php://input', length: 1);
            if ($body === false) { throw new \RuntimeException('Service unavailable.'); }
            $request = ReadApiRequest::parse($server, strlen($body));
            if ($request instanceof ReadApiResponse) { return $request; }
            ReadApiSchemaAdmission::sites($db);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            if ($request->id !== null) {
                $detail = read_api_site($db, $request->id, $now);
                return $detail === null ? ReadApiResponse::error('not_found') : ReadApiResponse::success($detail);
            }
            return ReadApiResponse::success(read_api_sites($db, $request->afterId, $request->limit, $now));
        } catch (\Throwable $error) { $primary = $error; throw $error; }
        finally {
            try { $db->exec('ROLLBACK'); }
            catch (\Throwable $rollback) { if ($primary === null) { DatabaseFailure::rethrow($db, $rollback); } }
        }
    }

/** @return array{api_version: int, generated_at: string, data: list<\Tablo\ApiSiteDto::Dto>, page: array{next_after_id: ?string}} */
function read_api_sites(\PDO $db, string $afterId, int $limit, string $now): array
{
    $rows = (new ReadApiRepository($db))->page($afterId, $limit);
    $more = count($rows) > $limit;
    if ($more) { array_pop($rows); }
    $data = array_map(static fn (array $row): array => ApiSiteDto::fromRow($row, $now), $rows);
    $last = array_pop($data);
    if ($last !== null) { $data[] = $last; }
    return ['api_version' => 1, 'generated_at' => $now, 'data' => $data, 'page' => ['next_after_id' => $more ? ($last['id'] ?? null) : null]];
}

/** @return array{api_version: int, generated_at: string, data: \Tablo\ApiSiteDto::Dto}|null */
function read_api_site(\PDO $db, string $id, string $now): ?array
{
    $row = (new ReadApiRepository($db))->find($id);
    return $row === null ? null : ['api_version' => 1, 'generated_at' => $now, 'data' => ApiSiteDto::fromRow($row, $now)];
}


function read_api_table_object(\PDO $db, string $table): void
{
        $statement = SqlStatement::checked($db->prepare("SELECT type, substr(sql,1,32) AS prefix FROM main.sqlite_schema WHERE name=:name"));
        try {
            $statement->execute([':name' => $table]);
            /** @var array|false $object Native associative metadata or no row. */
            $object = $statement->fetch(\PDO::FETCH_ASSOC);
        }
        finally { $statement->closeCursor(); }
        if (!is_array($object) || ($object['type'] ?? null) !== 'table' || !is_string($object['prefix'] ?? null)
            || preg_match('/^CREATE TABLE /i', $object['prefix']) !== 1) { throw new \RuntimeException('Service unavailable.'); }
}

function read_api_clean_capture(int $depth): bool
{
    $outputBytes = 0;
    for ($i = 0; ob_get_level() > $depth && $i < 32; ++$i) {
        $length = ob_get_length();
        if ($length === false) { return true; }
        $outputBytes += $length;
        if ((ob_get_status()['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) === 0) { break; }
        try { if (!ob_end_clean()) { break; } }
        catch (\Throwable) { return true; }
    }
    return $outputBytes > 0;
}
