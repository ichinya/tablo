<?php
declare(strict_types=1);

// Project-local dependencies, not Lekalo declaration owners or transport endpoints.
function requiredReviewedDependencies(): array
{
    return [
        'app/password-service.php' => ['owner' => 'Tablo\\PasswordService', 'consumer' => 'Tablo\\Auth setup and password change'],
        'app/admin-password-command.php' => ['owner' => 'Tablo\\AdminPasswordCommand', 'consumer' => 'Local administrator password CLI'],
        'bin/admin-password.php' => ['owner' => 'Local CLI bootstrap and safe error boundary', 'consumer' => 'Local administrator password CLI'],
        'app/installation-exception.php' => ['owner' => 'Tablo\\InstallationException', 'consumer' => 'Existing installation refusal and CLI exit'],
        'app/password-conflict.php' => ['owner' => 'Tablo\\PasswordConflict', 'consumer' => 'Snapshot conflict and CLI exit'],
        'tools/admin-password.bash' => ['owner' => 'Bash protected input helper', 'consumer' => 'Local administrator password CLI stdin'],
        'tools/admin-password.ps1' => ['owner' => 'PowerShell protected input helper', 'consumer' => 'Local or Docker administrator password CLI stdin'],
    ];
}

function checkReviewedDependencies(string $root): void
{
    $path = $root . '/contracts/reviewed-dependencies.json';
    if (!is_file($path)) {
        throw new RuntimeException('Missing project-local reviewed dependencies. Review sources and explicitly capture dependencies.');
    }
    try {
        $manifest = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Malformed project-local reviewed dependencies.');
    }
    $required = requiredReviewedDependencies();
    if (!is_array($manifest) || count($manifest) !== 2
        || ($manifest['scope'] ?? null) !== 'project-local-reviewed-dependencies'
        || !isset($manifest['files']) || !is_array($manifest['files']) || !array_is_list($manifest['files'])
        || count($manifest['files']) !== count($required)) {
        throw new RuntimeException('Invalid project-local reviewed dependency coverage.');
    }
    $seen = [];
    foreach ($manifest['files'] as $file) {
        if (!is_array($file) || count($file) !== 4 || !is_string($file['path'] ?? null)
            || !isset($required[$file['path']]) || isset($seen[$file['path']])
            || ($file['owner'] ?? null) !== $required[$file['path']]['owner']
            || ($file['consumer'] ?? null) !== $required[$file['path']]['consumer']
            || !is_string($file['fingerprint'] ?? null)
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $file['fingerprint']) !== 1) {
            throw new RuntimeException('Invalid project-local reviewed dependency entry.');
        }
        $seen[$file['path']] = true;
        $source = $root . '/' . $file['path'];
        if (!is_file($source) || !hash_equals($file['fingerprint'], 'sha256:' . hash_file('sha256', $source))) {
            throw new RuntimeException('Unreviewed dependency source drift: ' . $file['path']
                . '. Review sources and explicitly capture dependencies.');
        }
    }
}
