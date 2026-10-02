<?php
declare(strict_types=1);

// Runs against an isolated database and server. No production data or password is changed.
$root = dirname(__DIR__);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$temporary = sys_get_temp_dir() . '/tablo-http-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$environment = getenv();
$environment['TABLO_DB'] = $temporary . '/test.sqlite';
// A legacy environment token must never grant access or affect the form.
$environment['GITHUB_TOKEN'] = 'fixture-token';
$process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $root . '/public', $root . '/tests/web-router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/server.log', 'a'], 2 => ['file', $temporary . '/server.log', 'a']], $pipes, $root, $environment);
$base = 'http://127.0.0.1:' . $port;
$cookies = $temporary . '/cookies';

function verify(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException($label); }
    echo "PASS $label\n";
}

function request(string $path, ?array $data = null, bool $cookie = true): array
{
    global $base, $cookies;
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => $cookie ? $cookies : '', CURLOPT_COOKIEJAR => $cookie ? $cookies : null]);
    if ($data !== null) {
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    }
    $raw = curl_exec($curl);
    if ($raw === false) { throw new RuntimeException('HTTP connection failed'); }
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
}

function token(array $response): string
{
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $response['body'], $match);
    if (!isset($match[1])) { throw new RuntimeException('CSRF missing: ' . substr($response['body'], 0, 250)); }
    return $match[1];
}

