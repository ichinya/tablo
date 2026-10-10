<?php
declare(strict_types=1);

// Project-local physical dependencies of genuine declared commands, not Lekalo owners.
function workerDependencies(): array
{
    return [
        'app/bootstrap.php' => ['owner' => 'php-script:app/bootstrap.php', 'consumers' => ['Application environment selection', 'Local administrator password CLI']],
        'bin/check.php' => ['owner' => 'php-script:bin/check.php', 'consumers' => ['dashboard.check_site']],
        'bin/key-preflight.php' => ['owner' => 'php-script:bin/key-preflight.php', 'consumers' => ['External key operator preflight']],
        'public/index.php' => ['owner' => 'php-script:public/index.php', 'consumers' => ['Application HTTP safe initialization']],
        'compose.yaml' => ['owner' => 'compose-service:tablo and tablo-worker', 'consumers' => ['Application and worker runtime selection']],
        'compose.secret.yaml' => ['owner' => 'compose-secret:tablo_token_key', 'consumers' => ['Application and worker external key selection']],
        'tools/capture-reviewed-dependencies.php' => ['owner' => 'php-script:tools/capture-reviewed-dependencies.php', 'consumers' => ['Explicit reviewed dependency capture']],
        'app/password-service.php' => ['owner' => 'Tablo\\PasswordService', 'consumers' => ['Tablo\Auth setup and password change']],
        'app/admin-password-command.php' => ['owner' => 'Tablo\\AdminPasswordCommand', 'consumers' => ['Local administrator password CLI']],
        'bin/admin-password.php' => ['owner' => 'Local CLI bootstrap and safe error boundary', 'consumers' => ['Local administrator password CLI']],
        'app/installation-exception.php' => ['owner' => 'Tablo\\InstallationException', 'consumers' => ['Existing installation refusal and CLI exit']],
        'app/password-conflict.php' => ['owner' => 'Tablo\\PasswordConflict', 'consumers' => ['Snapshot conflict and CLI exit']],
        'tools/admin-password.bash' => ['owner' => 'Bash protected input helper', 'consumers' => ['Local administrator password CLI stdin']],
        'tools/admin-password.ps1' => ['owner' => 'PowerShell protected input helper', 'consumers' => ['Local or Docker administrator password CLI stdin']],
        'app/client-address.php' => ['owner' => 'Tablo\\ClientAddress', 'consumers' => ['Tablo\\Web::__construct']],
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
        'app/incident-presenter.php' => ['owner' => 'Tablo\IncidentPresenter', 'consumers' => ['dashboard.read_incidents']],
        'app/incident-repository.php' => ['owner' => 'Tablo\IncidentRepository', 'consumers' => ['dashboard.read_incidents']],
        'app/incident-cursor.php' => ['owner' => 'Tablo\IncidentCursor', 'consumers' => ['dashboard.read_incidents']],
        'app/site-repository.php' => ['owner' => 'Tablo\SiteRepository', 'consumers' => ['dashboard.store_check', 'dashboard.settle_worker_check']],
        'app/web-application.php' => ['owner' => 'Tablo\Web', 'consumers' => ['dashboard.read_incidents']],
        'views/incidents.volt' => ['owner' => 'volt-template:views/incidents.volt', 'consumers' => ['dashboard.read_incidents']],
        'views/layout.volt' => ['owner' => 'volt-template:views/layout.volt', 'consumers' => ['dashboard.read_incidents']],
        'public/assets/app.css' => ['owner' => 'asset:public/assets/app.css', 'consumers' => ['dashboard.read_incidents']],
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
    if (!is_file($path)) { throw new RuntimeException('Missing project-local reviewed dependencies. Explicit capture required.'); }
    try { $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new RuntimeException('Malformed project-local reviewed dependencies.'); }
    if (!is_array($manifest) || array_keys($manifest) !== ['schema', 'dependencies']
        || $manifest['schema'] !== 'tablo/reviewed-dependencies/v1' || !is_array($manifest['dependencies'])
        || !array_is_list($manifest['dependencies']) || count($manifest['dependencies']) !== count(workerDependencies())) {
        throw new RuntimeException('Invalid project-local reviewed dependencies manifest.');
    }
    foreach (array_keys(workerDependencies()) as $index => $path) {
        $entry = $manifest['dependencies'][$index];
        $metadata = workerDependencies()[$path];
        if (!is_array($entry) || array_keys($entry) !== ['path', 'owner', 'consumers', 'fingerprint']
            || $entry['path'] !== $path || $entry['owner'] !== $metadata['owner'] || $entry['consumers'] !== $metadata['consumers']
            || !is_string($entry['fingerprint']) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $entry['fingerprint'])) {
            throw new RuntimeException('Invalid project-local reviewed dependency metadata: ' . $path);
        }
        if (!hash_equals($entry['fingerprint'], dependencyHash($root, $path))) {
            throw new RuntimeException('Unreviewed dependency source drift: ' . $path . '. Review source and explicitly capture bindings.');
        }
    }
}

function requiredReviewedDependencies(): array { return workerDependencies(); }
function checkReviewedDependencies(string $root): void { verifyWorkerDependencies($root); }
