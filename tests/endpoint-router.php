<?php
declare(strict_types=1);

switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    case '/up':
        header('Content-Type: application/json');
        echo '{"status":"ok"}';
        break;
    case '/version':
        header('Content-Type: application/json');
        echo '{"version":"1.3.1","commit":"a61de82"}';
        break;
    case '/redirect':
        header('Location: /up', true, 302);
        break;
    case '/large':
        echo str_repeat('x', 1048577);
        break;
    case '/slow':
        sleep(10);
        echo 'late';
        break;
    default:
        http_response_code(404);
}
