<?php
declare(strict_types=1);

// Project-local physical dependencies of genuine declared commands, not Lekalo owners.
function workerDependencies(): array
{
    return [
        'app/worker-lock.php' => ['owner' => 'Tablo\\WorkerLock', 'consumers' => ['dashboard.run_worker_pass']],
        'bin/worker.php' => ['owner' => 'php-script:bin/worker.php', 'consumers' => ['dashboard.run_worker_pass', 'dashboard.request_worker_stop']],
        'app/token-vault.php' => ['owner' => 'Tablo\\TokenVault', 'consumers' => ['dashboard.run_worker_pass']],
        'app/shared-key-failure.php' => ['owner' => 'Tablo\\SharedKeyFailure', 'consumers' => ['dashboard.run_worker_pass']],
        'app/github-provider.php' => ['owner' => 'Tablo\\GitHubProvider', 'consumers' => ['dashboard.run_worker_pass']],
        'app/github-request-policy.php' => ['owner' => 'Tablo\\GitHubRequestPolicy', 'consumers' => ['dashboard.run_worker_pass']],
        'tools/verify.php' => ['owner' => 'php-script:tools/verify.php', 'consumers' => ['dashboard.run_worker_pass']],
        'tools/capture-bindings.php' => ['owner' => 'php-script:tools/capture-bindings.php', 'consumers' => ['dashboard.run_worker_pass']],
        'tools/reviewed-dependencies.php' => ['owner' => 'php-script:tools/reviewed-dependencies.php', 'consumers' => ['dashboard.run_worker_pass']],
        'app/check-history-snapshot.php' => ['owner' => 'Tablo\\CheckHistorySnapshot', 'consumers' => ['dashboard.store_check', 'dashboard.settle_worker_check', 'dashboard.run_worker_pass', 'dashboard.check_site']],
        'app/history-time.php' => ['owner' => 'Tablo\\HistoryTime', 'consumers' => ['dashboard.store_check', 'dashboard.settle_worker_check', 'dashboard.read_history', 'dashboard.prune_history']],
        'app/history-retention.php' => ['owner' => 'Tablo\\HistoryRetention', 'consumers' => ['dashboard.prune_history']],
        'bin/history.php' => ['owner' => 'php-script:bin/history.php', 'consumers' => ['dashboard.read_history']],
        'bin/prune-history.php' => ['owner' => 'php-script:bin/prune-history.php', 'consumers' => ['dashboard.prune_history']],
    ];
}

function dependencyHash(string $root, string $path): string
{
    $expected = str_replace('\\', '/', realpath($root)) . '/' . $path;
    $actual = realpath($root . '/' . $path);
    if ($actual === false || str_replace('\\', '/', $actual) !== $expected || !is_file($actual)) {
        throw new RuntimeException('Invalid reviewed dependency path: ' . $path);
    }
    return 'sha256:' . hash_file('sha256', $actual);
}

function verifyWorkerDependencies(string $root): void
{
    $path = $root . '/contracts/reviewed-dependencies.json';
    if (!is_file($path)) { throw new RuntimeException('Missing reviewed dependencies. Explicit capture required.'); }
    $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || array_keys($manifest) !== ['schema', 'dependencies']
        || $manifest['schema'] !== 'tablo/reviewed-dependencies/v1' || !is_array($manifest['dependencies'])
        || !array_is_list($manifest['dependencies']) || count($manifest['dependencies']) !== count(workerDependencies())) {
        throw new RuntimeException('Invalid reviewed dependencies manifest.');
    }
    foreach (array_keys(workerDependencies()) as $index => $path) {
        $entry = $manifest['dependencies'][$index];
        $metadata = workerDependencies()[$path];
        if (!is_array($entry) || array_keys($entry) !== ['path', 'owner', 'consumers', 'fingerprint']
            || $entry['path'] !== $path || $entry['owner'] !== $metadata['owner'] || $entry['consumers'] !== $metadata['consumers']
            || !is_string($entry['fingerprint']) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $entry['fingerprint'])) {
            throw new RuntimeException('Invalid reviewed dependency metadata: ' . $path);
        }
        if (!hash_equals($entry['fingerprint'], dependencyHash($root, $path))) {
            throw new RuntimeException('Unreviewed dependency drift: ' . $path . '. Review source and explicitly capture bindings.');
        }
    }
}
