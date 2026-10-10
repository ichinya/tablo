<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class SiteRepository
{
    private readonly TokenVault $tokens;
    private readonly GitTokenRepository $savedTokens;

    public function __construct(private readonly PDO $db, #[\SensitiveParameter] ?TokenVault $tokens = null)
    {
        $this->tokens = $tokens ?? TokenVault::forDatabase($db);
        $this->savedTokens = new GitTokenRepository($db, $this->tokens);
    }

    // Settlement must write on the exact connection that owns its transaction.
    public function assertConnection(#[\SensitiveParameter] PDO $db): void
    {
        if ($this->db !== $db) { throw new \LogicException('Settlement repository connection mismatch.'); }
    }

    public static function defaults(): array
    {
        return ['name' => '', 'url' => '', 'repository' => '', 'branch' => 'main', 'health_path' => '/up',
            'version_path' => '', 'version_json_path' => '', 'health_check_mode' => 'http',
            'health_json_path' => '', 'health_json_operator' => '==', 'health_json_expected_value' => '',
            'comparison_mode' => 'release', 'enabled' => 1, 'sort_order' => 0];
    }

    public function all(): array
    {
        return $this->db->query('SELECT s.*, t.encrypted_token AS selected_token_snapshot
            FROM sites s LEFT JOIN git_tokens t ON t.id = s.git_token_id ORDER BY s.sort_order, s.id')->fetchAll();
    }

    public function githubCooldowns(): GitHubCooldownRepository
    {
        return new GitHubCooldownRepository($this->db);
    }

    public function enabledIds(): array
    {
        $statement = $this->db->query('SELECT id FROM sites WHERE enabled = 1 ORDER BY sort_order, id');
        try { return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)); }
        finally { $statement->closeCursor(); }
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT s.*, t.encrypted_token AS selected_token_snapshot
            FROM sites s LEFT JOIN git_tokens t ON t.id = s.git_token_id WHERE s.id = ?');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public static function normalize(#[\SensitiveParameter] array $input): array
    {
        $data = self::defaults();
        $errors = [];
        foreach (['name', 'url', 'repository', 'branch', 'health_path', 'version_path', 'comparison_mode'] as $field) {
            $data[$field] = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
        }
        if ($data['name'] === '' || mb_strlen($data['name']) > 80) {
            $errors['name'] = 'Укажите название до 80 символов.';
        }
        $data['url'] = rtrim($data['url'], '/');
        $parts = parse_url($data['url']);
        if (strlen($data['url']) > 500 || !filter_var($data['url'], FILTER_VALIDATE_URL)
            || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            $errors['url'] = 'Нужен HTTP(S) URL без логина, пароля, query и fragment.';
        }
        try {
            $data['repository'] = self::normalizeRepository($data['repository']);
        } catch (ValidationException $e) {
            $errors += $e->errors;
        }
        if ($data['branch'] === '' || strlen($data['branch']) > 128 || preg_match('/[\s\x00-\x1f]/', $data['branch'])) {
            $errors['branch'] = 'Укажите ветку без пробелов, до 128 символов.';
        }
        foreach (['health_path', 'version_path'] as $field) {
            if (array_key_exists($field, $input) && !is_string($input[$field])) {
                $errors[$field] = 'Укажите путь строкой.';
            }
            $path = $data[$field];
            if ($path === '') { continue; }
            if (!str_starts_with($path, '/') || str_starts_with($path, '//') || strlen($path) > 300
                || preg_match('~[\s\\\\\x00-\x1f#]~', $path)) {
                $errors[$field] = 'Укажите путь на этом сайте, например /up.';
            }
        }
        $mode = $input['health_check_mode'] ?? 'http';
        if (!is_string($mode) || !in_array($mode, ['http', 'json'], true)) {
            $errors['health_check_mode'] = 'Выберите HTTP-статус или JSON-поле.';
        } else {
            $data['health_check_mode'] = $mode;
        }
        foreach (['version_json_path', 'health_json_path'] as $field) {
            $raw = $input[$field] ?? '';
            $data[$field] = is_string($raw) ? trim($raw) : '';
            $active = $field === 'version_json_path' ? $data['version_path'] !== '' : ($data['health_path'] !== '' && $mode === 'json');
            if (!$active || ($field === 'version_json_path' && is_string($raw) && $data[$field] === '')) { continue; }
            try {
                if (!is_string($raw)) { throw new \InvalidArgumentException('Укажите JSON path строкой.'); }
                JsonField::validatePath($data[$field]);
            } catch (\InvalidArgumentException $e) {
                $errors[$field] = $e->getMessage();
            }
        }
        $operator = $input['health_json_operator'] ?? '==';
        $data['health_json_operator'] = is_string($operator) && in_array($operator, JsonField::OPERATORS, true) ? $operator : '==';
        $expected = $input['health_json_expected_value'] ?? '';
        $data['health_json_expected_value'] = is_string($expected) ? $expected : '';
        if ($data['health_path'] !== '' && $mode === 'json') {
            if (!is_string($operator) || !in_array($operator, JsonField::OPERATORS, true)) {
                $errors['health_json_operator'] = 'Выберите допустимое условие JSON-проверки.';
            }
            if (!is_string($expected) || mb_strlen($expected) > JsonField::MAX_EXPECTED_LENGTH) {
                $errors['health_json_expected_value'] = 'Ожидаемое значение — строка до 512 символов.';
            } elseif (in_array($operator, ['>', '>=', '<', '<='], true)) {
                try { JsonField::number($expected); }
                catch (\InvalidArgumentException $e) { $errors['health_json_expected_value'] = $e->getMessage(); }
            }
        }
        if (!in_array($data['comparison_mode'], ['release', 'branch'], true)) {
            $errors['comparison_mode'] = 'Выберите релиз или HEAD ветки.';
        }
        $data['enabled'] = in_array($input['enabled'] ?? null, [1, '1', 'on'], true) ? 1 : 0;
        $order = filter_var($input['sort_order'] ?? 0, FILTER_VALIDATE_INT);
        if ($order === false || $order < 0 || $order > 9999) {
            $errors['sort_order'] = 'Порядок должен быть целым числом от 0 до 9999.';
        } else {
            $data['sort_order'] = $order;
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $data;
    }

    public static function normalizeRepository(string $repository): string
    {
        $repository = trim($repository);
        if (preg_match('~^https://github\.com/([^/?#]+/[^/?#]+)/?$~i', $repository, $match)) {
            $repository = $match[1];
        }
        $repository = preg_replace('~\.git$~', '', $repository);
        if (!preg_match('~^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,38})/[a-zA-Z0-9_.-]{1,100}$~D', $repository)
            || in_array(explode('/', $repository)[1] ?? '', ['.', '..'], true)) {
            throw new ValidationException(['repository' => 'Укажите owner/repository или URL репозитория на github.com.']);
        }
        return $repository;
    }

    public static function validateToken(#[\SensitiveParameter] mixed $token): string
    {
        if (!is_string($token) || ($token !== '' && !preg_match('/^[\x21-\x7e]{1,512}$/D', $token))) {
            throw new ValidationException(['github_token' => 'Токен должен содержать до 512 печатных символов без пробелов.']);
        }
        return $token;
    }

    public function tokenFor(#[\SensitiveParameter] ?array $site): string
    {
        if (!empty($site['git_token_id'])) {
            return $this->savedTokens->tokenFor((int) $site['git_token_id'], $site['provider'] ?? 'github');
        }
        return empty($site['github_token']) ? '' : $this->tokens->decrypt($site['github_token']);
    }

    private function selectedToken(#[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $site): ?int
    {
        $id = $input['git_token_id'] ?? ($site['git_token_id'] ?? '');
        if (!array_key_exists('git_token_id', $input) && ($input['github_token'] ?? '') !== '') { $id = ''; }
        if ($id === '' || $id === null) { return null; }
        if ((!is_string($id) && !is_int($id)) || !ctype_digit((string) $id) || (int) $id < 1) {
            throw new ValidationException(['git_token_id' => 'Выберите сохранённый токен.']);
        }
        $saved = $this->savedTokens->find((int) $id);
        if (!$saved || $saved['provider'] !== ($site['provider'] ?? 'github')) {
            throw new ValidationException(['git_token_id' => 'Выберите сохранённый токен для этого Git-провайдера.']);
        }
        return (int) $id;
    }

    public function resolveToken(#[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $site): string
    {
        $token = self::validateToken($input['github_token'] ?? '');
        $selected = $this->selectedToken($input, $site);
        if ($selected !== null) {
            if ($token !== '') { throw new ValidationException(['github_token' => 'Выберите сохранённый токен или введите свой.']); }
            return $this->savedTokens->tokenFor($selected, $site['provider'] ?? 'github');
        }
        if ($token !== '') {
            return $token;
        }
        if (!in_array($input['remove_github_token'] ?? null, [1, '1', 'on'], true)) {
            $token = empty($site['github_token']) ? '' : $this->tokens->decrypt($site['github_token']);
        }
        return $token;
    }

    public function credentialRevision(#[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $site): string
    {
        $selected = $this->selectedToken($input, $site);
        if ($selected !== null) {
            $statement = $this->db->prepare('SELECT encrypted_token FROM git_tokens WHERE id = ?');
            $statement->execute([$selected]);
            try {
                $revision = $statement->fetchColumn();
                if (!is_string($revision)) { throw new ValidationException(['git_token_id' => 'Выберите сохранённый токен.']); }
                return $revision;
            } finally { $statement->closeCursor(); }
        }
        if (($input['github_token'] ?? '') !== '' || in_array($input['remove_github_token'] ?? null, [1, '1', 'on'], true)) { return ''; }
        return $site['github_token'] ?? '';
    }

    public function credentialScope(#[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $site): string
    {
        $selected = $this->selectedToken($input, $site);
        if ($selected !== null) { return 'saved:' . $selected; }
        if ($this->resolveToken($input, $site) === '') { return 'anonymous'; }
        return isset($site['id']) ? 'site:' . (int) $site['id'] : 'authenticated';
    }

    public function equivalentCredentialScope(#[\SensitiveParameter] string $token): ?string
    {
        if ($token === '') { return null; }
        // Even expired key-derived quota rows retain the original key's custody. A manual
        // read-only preview must not orphan them or either kind of installed ciphertext.
        return $this->tokens->credentialScope($token, !self::hasEstablishedKeyState($this->db));
    }

    public function assertWorkerKeyAvailable(): void
    {
        $this->tokens->assertAvailable(self::hasEstablishedKeyState($this->db));
    }

    public static function hasEstablishedKeyState(PDO $db): bool
    {
        // Direct repository construction also supports genuine pre-migration fixtures.
        // Inspect only known physical columns; a missing table is not a lost key.
        foreach (['git_tokens' => ['encrypted_token', "encrypted_token <> ''"],
            'sites' => ['github_token', "github_token <> ''"],
            'github_cooldowns' => ['scope', "scope LIKE 'credential:v1:%'"]] as $table => [$column, $predicate]) {
            $statement = $db->query('PRAGMA main.table_info(' . $table . ')');
            try { $columns = array_column($statement->fetchAll(), 'name'); }
            finally { $statement->closeCursor(); }
            if (!in_array($column, $columns, true)) { continue; }
            $statement = $db->query('SELECT EXISTS(SELECT 1 FROM main.' . $table . ' WHERE ' . $predicate . ')');
            try { if ((bool) $statement->fetchColumn()) { return true; } }
            finally { $statement->closeCursor(); }
        }
        return false;
    }

    public function save(#[\SensitiveParameter] array $input, ?int $id = null): int
    {
        $this->assertWorkerKeyAvailable();
        $data = self::normalize($input);
        $existing = $id === null ? null : $this->find($id);
        if ($id !== null && $existing === null) {
            throw new \OutOfBoundsException('Сайт не найден.');
        }
        $token = self::validateToken($input['github_token'] ?? '');
        $data['git_token_id'] = $this->selectedToken($input, $existing);
        if ($data['git_token_id'] !== null && $token !== '') {
            throw new ValidationException(['github_token' => 'Выберите сохранённый токен или введите свой.']);
        }
        $data['github_token'] = $data['git_token_id'] !== null ? null : ($token !== '' ? $this->tokens->encrypt($token)
            : (in_array($input['remove_github_token'] ?? null, [1, '1', 'on'], true) ? null : ($existing['github_token'] ?? null)));
        $fields = array_keys($data);
        if ($id === null) {
            $sql = 'INSERT INTO sites (' . implode(', ', $fields) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')';
            $this->db->prepare($sql)->execute(array_values($data));
            return (int) $this->db->lastInsertId();
        }
        $sql = 'UPDATE sites SET ' . implode(', ', array_map(fn ($f) => "$f = ?", $fields)) . ',
            online = NULL, health_error_code = NULL, health_http_status = NULL,
            deployed_version = NULL, deployed_commit = NULL, latest_release = NULL,
            latest_commit = NULL, open_issues = NULL, open_prs = NULL, response_time_ms = NULL,
            last_error = NULL, checked_at = NULL, config_revision = config_revision + 1,
            updated_at = strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\') WHERE id = ?';
        $this->db->prepare($sql)->execute([...array_values($data), $id]);
        return $id;
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
    }

    public function storeCheck(array $site, array $state): bool
    {
        // A result for an old configuration must not overwrite a concurrent edit.
        $fields = ['online', 'health_error_code', 'health_http_status', 'deployed_version', 'deployed_commit', 'latest_release', 'latest_commit',
            'open_issues', 'open_prs', 'response_time_ms', 'last_error', 'checked_at'];
        $config = ['name', 'url', 'repository', 'branch', 'health_path', 'version_path', 'version_json_path',
            'health_check_mode', 'health_json_path', 'health_json_operator', 'health_json_expected_value',
            'comparison_mode', 'enabled', 'sort_order', 'config_revision'];
        $sql = 'UPDATE sites SET ' . implode(', ', array_map(fn ($f) => "$f = ?", $fields))
            . ' WHERE id = ? AND ' . implode(' AND ', array_map(fn ($f) => "$f = ?", $config)) . ' AND github_token IS ?
                AND git_token_id IS ? AND (SELECT encrypted_token FROM git_tokens WHERE id = sites.git_token_id) IS ?';
        $statement = $this->db->prepare($sql);
        $statement->execute([...array_map(fn ($f) => $state[$f] ?? null, $fields), $site['id'], ...array_map(fn ($f) => $site[$f], $config),
            $site['github_token'] ?? null, $site['git_token_id'] ?? null, $site['selected_token_snapshot'] ?? null]);
        return $statement->rowCount() > 0;
    }
}