try {
    for ($i = 0; $i < 50; ++$i) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, .1);
        if ($connection) { fclose($connection); break; }
        usleep(100000);
    }
    $setup = request('/setup');
    verify($setup['status'] === 200 && str_contains($setup['body'], 'Создать панель'), 'Volt setup page renders');
    verify(str_contains(strtolower($setup['headers']), 'httponly') && str_contains(strtolower($setup['headers']), 'samesite=strict'), 'session cookie protections');
    verify(str_contains($setup['headers'], "frame-ancestors 'none'"), 'CSP present');
    $csrf = token($setup);
    verify(request('/setup', ['password' => 'fixture-password', 'confirmation' => 'fixture-password'])['status'] === 419, 'setup rejects missing CSRF');
    $result = request('/setup', ['_csrf' => $csrf, 'password' => 'fixture-password', 'confirmation' => 'fixture-password']);
    verify($result['status'] === 303 && str_contains($result['headers'], 'Location: /'), 'setup creates admin and authenticates');
    $dashboard = request('/');
    verify($dashboard['status'] === 200 && str_contains($dashboard['body'], 'Ваши сайты.'), 'dashboard renders');
    $csrf = token($dashboard);
    verify($csrf !== token($setup), 'authentication rotates CSRF');
    $form = request('/sites/new');
    verify($form['status'] === 200 && str_contains($form['body'], 'Health endpoint'), 'new site form renders');
    verify(str_contains($form['body'], 'placeholder="https://example.com"')
        && str_contains($form['body'], 'placeholder="https://github.com/owner/repository"')
        && !str_contains($form['body'], 'tempalog') && !str_contains($form['body'], 'ichinya'), 'neutral project placeholders');
    verify(preg_match('/id="version_path"[^>]*value=""[^>]*>/', $form['body']) === 1
        && preg_match('/id="version_path"[^>]*required/', $form['body']) === 0, 'version field defaults to blank and is optional');
    verify(str_contains($form['body'], 'name="github_token" value=""') && str_contains($form['body'], 'Получить ветки'), 'repository token and branch controls render');
    verify(!str_contains($form['body'], 'токен установки') && !str_contains($form['body'], 'fixture-token'), 'environment token neither advertised nor exposed');
    verify(request('/sites/branches', ['repository' => 'fixture/private', 'github_token' => 'fixture-token'])['status'] === 419, 'branch loading requires CSRF');
    $lookup = ['_csrf' => $csrf, 'repository' => 'https://github.com/fixture/private.git', 'github_token' => 'fixture-token'];
    $branches = request('/sites/branches', $lookup);
    $json = json_decode($branches['body'], true, 16, JSON_THROW_ON_ERROR);
    verify($branches['status'] === 200 && $json['branches'] === ['main', 'develop', 'feature/login']
        && $json['repository'] === 'fixture/private' && !str_contains($branches['body'], 'fixture-token'), 'authenticated branch lookup normalizes URL and does not echo token');
    $withoutToken = array_replace($lookup, ['github_token' => '']);
    verify(request('/sites/branches', $withoutToken)['status'] === 422, 'legacy environment token does not grant private repository access');
    verify(request('/sites/branches', array_replace($withoutToken, ['repository' => 'fixture/public']))['status'] === 200, 'public repository branches load without a token');
    foreach (['invalid-fixture-token' => '401', 'forbidden-fixture-token' => '403', '' => '404'] as $value => $status) {
        $errorResponse = request('/sites/branches', array_replace($lookup, ['github_token' => $value]));
        verify($errorResponse['status'] === 422 && str_contains($errorResponse['body'], $status)
            && ($value === '' || !str_contains($errorResponse['body'], $value)), 'GitHub ' . $status . ' error is safe and actionable');
    }
    $site = ['_csrf' => $csrf, 'name' => '<img src=x onerror=alert(1)>', 'url' => 'https://example.com',
        'repository' => 'https://github.com/ichinya/lekalo', 'branch' => 'main', 'health_path' => '/up',
        'version_path' => '/version', 'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
    verify(request('/sites/new', array_replace($site, ['url' => 'file:///etc/passwd']))['status'] === 422, 'invalid site returns 422');
    verify(request('/sites/new', array_replace($site, ['branch' => 'v1.0.0']))['status'] === 422, 'save refuses a tag or absent branch');
    verify(request('/sites/new', $site)['status'] === 303, 'site create persists');
    $dashboard = request('/');
    verify(str_contains($dashboard['body'], '&lt;img') && !str_contains($dashboard['body'], '<img src=x'), 'stored XSS escaped by Volt');
    verify(request('/sites/1/edit')['status'] === 200, 'edit form renders');
    verify(request('/sites/1/edit', array_replace($site, ['name' => 'Renamed site', 'comparison_mode' => 'branch']))['status'] === 303, 'site update persists');
    verify(str_contains(request('/')['body'], 'Renamed site'), 'updated state visible');
    verify(request('/sites/1/check', ['_csrf' => 'invalid'])['status'] === 419, 'manual check requires CSRF');
    $paused = array_replace($site, ['name' => 'Renamed site']);
    unset($paused['enabled']);
    verify(request('/sites/1/edit', $paused)['status'] === 303
        && request('/sites/1/check', ['_csrf' => $csrf])['status'] === 303
        && str_contains(request('/')['body'], 'Проверки этого сайта на паузе.'), 'disabled site skips network checks');
    verify(request('/sites/1/delete')['status'] === 200 && str_contains(request('/')['body'], 'Renamed site'), 'GET deletion only confirms');
    verify(request('/sites/1/delete', ['_csrf' => 'invalid'])['status'] === 419 && str_contains(request('/')['body'], 'Renamed site'), 'delete requires CSRF');
    verify(request('/sites/999/edit')['status'] === 404, 'missing site returns 404');
    verify(request('/sites/1/delete', ['_csrf' => $csrf])['status'] === 303 && !str_contains(request('/')['body'], 'Renamed site'), 'confirmed POST deletes');
    $private = array_replace($site, ['name' => 'Private fixture', 'url' => 'http://127.0.0.1',
        'repository' => 'fixture/private', 'branch' => 'feature/login', 'github_token' => 'fixture-token']);
    verify(request('/sites/new', $private)['status'] === 303, 'private repository and slash branch save with token');
    $db = new PDO('sqlite:' . $temporary . '/test.sqlite');
    $row = $db->query('SELECT * FROM sites')->fetch(PDO::FETCH_ASSOC);
    $id = $row['id'];
    verify($row['github_token'] !== 'fixture-token' && str_starts_with($row['github_token'], 'v1:'), 'database stores only encrypted token');
    $edit = request('/sites/' . $id . '/edit');
    verify(!str_contains($edit['body'], 'fixture-token') && !str_contains($edit['body'], $row['github_token'])
        && str_contains($edit['body'], 'Токен сохранён.'), 'edit shows saved status without credentials');
    $existingLookup = ['_csrf' => $csrf, 'site_id' => (string) $id, 'repository' => 'fixture/private'];
    verify(request('/sites/branches', $existingLookup)['status'] === 200, 'branch lookup reuses saved token');
    verify(request('/sites/branches', array_replace($existingLookup, ['remove_github_token' => '1']))['status'] === 422,
        'removal preview stops using saved token');
    $blank = array_replace($private, ['github_token' => '']);
    verify(request('/sites/' . $id . '/edit', $blank)['status'] === 303
        && $db->query('SELECT github_token FROM sites')->fetchColumn() === $row['github_token'], 'blank token preserves existing ciphertext and access');
    $failure = request('/sites/' . $id . '/edit', array_replace($private, ['github_token' => 'invalid-fixture-token']));
    verify($failure['status'] === 422 && !str_contains($failure['body'], 'invalid-fixture-token')
        && $db->query('SELECT github_token FROM sites')->fetchColumn() === $row['github_token'], 'invalid replacement neither persists nor reflects secret');
    verify(request('/sites/' . $id . '/check', ['_csrf' => $csrf])['status'] === 303
        && $db->query('SELECT latest_commit FROM sites')->fetchColumn() === str_repeat('a', 40), 'dashboard checks use site token');
    verify(request('/sites/' . $id . '/edit', array_replace($private, ['github_token' => 'replacement-token']))['status'] === 303
        && $db->query('SELECT github_token FROM sites')->fetchColumn() !== $row['github_token'], 'token replacement persists');
    $remove = array_replace($blank, ['repository' => 'ichinya/lekalo', 'remove_github_token' => '1']);
    verify(request('/sites/' . $id . '/edit', $remove)['status'] === 303
        && $db->query('SELECT github_token FROM sites')->fetchColumn() === null, 'token removal persists');
    $settings = request('/settings');
    verify($settings['status'] === 200 && str_contains($settings['body'], 'Пока нет сохранённых токенов'), 'settings token list renders empty state');
    $shared = ['_csrf' => $csrf, 'name' => 'Shared <token>', 'provider' => 'github', 'token' => 'fixture-token'];
    verify(request('/settings/tokens/new', ['name' => 'Without CSRF', 'provider' => 'github', 'token' => 'fixture-token'])['status'] === 419, 'settings token create requires CSRF');
    verify(request('/settings/tokens/new', array_replace($shared, ['token' => '']))['status'] === 422, 'new shared token requires a value');
    verify(request('/settings/tokens/new', array_replace($shared, ['provider' => 'gitlab']))['status'] === 422, 'unsupported Git provider is rejected');
    verify(request('/settings/tokens/new', $shared)['status'] === 303, 'shared token create succeeds');
    $credential = $db->query('SELECT * FROM git_tokens')->fetch(PDO::FETCH_ASSOC);
    $tokenId = (string) $credential['id'];
    verify(str_starts_with($credential['encrypted_token'], 'v1:') && $credential['encrypted_token'] !== 'fixture-token', 'shared token encrypted at rest');
    $settings = request('/settings');
    verify(str_contains($settings['body'], 'Shared &lt;token&gt;') && !str_contains($settings['body'], 'fixture-token')
        && !str_contains($settings['body'], $credential['encrypted_token']), 'settings exposes escaped metadata only');
    $tokenEdit = request('/settings/tokens/' . $tokenId . '/edit');
    verify($tokenEdit['status'] === 200 && str_contains($tokenEdit['body'], 'name="token" value=""')
        && !str_contains($tokenEdit['body'], 'fixture-token'), 'shared token edit never returns value');
    verify(str_contains(request('/sites/new')['body'], 'Shared &lt;token&gt;'), 'project selector includes saved token');
    $selectedLookup = ['_csrf' => $csrf, 'repository' => 'fixture/private', 'git_token_id' => $tokenId];
    verify(request('/sites/branches', $selectedLookup)['status'] === 200, 'branch lookup uses selected shared token');
    verify(request('/sites/branches', array_replace($selectedLookup, ['git_token_id' => '999']))['status'] === 422, 'missing saved token does not fall back silently');
    $sharedSite = array_replace($private, ['name' => 'Shared project', 'github_token' => '', 'git_token_id' => $tokenId, 'version_path' => '']);
    verify(request('/sites/new', array_replace($sharedSite, ['github_token' => 'fixture-token']))['status'] === 422, 'ambiguous manual and saved token rejected');
    verify(request('/sites/new', $sharedSite)['status'] === 303, 'project accepts saved token and no version endpoint');
    $poolSite = $db->query("SELECT * FROM sites WHERE name = 'Shared project'")->fetch(PDO::FETCH_ASSOC);
    $projectId = (string) $poolSite['id'];
    verify((string) $poolSite['git_token_id'] === $tokenId && $poolSite['github_token'] === null && $poolSite['version_path'] === '', 'project references saved token without copying secret');
    $projectEdit = request('/sites/' . $projectId . '/edit');
    verify(preg_match('/<option value="' . $tokenId . '" selected/', $projectEdit['body']) === 1
        && !str_contains($projectEdit['body'], $credential['encrypted_token']), 'edit preserves token selection without secret');
    verify(request('/sites/' . $projectId . '/check', ['_csrf' => $csrf])['status'] === 303, 'manual check accepts project without version endpoint');
    $state = $db->query("SELECT * FROM sites WHERE id = $projectId")->fetch(PDO::FETCH_ASSOC);
    verify($state['latest_commit'] === str_repeat('a', 40) && $state['deployed_version'] === null
        && !str_contains($state['last_error'] ?? '', 'Version:'), 'optional version check skipped while Git metrics still update');
    verify(request('/settings/tokens/' . $tokenId . '/delete')['status'] === 200
        && $db->query('SELECT COUNT(*) FROM git_tokens')->fetchColumn() === 1, 'token GET delete only confirms');
    verify(request('/settings/tokens/' . $tokenId . '/delete', ['_csrf' => $csrf])['status'] === 422
        && $db->query('SELECT COUNT(*) FROM git_tokens')->fetchColumn() === 1, 'used token cannot be deleted');
    verify(request('/settings/tokens/' . $tokenId . '/edit', array_replace($shared, ['name' => 'Renamed', 'token' => '']))['status'] === 303
        && $db->query('SELECT encrypted_token FROM git_tokens')->fetchColumn() === $credential['encrypted_token'], 'blank shared token preserves secret');
    $invalidToken = request('/settings/tokens/' . $tokenId . '/edit', array_replace($shared, ['token' => "invalid\r\nfixture-secret"]));
    verify($invalidToken['status'] === 422 && !str_contains($invalidToken['body'], 'fixture-secret')
        && $db->query('SELECT encrypted_token FROM git_tokens')->fetchColumn() === $credential['encrypted_token'], 'invalid token value neither reflects nor overwrites secret');
    verify(request('/settings/tokens/' . $tokenId . '/edit', array_replace($shared, ['token' => 'replacement-token']))['status'] === 303
        && $db->query("SELECT checked_at FROM sites WHERE id = $projectId")->fetchColumn() === null, 'shared token rotation invalidates project state');
    verify(request('/sites/branches', array_replace($selectedLookup, ['site_id' => $projectId]))['status'] === 200, 'rotated token used for branches');
    verify(request('/sites/' . $projectId . '/edit', array_replace($sharedSite, ['git_token_id' => '', 'github_token' => 'fixture-token']))['status'] === 303, 'project can switch to manually entered token');
    verify(request('/settings/tokens/' . $tokenId . '/delete', ['_csrf' => 'wrong'])['status'] === 419, 'token delete requires CSRF');
    verify(request('/settings/tokens/' . $tokenId . '/delete', ['_csrf' => $csrf])['status'] === 303
        && $db->query('SELECT COUNT(*) FROM git_tokens')->fetchColumn() === 0
        && $db->query("SELECT COUNT(*) FROM sites WHERE id = $projectId")->fetchColumn() === 1, 'unused shared token deletes without removing project');
    unset($db);
    verify(request('/logout', ['_csrf' => $csrf])['status'] === 303, 'logout succeeds');
    $anonymous = request('/');
    verify($anonymous['status'] === 303 && str_contains($anonymous['headers'], 'Location: /login'), 'dashboard requires login');
    verify(request('/sites/branches', $lookup)['status'] === 303, 'branch loading requires login');
    verify(request('/settings')['status'] === 303 && request('/settings/tokens/new', $shared)['status'] === 303, 'token settings require login');
    $login = request('/login');
    verify($login['status'] === 200 && str_contains($login['body'], 'Войти в Tablo'), 'login page renders');
    verify(request('/login', ['_csrf' => token($login), 'password' => 'wrong'])['status'] === 422, 'wrong password refused');
    verify(request('/login', ['_csrf' => token($login), 'password' => 'fixture-password'])['status'] === 303, 'existing admin can log in');
    verify(request('/setup')['status'] === 303, 'public setup closes after first admin');
    verify(request('/storage/tablo.sqlite')['status'] === 404 && request('/vendor/autoload.php')['status'] === 404, 'storage and source inaccessible through web root');
    echo "HTTP flow passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n" . file_get_contents($temporary . '/server.log'));
    $failed = true;
} finally {
    proc_terminate($process);
    if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); }
    proc_close($process);
    foreach (glob($temporary . '/*') as $file) { unlink($file); }
    rmdir($temporary);
}
exit(isset($failed) ? 1 : 0);
