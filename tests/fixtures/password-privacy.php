<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
ini_set('zend.exception_ignore_args', '0');
$directory = new Tablo\Tests\Support\TemporaryDirectory('tablo-password-trace-');
$db = $auth = $caught = null;
$checks = 0;
try {
    $db = Tablo\Database::connect($directory->path . '/fixture.sqlite');
    $auth = new Tablo\Auth($db);
    $auth->setup('fixture-private-password', 'fixture-private-password');
    $fingerprint = $auth->credentialFingerprint();
    $hash = $db->query('SELECT password_hash FROM users')->fetchColumn();
    $db->exec("CREATE TRIGGER block_update BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'private-trigger-text'); END");
    $actions = [
        fn () => Tablo\PasswordService::hash('fixture-private-password', 'fixture-private-confirmation'),
        fn () => Tablo\PasswordService::hash("fixture-private\0password", "fixture-private\0password"),
        fn () => $auth->setup('fixture-private-password', 'fixture-private-password'),
        fn () => $auth->setupSession('fixture-private-password', 'fixture-private-password'),
        fn () => $auth->changePassword('fixture-new-private-password', 'fixture-new-private-password', $fingerprint),
        fn () => Tablo\Database::openExisting('file:' . $directory->path . '/fixture.sqlite?mode=fixture-private-confirmation'),
    ];
    for ($i = 0; $i < 5; $i++) { $auth->login('wrong', 'blocked'); }
    $actions[] = fn () => $auth->login('fixture-private-password', 'blocked');
    $actions[] = fn () => $auth->loginSession('fixture-private-password', 'blocked');
    foreach ($actions as $action) {
        try { $action(); } catch (Throwable $error) { $caught = $error; }
        if ($caught === null) { throw new RuntimeException('No actual privacy exception'); }
        $complete = '';
        for ($error = $caught; $error !== null; $error = $error->getPrevious()) {
            $complete .= print_r($error, true) . print_r($error->getTrace(), true);
        }
        foreach (['fixture-private-password', 'fixture-private-confirmation', 'fixture-new-private-password',
            'private-trigger-text', $fingerprint, $hash] as $secret) {
            if (str_contains($complete, $secret)) { throw new RuntimeException('Complete trace privacy failed'); }
            ++$checks;
        }
        $caught = null;
    }
    foreach ([['setup', [0, 1]], ['setupSession', [0, 1]], ['login', [0]], ['loginSession', [0]], ['changePassword', [0, 1, 2]]] as [$method, $positions]) {
        $parameters = (new ReflectionMethod(Tablo\Auth::class, $method))->getParameters();
        foreach ($positions as $position) {
            if (count($parameters[$position]->getAttributes(SensitiveParameter::class)) !== 1) { throw new RuntimeException('Sensitive wrapper missing'); }
            ++$checks;
        }
    }
    foreach (['connect', 'openExisting', 'resolvePath', 'validateExistingUri', 'refuseExistingFailure'] as $method) {
        $parameter = (new ReflectionMethod(Tablo\Database::class, $method))->getParameters()[0];
        if (count($parameter->getAttributes(SensitiveParameter::class)) !== 1) { throw new RuntimeException('Sensitive connection wrapper missing'); }
        ++$checks;
    }
    echo 'Complete propagated/previous traces redacted; ' . $checks . " checks.\n";
} finally {
    $actions = $action = $error = $caught = $auth = $db = null;
    $directory->close();
}
