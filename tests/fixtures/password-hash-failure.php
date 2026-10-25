<?php
declare(strict_types=1);

namespace Tablo {
    require_once dirname(__DIR__, levels: 2) . '/app/RuntimeIni.php';
    // Share the one explicit shim definition so static analysis sees no duplicate.
    require_once __DIR__ . '/password-provider-failure.php';
    putenv('TABLO_TEST_PROVIDER_CASE=hash-throw');
}
namespace {
    require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';
    \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
    $db = \Tablo\Database::connect(':memory:');
    $auth = new \Tablo\Auth($db);
    \Tablo\SqlStatement::checked($db->prepare('INSERT INTO users(id,password_hash) VALUES(1,?)'))
        ->execute([\password_hash('synthetic-private-password', PASSWORD_BCRYPT, ['cost' => 4])]);
    foreach ([fn () => \Tablo\PasswordService::hash('synthetic-private-password', 'synthetic-private-password'),
        fn () => $auth->setup('synthetic-private-password', 'synthetic-private-password'),
        fn () => $auth->setupSession('synthetic-private-password', 'synthetic-private-password'),
        fn () => $auth->changePassword('synthetic-private-password', 'synthetic-private-password', 'synthetic-private-marker'),
        fn () => $auth->login('synthetic-private-password', 'client'),
        fn () => $auth->loginSession('synthetic-private-password', 'client')] as $action) {
        try { $action(); exit(2); }
        catch (\Tablo\PasswordRuntimeFailure $error) {
            if ($error->getPrevious() !== null) { exit(5); }
            for ($current = $error; $current !== null; $current = $current->getPrevious()) {
                $complete = print_r($current, return: true) . print_r($current->getTrace(), return: true);
                if (str_contains($complete, 'synthetic-private') || str_contains($complete, 'provider-private-marker')) { exit(3); }
            }
        }
    }
    if (\Tablo\provider_hits('hash') !== 6) { exit(6); }
    echo "Complete hashing failure traces redacted.\n";
}
