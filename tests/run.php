<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Tablo\Auth;
use Tablo\Database;
use Tablo\GitHubProvider;
use Tablo\GitHubConnection;
use Tablo\HttpClient;
use Tablo\Presenter;
use Tablo\RepositoryProvider;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\ValidationException;
use Tablo\TokenVault;
use Tablo\GitTokenRepository;
use Tablo\GitProviders;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $action, string $message): void
{
    try {
        $action();
    } catch (ValidationException) {
        return;
    }
    throw new RuntimeException($message);
}

final class FakeHttp extends HttpClient
{
    public array $requests = [];
    public function __construct(private array $responses) {}
    public function get(string $url, array $headers = []): array
    {
        $this->requests[] = [$url, $headers];
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        if (!is_array($response)) {
            throw new RuntimeException('Unexpected HTTP request: ' . $url);
        }
        return $response;
    }
}

final class FakeProvider implements RepositoryProvider
{
    public bool $fail = false;
    public function getLatestRelease(string $repository): ?string
    {
        if ($this->fail) { throw new RuntimeException('rate limit'); }
        return 'v1.3.2';
    }
    public function getLatestCommit(string $repository, string $branch): string { return str_repeat('a', 40); }
    public function getOpenIssuesCount(string $repository): int { return 4; }
    public function getOpenPullRequestsCount(string $repository): int { return 1; }
}

function response(int $status = 200, string $body = '{}'): array
{
    return ['status' => $status, 'body' => $body, 'time_ms' => 87];
}

