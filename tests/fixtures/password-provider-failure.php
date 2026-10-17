<?php
declare(strict_types=1);

namespace Tablo {
    // Explicit namespace simulations. No result from this fixture proves provider absence.
    function provider_legacy_hash(?string $selected = null): string
    {
        static $hash = '';
        if ($selected !== null) { $hash = $selected; }
        return $hash;
    }
    function provider_hits(string $operation, int $increment = 0): int
    {
        /** @var array<string, int> $hits */
        static $hits = [];
        $hits[$operation] = ($hits[$operation] ?? 0) + $increment;
        $path = getenv('TABLO_TEST_PROVIDER_HITS');
        if ($path) { file_put_contents($path, json_encode($hits, JSON_THROW_ON_ERROR)); }
        return $hits[$operation];
    }
    function defined(string $name): bool
    {
        provider_hits('registration', increment: 1);
        return $name === 'PASSWORD_ARGON2ID' && getenv('TABLO_TEST_PROVIDER_CASE') === 'no-constant' ? false : \defined($name);
    }
    /** @return list<string> */
    function password_algos(): array
    {
        provider_hits('registration', increment: 1);
        return getenv('TABLO_TEST_PROVIDER_CASE') === 'unregistered'
            ? array_values(array_diff(\password_algos(), ['argon2id', 'argon2i'])) : \password_algos();
    }
    /** @param array{cost?: int, memory_cost?: int, time_cost?: int, threads?: int} $options */
    function password_hash(#[\SensitiveParameter] string $input, string|int|null $algorithm, #[\SensitiveParameter] array $options = []): string
    {
        provider_hits('hash', increment: 1);
        if ([$algorithm, $options] !== ['argon2id', ['memory_cost' => 65_536, 'time_cost' => 3, 'threads' => 1]]) { exit(20); }
        return match (getenv('TABLO_TEST_PROVIDER_CASE')) {
            'hash-throw' => throw new \ValueError('provider-private-marker ' . $input),
            'wrong-algorithm' => \password_hash($input, PASSWORD_BCRYPT, ['cost' => 4]),
            'wrong-options' => \password_hash($input, PASSWORD_ARGON2ID, ['memory_cost' => 32_768, 'time_cost' => 3, 'threads' => 1]),
            default => \password_hash($input, $algorithm, $options),
        };
    }
    function password_verify(#[\SensitiveParameter] string $input, #[\SensitiveParameter] string $hash): bool
    {
        provider_hits('verify', increment: 1);
        if (provider_hits('verify') === 1 && provider_legacy_hash() !== '' && $hash !== provider_legacy_hash()) { exit(33); }
        if (getenv('TABLO_TEST_PROVIDER_CASE') === 'verify-throw') { throw new \ValueError('provider-private-marker ' . $input . $hash); }
        if (getenv('TABLO_TEST_PROVIDER_CASE') === 'verify-false') { return false; }
        if (getenv('TABLO_TEST_PROVIDER_CASE') === 'result-false' && str_starts_with($hash, '$argon2id$')) { return false; }
        return \password_verify($input, $hash);
    }
}
namespace {
    if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) { return; }
    $case = getenv('TABLO_TEST_PROVIDER_CASE');
    if (is_string($case) && str_starts_with($case, 'cli-')) {
        putenv('TABLO_TEST_PROVIDER_CASE=' . substr($case, offset: 4));
        require dirname(__DIR__, levels: 2) . '/bin/admin-password.php';
        exit(32);
    }
    require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';
    \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
    $mode = getenv('TABLO_TEST_PROVIDER_CASE');
    $db = \Tablo\Database::connect(':memory:');
    $input = str_repeat('x', times: 72);
    $variant = getenv('TABLO_TEST_BCRYPT_VARIANT');
    $prefix = $variant === false ? '2y' : $variant;
    if (!in_array($prefix, ['2y', '2a', '2b'], strict: true)) { exit(34); }
    $old = '$' . $prefix . '$' . substr(\password_hash($input, PASSWORD_BCRYPT, ['cost' => 4]), offset: 4);
    \Tablo\provider_legacy_hash($old);
    if (!\password_verify($input, $old) || !\password_verify($input . 'suffix', $old)) { exit(35); }
    foreach ([$input . 'suffix', $input . "\0", str_repeat('x', times: 1025)] as $refused) {
        if (!\Tablo\PasswordService::verify($refused, $old)) { continue; }
        exit(36);
    }
    $over72VerifyHits = \Tablo\provider_hits('verify');
    if ($over72VerifyHits !== 0) { exit(37); }
    \Tablo\SqlStatement::checked($db->prepare('INSERT INTO users(id,password_hash) VALUES(1,?)'))->execute([$old]);
    $db->exec("INSERT INTO login_limits VALUES('expired-sentinel',3,0)");
    $before = \Tablo\Tests\Support\ReadApiAssertions::snapshot($db);
    $auth = new \Tablo\Auth($db); $fingerprint = $auth->credentialFingerprint();
    $failed = false;
    try {
        $selected = $auth->loginSession($input, 'provider-fixture');
        if ($mode === 'verify-false') {
            if ($selected !== null || \Tablo\provider_hits('hash') !== 0) { exit(21); }
            if ((int) \Tablo\SqlStatement::checked($db->query('SELECT failures FROM login_limits'))->fetchColumn() !== 1) { exit(22); }
        }
        if (in_array($mode, ['no-constant', 'unregistered'], strict: true)) {
            if ($selected !== $fingerprint || \Tablo\provider_hits('hash') !== 0) { exit(23); }
        }
        if (!in_array($mode, ['no-constant', 'unregistered', 'verify-false'], strict: true)) { exit(24); }
    } catch (\Tablo\PasswordRuntimeFailure $error) {
        $failed = true;
        if ($error->getPrevious() !== null || $error->getMessage() !== 'Password runtime unavailable.') { exit(25); }
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $trace = print_r($current, return: true) . print_r($current->getTrace(), return: true);
            foreach ([$input, $old, 'provider-private-marker'] as $marker) {
                if (!str_contains($trace, $marker)) { continue; }
                exit(26);
            }
        }
        if (\Tablo\Tests\Support\ReadApiAssertions::snapshot($db) !== $before) { exit(27); }
    }
    if (\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn() !== $old) { exit(28); }
    if (\Tablo\provider_hits('registration') === 0 || \Tablo\provider_hits('verify') === 0) { exit(29); }
    $expectedFailure = !in_array($mode, ['no-constant', 'unregistered', 'verify-false'], strict: true);
    if ($failed !== $expectedFailure) { exit(30); }
    if (in_array($mode, ['hash-throw', 'wrong-algorithm', 'wrong-options', 'result-false'], strict: true) && \Tablo\provider_hits('hash') !== 1) { exit(31); }
    echo json_encode(['classification' => 'simulation only', 'case' => $mode, 'hashHits' => \Tablo\provider_hits('hash'),
        'bcryptVariant' => $prefix, 'over72VerifyHits' => $over72VerifyHits,
        'verifyHits' => \Tablo\provider_hits('verify'), 'registrationHits' => \Tablo\provider_hits('registration'), 'safeFailure' => $failed,
        'credentialUnchanged' => true, 'rollbackSnapshotMatched' => $failed ? true : null], JSON_THROW_ON_ERROR) . "\n";
}
