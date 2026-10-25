<?php
declare(strict_types=1);

// Real setup GET initializes the isolated application, database and CSRF cookie.
// Reject only its setup POST so authentication fails after both servers exist.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_SERVER['REQUEST_URI'] ?? '') === '/setup') {
    error_log('Controlled dashboard authentication refusal');
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Controlled dashboard authentication refusal';
    return;
}
require dirname(__DIR__) . '/web-router.php';