$tests = [];
$tests['one administrator, strong password, hashed credentials'] = function () {
    $db = Database::connect(':memory:');
    $auth = new Auth($db);
    expect($auth->needsSetup(), 'setup required');
    rejects(fn () => $auth->setup('short', 'short'), 'short password accepted');
    rejects(fn () => $auth->setup('fixture-password', 'different'), 'mismatch accepted');
    $auth->setup('fixture-password', 'fixture-password');
    expect(!$auth->needsSetup(), 'setup repeated');
    $hash = $db->query('SELECT password_hash FROM users')->fetchColumn();
    expect($hash !== 'fixture-password' && password_verify('fixture-password', $hash), 'password not hashed');
    rejects(fn () => $auth->setup('another-password', 'another-password'), 'second administrator accepted');
    expect($auth->login('fixture-password', 'client'), 'valid login refused');
};
$tests['persistent rate limit and expiry'] = function () {
    $db = Database::connect(':memory:');
    $auth = new Auth($db);
    $auth->setup('fixture-password', 'fixture-password');
    for ($i = 0; $i < 5; ++$i) {
        expect(!(new Auth($db))->login('incorrect', 'client'), 'wrong password accepted');
    }
    rejects(fn () => (new Auth($db))->login('fixture-password', 'client'), 'rate limit bypassed');
    expect($auth->login('fixture-password', 'other-client'), 'independent client blocked');
    $db->exec('UPDATE login_limits SET window_start = 0');
    expect($auth->login('fixture-password', 'client'), 'rate limit never expires');
};
$sample = SiteRepository::defaults() + [];
$sample['name'] = 'Tempalog';
$sample['url'] = 'https://tempalog.example/';
$sample['repository'] = 'https://github.com/ichinya/tempalog.git';
$sample['version_path'] = '/version';
$tests['CRUD, repository normalization, ordering and check invalidation'] = function () use ($sample) {
    $db = Database::connect(':memory:');
    $sites = new SiteRepository($db);
    $id = $sites->save($sample);
    $site = $sites->find($id);
    expect($site['repository'] === 'ichinya/tempalog' && $site['url'] === 'https://tempalog.example', 'normalization');
    $state = ['online' => 1, 'deployed_version' => '1.3.1', 'checked_at' => gmdate('c')];
    expect($sites->storeCheck($site, $state), 'check not saved');
    $changed = $sample;
    $changed['url'] = 'https://other.example';
    $changed['sort_order'] = 4;
    $sites->save($changed, $id);
    expect($sites->find($id)['checked_at'] === null, 'stale check survived edit');
    expect(!$sites->storeCheck($site, $state), 'old in-flight check overwrote edit');
    $first = $sites->save($sample);
    expect($sites->all()[0]['id'] === $first, 'sort order ignored');
    $sites->delete($id);
    expect($sites->find($id) === null && count($sites->all()) === 1, 'delete removed wrong record');
};
$tests['invalid and malicious site inputs never persist'] = function () use ($sample) {
    foreach ([['url' => 'file:///etc/passwd'], ['url' => 'https://user:pass@example.com'],
        ['url' => 'https://example.com/#test'], ['repository' => 'https://evil.example/a/b'],
        ['repository' => 'owner/..'], ['health_path' => '//evil.example/up'], ['health_path' => '/a\\b'],
        ['version_path' => '/version#other'], ['branch' => "main\nother"], ['comparison_mode' => 'evil'],
        ['sort_order' => '3.5'], ['name' => ['array']]] as $bad) {
        rejects(fn () => SiteRepository::normalize(array_replace($sample, $bad)), 'invalid input accepted: ' . json_encode($bad));
    }
};
$tests['health, JSON version and independent GitHub metrics'] = function () use ($sample) {
    $site = SiteRepository::normalize($sample);
    $http = new FakeHttp([response(), response(200, '{"version":"1.3.1","commit":"a61de82"}')]);
    $state = (new SiteChecker($http, new FakeProvider()))->check($site);
    expect($state['online'] === 1 && $state['response_time_ms'] === 87 && $state['last_error'] === null, 'health outcome');
    expect($state['open_issues'] === 4 && $state['open_prs'] === 1, 'issue/pr counts mixed');
    expect($state['deployed_commit'] === 'a61de82', 'commit ignored');
};
$tests['partial failures remain unknown without losing valid health'] = function () use ($sample) {
    $provider = new FakeProvider();
    $provider->fail = true;
    $http = new FakeHttp([response(), response(200, 'not json')]);
    $state = (new SiteChecker($http, $provider))->check(SiteRepository::normalize($sample));
    expect($state['online'] === 1 && $state['latest_release'] === null && $state['deployed_version'] === null, 'partial outcome fabricated');
    expect($state['open_issues'] === 4 && str_contains($state['last_error'], 'rate limit'), 'partial metadata lost');
};
$tests['HTTP errors offline, transport errors unknown, malformed SHA rejected'] = function () use ($sample) {
    $site = SiteRepository::normalize($sample);
    $state = (new SiteChecker(new FakeHttp([response(503), response(200, '{"commit":"abc"}')]), new FakeProvider()))->check($site);
    expect($state['online'] === 0 && $state['deployed_commit'] === null, 'invalid outcome');
    $state = (new SiteChecker(new FakeHttp([new RuntimeException('timeout'), response(404)]), new FakeProvider()))->check($site);
    expect($state['online'] === null && $state['response_time_ms'] === null, 'timeout claimed offline');
};
$tests['GitHub search separates Issues and PR, encodes branch, protects token'] = function () {
    $http = new FakeHttp([response(200, '{"total_count":4,"incomplete_results":false}'),
        response(200, '{"total_count":1,"incomplete_results":false}'), response(200, '{"name":"feature/topic","commit":{"sha":"' . str_repeat('a', 40) . '"}}')]);
    $provider = new GitHubProvider($http, 'fixture-token');
    expect($provider->getOpenIssuesCount('owner/repo') === 4 && $provider->getOpenPullRequestsCount('owner/repo') === 1, 'counts');
    $provider->getLatestCommit('owner/repo', 'feature/topic');
    expect(str_contains($http->requests[0][0], 'is%3Aissue') && str_contains($http->requests[1][0], 'is%3Apr'), 'wrong search');
    expect(str_ends_with($http->requests[2][0], '/branches/feature%2Ftopic'), 'branch not encoded or resolved as a tag');
    foreach ($http->requests as [$url, $headers]) {
        expect(str_starts_with($url, 'https://api.github.com/'), 'token sent to arbitrary host');
        expect(in_array('Authorization: Bearer fixture-token', $headers, true), 'token missing');
    }
};
$tests['GitHub missing releases and incomplete search do not invent data'] = function () {
    $provider = new GitHubProvider(new FakeHttp([response(404), response(200, '{}')]));
    expect($provider->getLatestRelease('owner/repo') === null, 'no releases not represented');
    try {
        (new GitHubProvider(new FakeHttp([response(200, '{"total_count":1,"incomplete_results":true}')])))->getOpenIssuesCount('owner/repo');
        throw new LogicException('incomplete search accepted');
    } catch (RuntimeException $e) {
        expect(!($e instanceof LogicException), 'incomplete search accepted');
    }
};
$tests['SSRF rejects local, mapped IPv6 and unsupported schemes'] = function () {
    foreach (['http://127.0.0.1/up', 'http://10.0.0.1/up', 'http://[::1]/up', 'http://[::ffff:127.0.0.1]/up', 'file:///etc/passwd'] as $url) {
        try {
            (new HttpClient())->get($url);
            throw new LogicException('local request accepted');
        } catch (RuntimeException $e) {
            expect(!($e instanceof LogicException), 'local request accepted');
        }
    }
};
$tests['release and commit comparisons, unknown, paused and stale states'] = function () use ($sample) {
    $db = Database::connect(':memory:');
    $sites = new SiteRepository($db);
    $id = $sites->save($sample);
    $site = $sites->find($id);
    expect(Presenter::site($site)['comparison_tone'] === 'muted', 'unknown shown current');
    $site['deployed_version'] = '1.3.2'; $site['latest_release'] = 'v1.3.2';
    expect(Presenter::site($site)['comparison_tone'] === 'green', 'v prefix not normalized');
    $site['deployed_version'] = '1.3.1';
    expect(Presenter::site($site)['comparison'] === 'Доступно обновление', 'older release');
    $site['deployed_version'] = '1.4.0';
    expect(Presenter::site($site)['comparison'] === 'Версия новее релиза', 'newer shown update');
    $site['comparison_mode'] = 'branch'; $site['deployed_commit'] = 'a61de82'; $site['latest_commit'] = 'a61de82' . str_repeat('0', 33);
    expect(Presenter::site($site)['comparison_tone'] === 'green', 'short SHA not accepted');
    expect(Presenter::site($site)['deployed_label'] === 'a61de82', 'branch comparison displayed a release instead of SHA');
    $site['enabled'] = 0;
    expect(Presenter::site($site)['status'] === 'На паузе', 'disabled not paused');
    $site['enabled'] = 1; $site['checked_at'] = gmdate('c', time() - 1800);
    expect(Presenter::site($site)['stale'], 'stale data not marked');
};

