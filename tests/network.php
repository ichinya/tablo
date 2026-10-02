<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$log = tempnam(sys_get_temp_dir(), 'tablo-network-');
$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/endpoint-router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__));
$base = 'http://127.0.0.1:' . $port;

function checkNetwork(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS $message\n";
}

try {
    for ($i = 0; $i < 50; ++$i) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, .1);
        if ($connection) { fclose($connection); break; }
        usleep(100000);
    }
    $http = new Tablo\HttpClient(true);
    $provider = new class implements Tablo\RepositoryProvider {
        public function getLatestRelease(string $repository): ?string { return 'v1.3.2'; }
        public function getLatestCommit(string $repository, string $branch): string { return 'a61de82' . str_repeat('0', 33); }
        public function getOpenIssuesCount(string $repository): int { return 0; }
        public function getOpenPullRequestsCount(string $repository): int { return 0; }
    };
    $site = ['url' => $base, 'health_path' => '/up', 'version_path' => '/version', 'repository' => 'test/repo', 'branch' => 'main'];
    $state = (new Tablo\SiteChecker($http, $provider))->check($site);
    checkNetwork($state['online'] === 1 && $state['deployed_version'] === '1.3.1' && $state['deployed_commit'] === 'a61de82' && $state['last_error'] === null, 'actual cURL health and version endpoints');
    checkNetwork($http->get($base . '/redirect')['status'] === 302, 'redirect is not followed');
    foreach (['/large', '/slow'] as $path) {
        $start = microtime(true);
        $refused = false;
        try { $http->get($base . $path); } catch (RuntimeException) { $refused = true; }
        checkNetwork($refused, $path === '/large' ? 'response over 1 MB refused' : 'stalled endpoint times out');
        if ($path === '/slow') { checkNetwork(microtime(true) - $start < 8, 'HTTP timeout is bounded'); }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL ' . $e->getMessage() . "\n");
    $failed = true;
} finally {
    proc_terminate($process);
    fclose($pipes[0]);
    proc_close($process);
    unlink($log);
}
exit(isset($failed) ? 1 : 0);
