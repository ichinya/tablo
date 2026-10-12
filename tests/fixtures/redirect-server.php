<?php
declare(strict_types=1);

require dirname(__DIR__, levels: 2) . '/app/NativeWarning.php';

// Owned synthetic raw HTTP receiver; protocol framing is emitted, never parsed by product code.
$directory = $argv[1];
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) { throw new RuntimeException('Cannot allocate owned redirect peer'); }
$address = stream_socket_get_name($server, remote: false);
file_put_contents($directory . '/ready.tmp', $address);
rename($directory . '/ready.tmp', $directory . '/ready');
$deadline = microtime(true) + 120;
try {
    while (microtime(true) < $deadline && !is_file($directory . '/stop')) {
        $read = [$server]; $except = []; $write = [];
        if (stream_select($read, $write, $except, seconds: 0, microseconds: 100_000) !== 1) { continue; }
        $connection = stream_socket_accept($server, timeout: 0);
        if ($connection === false) { continue; }
        try {
            stream_set_timeout($connection, seconds: 3);
            $line = fgets($connection, length: 8192);
            $requestLine = rtrim($line ? $line : '', characters: "\r\n");
            $methodGet = str_starts_with($line ? $line : '', 'GET ');
            $uri = explode(' ', $line ? $line : '')[1] ?? '/';
            $requestedPath = parse_url($uri, PHP_URL_PATH);
            $path = $requestedPath ? $requestedPath : '/';
            parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);
            $authorization = ''; $unexpected = false;
            while (($line = fgets($connection, length: 8192)) !== false && $line !== "\r\n") {
                if (str_starts_with(strtolower($line), 'authorization:')) { $authorization = trim(substr($line, offset: 14)); }
                if (preg_match('/\A(?:cookie|referer|x-api-key|proxy-authorization):/i', $line)) { $unexpected = true; }
            }
            $routeSegment = explode('/', trim($path, characters: '/'))[0];
            $route = $routeSegment ? $routeSegment : 'root';
            file_put_contents($directory . '/requests.jsonl', json_encode(['route' => $route,
                'bearer' => $authorization === 'Bearer synthetic-redirect-token', 'authorized' => $authorization !== '',
                'unexpected_headers' => $unexpected, 'method_get' => $methodGet,
                ...($route === 'query' ? ['request_line' => $requestLine] : [])]) . "\n", FILE_APPEND);
            $status = 200; $location = null; $body = '{"ok":true,"version":"v-safe","commit":"a61de82"}';
            $extra = ''; $prefix = ''; $chunked = false;
            switch (true) {
case ($route === 'query'):

                $location = match ($uri) {
                    '/query/start-empty', '/query/step?' => '/query/resource?',
                    '/query/start-nonempty' => '/query/resource?x=',
                    '/query/start-absent?inherited=1' => '/query/resource',
                    '/query/query-only?old=1', '/query/step?replace=1' => '?',
                    '/query/query-nonempty?old=1' => '?x=',
                    '/query/later?inherited=1' => '/query/step?replace=1',
                    '/query/encoded-start' => '/query/%2E%2e/%2F%3F%23%25?value=%2f%3F%23%25&literal=+',
                    default => null,
                };
                $status = $location !== null ? 302 : match ($uri) {
                    '/query/resource?', '/query/query-only?' => 200,
                    '/query/resource?x=', '/query/query-nonempty?x=' => 201,
                    '/query/%2E%2e/%2F%3F%23%25?value=%2f%3F%23%25&literal=+' => 202,
                    default => 404,
                };
                $body = json_encode(['query_resource' => $status]);

    break;
case (preg_match('~^/status/(301|302|303|307|308|300|304|305)$~D', $path, $m)):
 $status = (int) $m[1]; $location = '/final';
    break;
case (preg_match('~^/chain/([0-9]+)$~D', $path, $m) && (int) $m[1] > 0):
 $status = 302; $location = '/chain/' . ((int) $m[1] - 1);
    break;
case (str_starts_with($path, '/cycle/')):
 $status = 302; $location = $path === '/cycle/a' ? '/cycle/b' : '/cycle/a';
    break;
case ($path === '/relative/start' && !(($query['done'] ?? null) !== null)):

                $status = 302;
                $location = match ($query['case'] ?? '') { 'root' => '/final', 'sibling' => 'final', 'dot' => '../final',
                    'query' => '?done=1', 'network' => '//' . $address . '/final', 'case' => 'HTTP://' . $address . '/final', default => './final' };

    break;
case (str_starts_with($path, '/cross/')):

                $status = 302;
                $location = match (substr($path, offset: 7)) { 'scheme' => 'https://' . $address . '/final',
                    'host' => 'http://localhost:' . parse_url('http://' . $address, PHP_URL_PORT) . '/final',
                    'port' => 'http://127.0.0.1:' . (int) ($query['port'] ?? 1) . '/final',
                    'private' => 'http://10.0.0.1/final', default => '/final' };

    break;
case (str_starts_with($path, '/header/')):

                $status = 302;
                switch (substr($path, offset: 8)) {
                    case 'missing': break;
                    case 'empty': $location = ''; break;
                    case 'duplicate': $location = '/final'; $extra = "Location: /final\r\n"; break;
                    case 'conflicting': $location = '/final'; $extra = "Location: /elsewhere\r\n"; break;
                    case 'folded': $extra = "Location: /final\r\n more\r\n"; break;
                    case 'control': $location = "/final\x01"; break;
                    case 'userinfo': $location = 'http://synthetic-redirect-token@' . $address . '/final'; break;
                    case 'fragment': $location = '/final#synthetic-redirect-token'; break;
                    case 'malformed': $location = '/bad%zz'; break;
                    case 'interim': $prefix = "HTTP/1.1 103 Early Hints\r\nX-Interim: discarded\r\n\r\n"; $location = '/final'; break;
                    case 'interim-identical': $prefix = "HTTP/1.1 103 Early Hints\r\nLocation: /final\r\n\r\n"; $location = '/final'; break;
                    case 'interim-conflicting': $prefix = "HTTP/1.1 103 Early Hints\r\nLocation: http://10.0.0.1/\r\n\r\n"; $location = '/final'; break;
                    case 'trailer': $status = 200; $chunked = true; break;
                }

    break;
case ($path === '/budget/body'):
 $status = 302; $location = '/budget/final'; $body = str_repeat('x', times: 600_000);
    break;
case ($path === '/budget/final'):
 $body = str_repeat('x', times: 600_000);
    break;
case ($path === '/budget/headers'):
 $status = 302; $location = '/budget/header-final'; $extra = 'X-Pad: ' . str_repeat('x', times: 40_000) . "\r\n";
    break;
case ($path === '/budget/header-final'):
 $extra = 'X-Pad: ' . str_repeat('x', times: 40_000) . "\r\n";
    break;
case ($path === '/budget/combined'):
 $body = str_repeat('x', times: 1_020_000); $extra = 'X-Pad: ' . str_repeat('x', times: 40_000) . "\r\n";
    break;
case ($path === '/budget/tiny-chunks'):
 $chunked = true; $body = str_repeat('x', times: 200_000);
    break;
case ($path === '/held/start'):
 // The client must expire while this response is held; close signals the owned stop latch.
 $holdUntil = hrtime(true) + 3_000_000_000;
 while (!is_file($directory . '/stop') && hrtime(true) < $holdUntil) { clearstatcache(); usleep(1_000); }
 $status = 302; $location = '/held/unreachable';
    break;
case ($path === '/delay/start'):
 usleep(100_000); $status = 302; $location = '/delay/final';
    break;
case ($path === '/delay/final'):
 usleep(200_000);
    break;
case ($path === '/final/false'):
 $body = '{"ok":false}';
    break;
case ($path === '/final/json-invalid'):
 $body = 'not-json';
    break;
case ($path === '/final/offline'):
 $status = 403;
    break;
case (str_starts_with($path, '/health/')):
 $status = 302; $location = '/final/' . substr($path, offset: 8);
    break;
case ($path === '/framing/start'):
 $status = 302; $location = '/framing/broken';
    break;
case ($path === '/framing/broken'):
 $chunked = true;
    break;
}
            $head = $prefix . 'HTTP/1.1 ' . $status . " Fixture\r\nConnection: close\r\n" . $extra;
            if ($location !== null) { $head .= 'Location: ' . $location . "\r\n"; }
            switch (true) {
case ($chunked):

                $head .= "Transfer-Encoding: chunked\r\n\r\n";
                $body = $path === '/framing/broken' ? "Z\r\ninvalid\r\n" : (match (true) { ($path === '/budget/tiny-chunks') => str_repeat("1\r\nx\r\n", times: 200_000) . "0\r\n\r\n", default => dechex(strlen($body)) . "\r\n" . $body . "\r\n0\r\nLocation: http://10.0.0.1/\r\n\r\n" });

    break;
default:
 $head .= 'Content-Length: ' . strlen($body) . "\r\n\r\n";
    break;
}
            $response = $head . $body;
            while ($response !== '') {
                $sent = \Tablo\NativeWarning::ignore(static function () use (&$connection, &$response) { return fwrite($connection, $response); });
                if ($sent === false || $sent === 0) { break; }
                $response = substr($response, $sent);
            }
        } finally { fclose($connection); unset($authorization, $line, $uri, $response); }
    }
} finally { fclose($server); }