$tests['encrypted token lifecycle, per-site isolation and stale token guard'] = function () use ($sample) {
    $directory = sys_get_temp_dir() . '/tablo-token-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $key = $directory . '/key';
        $vault = new TokenVault($key);
        $sites = new SiteRepository(Database::connect(':memory:'), $vault);
        $id = $sites->save($sample + ['github_token' => 'fixture-token']);
        $site = $sites->find($id);
        expect(str_starts_with($site['github_token'], 'v1:') && !str_contains($site['github_token'], 'fixture-token'), 'plaintext stored');
        expect($sites->tokenFor($site) === 'fixture-token' && filesize($key) === 32, 'token not restored');
        $other = $sites->save(array_replace($sample, ['name' => 'Second', 'github_token' => 'other-token']));
        expect($sites->tokenFor($sites->find($other)) === 'other-token' && $sites->tokenFor($site) === 'fixture-token', 'tokens crossed sites');
        expect($sites->resolveToken([], $site) === 'fixture-token', 'saved site token not used');
        expect($sites->resolveToken(['github_token' => 'new-token', 'remove_github_token' => '1'], $site) === 'new-token', 'replacement lost');
        expect($sites->resolveToken(['remove_github_token' => '1'], $site) === '', 'removed token was reused');
        expect($sites->resolveToken([], null) === '', 'credentials invented for a tokenless project');
        $sites->save($sample, $id);
        expect($sites->find($id)['github_token'] === $site['github_token'], 'empty token erased saved token');
        $sites->save($sample + ['github_token' => 'replacement-token'], $id);
        expect(!$sites->storeCheck($site, ['online' => 1]), 'old check survived token-only replacement');
        expect($sites->tokenFor($sites->find($id)) === 'replacement-token', 'new token lost');
        $sites->save($sample + ['remove_github_token' => '1'], $id);
        expect($sites->find($id)['github_token'] === null, 'token not deleted');
        foreach (["token\r\nInjected: value", 'a b', str_repeat('a', 513), ['array']] as $bad) {
            rejects(fn () => $sites->save($sample + ['github_token' => $bad]), 'unsafe token accepted');
        }
        $cipher = $vault->encrypt('tamper-fixture');
        $bytes = base64_decode(substr($cipher, 3));
        $bytes[28] = chr(ord($bytes[28]) ^ 1);
        try {
            $vault->decrypt('v1:' . base64_encode($bytes));
            throw new LogicException('tampered token accepted');
        } catch (RuntimeException $e) { expect(!($e instanceof LogicException), 'tampered token accepted'); }
        unlink($key);
        try {
            $vault->decrypt($cipher);
            throw new LogicException('missing key regenerated silently');
        } catch (RuntimeException $e) { expect(!($e instanceof LogicException) && !is_file($key), 'missing key regenerated silently'); }
    } finally {
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
};
$tests['existing SQLite migration preserves administrator and sites, is repeatable'] = function () {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    $db->exec(preg_replace('/^\s*github_token TEXT,\R/m', '', $schema));
    (new Auth($db))->setup('fixture-password', 'fixture-password');
    $db->exec("INSERT INTO sites (name,url,repository,branch) VALUES ('Existing','https://example.com','owner/repo','main')");
    $hash = $db->query('SELECT password_hash FROM users')->fetchColumn();
    Database::migrate($db);
    Database::migrate($db);
    expect($db->query('SELECT COUNT(*) FROM sites')->fetchColumn() === 1 && $db->query('SELECT github_token FROM sites')->fetchColumn() === null, 'sites lost during migration');
    expect($db->query('SELECT password_hash FROM users')->fetchColumn() === $hash, 'administrator changed during migration');
};
$tests['GitHub branch pagination, default branch and slash branch authentication'] = function () {
    $page = array_map(fn ($i) => ['name' => 'branch-' . $i, 'commit' => ['sha' => str_repeat('a', 40)]], range(1, 100));
    $http = new FakeHttp([response(200, '{"default_branch":"develop"}'), response(200, json_encode($page)),
        response(200, '[{"name":"develop","commit":{"sha":"' . str_repeat('b', 40) . '"}}]'),
        response(200, '{"name":"feature/topic","commit":{"sha":"' . str_repeat('a', 40) . '"}}')]);
    $provider = new GitHubProvider($http, 'fixture-token');
    $result = $provider->getBranches('owner/repo');
    expect(count($result['branches']) === 101 && $result['default_branch'] === 'develop', 'pagination truncated');
    expect(str_ends_with($http->requests[2][0], '?per_page=100&page=2'), 'second page missing');
    $provider->requireBranch('owner/repo', 'feature/topic');
    expect(str_ends_with($http->requests[3][0], '/branches/feature%2Ftopic'), 'branch treated as commit/ref or slash unencoded');
    foreach ($http->requests as [$url, $headers]) {
        expect(in_array('Authorization: Bearer fixture-token', $headers, true) && !str_contains($url, 'fixture-token'), 'branch auth missing or token in URL');
    }
    $numeric = new GitHubProvider(new FakeHttp([response(200, '{"default_branch":"123"}'),
        response(200, '[{"name":"123","commit":{"sha":"' . str_repeat('a', 40) . '"}}]')]));
    expect($numeric->getBranches('owner/repo')['branches'] === ['123'], 'numeric branch changed JSON type');
};
$tests['branch loading rejects errors, empty, malformed and oversized results without secret reflection'] = function () {
    $full = array_map(fn ($i) => ['name' => 'branch-' . $i, 'commit' => ['sha' => str_repeat('a', 40)]], range(1, 100));
    $cases = [
        [response(401, '{"message":"fixture-secret"}')],
        [response(403, '{"message":"fixture-secret"}')],
        [response(404, '{"message":"fixture-secret"}')],
        [response(200, '{"default_branch":"main"}'), response(200, '[]')],
        [response(200, '{"default_branch":"main"}'), response(200, '{"name":"main"}')],
        [response(200, '{"default_branch":"main"}'), response(200, '[{"name":"main","commit":{"sha":"bad"}}]')],
        [response(200, '{"default_branch":"main"}'), new RuntimeException('timeout')],
        [response(200, '{"default_branch":"main"}'), ...array_fill(0, 10, response(200, json_encode($full)))],
    ];
    foreach ($cases as $responses) {
        try {
            (new GitHubProvider(new FakeHttp($responses), 'fixture-secret'))->getBranches('owner/repo');
            throw new LogicException('bad branch response accepted');
        } catch (RuntimeException $e) { expect(!($e instanceof LogicException) && !str_contains($e->getMessage(), 'fixture-secret'), 'bad branch response accepted or secret leaked'); }
    }
};
$tests['connection uses stored credentials and checks branch before accepting form'] = function () use ($sample) {
    $directory = sys_get_temp_dir() . '/tablo-connection-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $sites = new SiteRepository(Database::connect(':memory:'), new TokenVault($directory . '/key'));
        $id = $sites->save($sample + ['github_token' => 'fixture-token']);
        $site = $sites->find($id);
        $http = new FakeHttp([response(200, '{"default_branch":"main"}'),
            response(200, '[{"name":"main","commit":{"sha":"' . str_repeat('a', 40) . '"}}]'),
            response(200, '{"name":"main","commit":{"sha":"' . str_repeat('a', 40) . '"}}'), response(404)]);
        $connection = new GitHubConnection($sites, $http);
        $connection->branches(['repository' => $sample['repository']], $site);
        $connection->validate($sample, $site);
        foreach ($http->requests as [$url, $headers]) { expect(in_array('Authorization: Bearer fixture-token', $headers, true), 'saved token not used'); }
        rejects(fn () => $connection->validate($sample + ['github_token' => 'replacement-token'], $site), 'missing branch accepted');
        expect($sites->find($id)['github_token'] === $site['github_token'], 'preview mutated site');
        rejects(fn () => $connection->branches(['repository' => 'owner/repo', 'github_token' => "a\r\nb"], $site), 'header injection accepted');
        expect(count($http->requests) === 4, 'invalid credentials reached network');
    } finally {
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
};

