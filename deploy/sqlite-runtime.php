<?php
declare(strict_types=1);

// Docker build/probe gate only; operator-managed runtimes may use documented vendor backports.
// Expected identity: https://sqlite.org/releaselog/3_53_4.html
$db = new PDO('sqlite::memory:');
$identity = $db->query('SELECT sqlite_version(), sqlite_source_id()')->fetch(PDO::FETCH_NUM);
if ($identity !== ['3.53.4', '2026-07-24 19:02:57 bf7c7f30031888f4e796e429ab3978879485813aaca6f641c7b33e4e09459bcc']) {
    throw new RuntimeException('Docker PDO SQLite does not match the verified upstream release.');
}
echo json_encode(['php' => PHP_VERSION, 'sapi' => PHP_SAPI,
    'sqlite_version' => $identity[0], 'sqlite_source_id' => $identity[1]], JSON_THROW_ON_ERROR), PHP_EOL;