$tests['saved Git tokens are reusable, secret, editable and guarded while in use'] = function () use ($sample) {
    $directory = sys_get_temp_dir() . '/tablo-saved-token-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $db = Database::connect(':memory:');
        $vault = new TokenVault($directory . '/key');
        $tokens = new GitTokenRepository($db, $vault);
        $sites = new SiteRepository($db, $vault);
        $input = ['name' => 'Shared token', 'provider' => 'github', 'token' => 'fixture-token'];
        $id = $tokens->save($input);
        $cipher = $db->query('SELECT encrypted_token FROM git_tokens')->fetchColumn();
        expect(str_starts_with($cipher, 'v1:') && $cipher !== 'fixture-token', 'pool token stored in plaintext');
        expect(!str_contains(json_encode($tokens->all()), 'fixture-token') && !str_contains(json_encode($tokens->find($id)), $cipher), 'pool metadata exposed token');
        rejects(fn () => $tokens->save(array_replace($input, ['name' => 'SHARED TOKEN'])), 'duplicate name accepted');
        rejects(fn () => $tokens->save(array_replace($input, ['name' => 'Another', 'token' => ''])), 'empty new token accepted');
        rejects(fn () => $tokens->save(array_replace($input, ['provider' => 'gitlab'])), 'unimplemented provider accepted');
        expect(GitProviders::available() === ['github' => 'GitHub'], 'providers misrepresented');
        $poolSite = $sample + ['git_token_id' => (string) $id];
        $first = $sites->save($poolSite);
        $second = $sites->save(array_replace($poolSite, ['name' => 'Second']));
        $site = $sites->find($first);
        expect($site['github_token'] === null && $site['git_token_id'] === $id, 'saved token copied instead of referenced');
        expect($sites->resolveToken([], $site) === 'fixture-token'
            && $sites->resolveToken(['git_token_id' => (string) $id], null) === 'fixture-token', 'saved token not resolved');
        expect($sites->resolveToken(['git_token_id' => ''], $site) === '', 'deselected pool token was reused');
        expect($tokens->all()[0]['site_count'] === 2, 'usage count wrong');
        rejects(fn () => $sites->resolveToken(['git_token_id' => (string) $id, 'github_token' => 'own-token'], null), 'ambiguous token accepted');
        rejects(fn () => $sites->save($sample + ['git_token_id' => '999']), 'missing pool token accepted');
        rejects(fn () => $sites->save($sample + ['git_token_id' => ['array']]), 'malformed pool id accepted');
        $db->prepare('INSERT INTO git_tokens (name, provider, encrypted_token) VALUES (?, ?, ?)')->execute(['Future provider', 'gitlab', $cipher]);
        $foreign = (string) $db->lastInsertId();
        rejects(fn () => $sites->resolveToken(['git_token_id' => $foreign], null), 'token from another provider accepted');
        $unsupportedHttp = new FakeHttp([]);
        rejects(fn () => (new GitHubConnection($sites, $unsupportedHttp))->provider(['provider' => 'gitlab', 'git_token_id' => (int) $foreign]), 'unsupported provider token could reach GitHub');
        expect($unsupportedHttp->requests === [], 'unsupported provider reached network');
        $tokens->save(array_replace($input, ['name' => 'Renamed', 'token' => '']), $id);
        expect($tokens->tokenFor($id, 'github') === 'fixture-token' && $sites->storeCheck($site, ['online' => 1, 'checked_at' => gmdate('c')]), 'metadata edit invalidated credential');
        $tokens->save(array_replace($input, ['name' => 'Renamed', 'token' => 'replacement-token']), $id);
        expect($sites->find($first)['checked_at'] === null && $sites->find($second)['checked_at'] === null, 'rotation did not invalidate checks');
        expect(!$sites->storeCheck($site, ['online' => 1]), 'in-flight check with old shared token survived');
        expect($sites->tokenFor($sites->find($first)) === 'replacement-token', 'rotation not used');
        rejects(fn () => $tokens->delete($id), 'used token deleted');
        $sites->save(array_replace($sample, ['git_token_id' => '', 'github_token' => 'own-token']), $first);
        expect($sites->find($first)['git_token_id'] === null && $sites->tokenFor($sites->find($first)) === 'own-token', 'switch to manual token failed');
        $sites->save($sample + ['git_token_id' => ''], $second);
        $tokens->delete($id);
        expect($tokens->find($id) === null && $sites->find($first) !== null && $sites->find($second) !== null, 'delete removed sites');
    } finally {
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
};
$tests['optional version makes no request, preserves health and Git data without false attention'] = function () use ($sample) {
    $input = array_replace($sample, ['version_path' => '']);
    expect(SiteRepository::normalize($input)['version_path'] === '', 'empty version rejected');
    unset($input['version_path']);
    expect(SiteRepository::normalize($input)['version_path'] === '', 'missing version rejected');
    $http = new FakeHttp([response()]);
    $site = SiteRepository::normalize($input);
    $state = (new SiteChecker($http, new FakeProvider()))->check($site);
    expect(count($http->requests) === 1 && $state['last_error'] === null && $state['online'] === 1, 'optional version requested or caused error');
    expect($state['deployed_version'] === null && $state['deployed_commit'] === null && $state['open_issues'] === 4, 'Git data lost or installed version fabricated');
    $presented = Presenter::site($site + $state);
    expect(!$presented['attention'] && !$presented['tracks_version'] && $presented['comparison'] === 'Версия сайта не отслеживается', 'unconfigured version flagged attention');
    rejects(fn () => SiteRepository::normalize(array_replace($sample, ['version_path' => '//evil.example'])), 'optional field accepts invalid nonempty value');
};
$tests['installed database migration retains per-project token, sites and admin'] = function () use ($sample) {
    $directory = sys_get_temp_dir() . '/tablo-upgrade-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $path = $directory . '/test.sqlite';
        $vault = new TokenVault($directory . '/github-token.key');
        $cipher = $vault->encrypt('legacy-token');
        $old = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        $schema = preg_replace('/CREATE TABLE IF NOT EXISTS git_tokens \(.*?\);\s*/s', '', $schema);
        $schema = preg_replace('/^\s*git_token_id INTEGER[^\r\n]*\R/m', '', $schema);
        $old->exec($schema);
        (new Auth($old))->setup('fixture-password', 'fixture-password');
        $hash = $old->query('SELECT password_hash FROM users')->fetchColumn();
        $old->prepare('INSERT INTO sites (name, url, repository, github_token, version_path) VALUES (?, ?, ?, ?, ?)')
            ->execute(['Existing', 'https://example.com', 'owner/repo', $cipher, '/version']);
        unset($old);
        $db = Database::connect($path);
        Database::migrate($db);
        $sites = new SiteRepository($db);
        $site = $sites->find(1);
        expect($site['github_token'] === $cipher && $site['git_token_id'] === null && $site['version_path'] === '/version', 'upgrade changed old configuration');
        expect($sites->tokenFor($site) === 'legacy-token' && $db->query('SELECT password_hash FROM users')->fetchColumn() === $hash, 'upgrade lost credentials');
        $tokens = new GitTokenRepository($db);
        expect($tokens->all() === [], 'upgrade invented shared tokens');
        $id = $tokens->save(['name' => 'New shared', 'provider' => 'github', 'token' => 'new-token']);
        expect($tokens->tokenFor($id, 'github') === 'new-token' && $sites->tokenFor($site) === 'legacy-token', 'vault key changed on upgrade');
        unset($sites, $tokens, $db);
    } finally {
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $e) {
        ++$failed;
        echo "FAIL $name: " . $e->getMessage() . "\n";
    }
}
echo count($tests) . ' tests, ' . $failed . " failures\n";
exit($failed ? 1 : 0);
