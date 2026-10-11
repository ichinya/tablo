<?php
declare(strict_types=1);

// Owned synthetic raw HTTP receiver; protocol framing is emitted, never parsed by product code.
$directory = $argv[1];
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) { throw new RuntimeException('Cannot allocate owned redirect peer'); }
$address = stream_socket_get_name($server, false);
file_put_contents($directory . '/ready.tmp', $address);
rename($directory . '/ready.tmp', $directory . '/ready');
$deadline = microtime(true) + 120;
try {
    while (microtime(true) < $deadline && !is_file($directory . '/stop')) {
        $read = [$server]; $write = $except = [];
        if (stream_select($read, $write, $except, 0, 100000) !== 1) { continue; }
        $connection = stream_socket_accept($server, 0);
        if ($connection === false) { continue; }
        try {
            stream_set_timeout($connection, 3);
            $line = fgets($connection, 8192);
            $methodGet = str_starts_with($line ?: '', 'GET ');
            $uri = explode(' ', $line ?: '')[1] ?? '/';
            $path = parse_url($uri, PHP_URL_PATH) ?: '/';
            parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);
            $authorization = ''; $unexpected = false;
            while (($line = fgets($connection, 8192)) !== false && $line !== "\r\n") {
                if (str_starts_with(strtolower($line), 'authorization:')) { $authorization = trim(substr($line, 14)); }
                if (preg_match('/\A(?:cookie|referer|x-api-key|proxy-authorization):/i', $line)) { $unexpected = true; }
            }
            $route = explode('/', trim($path, '/'))[0] ?: 'root';
            file_put_contents($directory . '/requests.jsonl', json_encode(['route' => $route,
                'bearer' => $authorization === 'Bearer synthetic-redirect-token', 'authorized' => $authorization !== '',
                'unexpected_headers' => $unexpected, 'method_get' => $methodGet]) . "\n", FILE_APPEND);
            $status = 200; $location = null; $body = '{"ok":true,"version":"v-safe","commit":"a61de82"}';
            $extra = ''; $prefix = ''; $chunked = false;
            if (preg_match('~^/status/(301|302|303|307|308|300|304|305)$~D', $path, $m)) { $status = (int) $m[1]; $location = '/final'; }
            elseif (preg_match('~^/chain/([0-9]+)$~D', $path, $m) && (int) $m[1] > 0) { $status = 302; $location = '/chain/' . ((int) $m[1] - 1); }
            elseif (str_starts_with($path, '/cycle/')) { $status = 302; $location = $path === '/cycle/a' ? '/cycle/b' : '/cycle/a'; }
            elseif ($path === '/relative/start' && !isset($query['done'])) {
                $status = 302;
                $location = match ($query['case'] ?? '') { 'root' => '/final', 'sibling' => 'final', 'dot' => '../final',
                    'query' => '?done=1', 'network' => '//' . $address . '/final', 'case' => 'HTTP://' . $address . '/final', default => './final' };
            } elseif (str_starts_with($path, '/cross/')) {
                $status = 302;
                $location = match (substr($path, 7)) { 'scheme' => 'https://' . $address . '/final',
                    'host' => 'http://localhost:' . parse_url('http://' . $address, PHP_URL_PORT) . '/final',
                    'port' => 'http://127.0.0.1:' . (int) ($query['port'] ?? 1) . '/final',
                    'private' => 'http://10.0.0.1/final', default => '/final' };
            } elseif (str_starts_with($path, '/header/')) {
                $status = 302;
                switch (substr($path, 8)) {
                    case 'missing': break;
                    case 'empty': $location = ''; break;
                    case 'duplicate': $location = '/final'; $extra = "Location: /final\r\n"; break;
                    case 'conflicting': $location = '/final'; $extra = "Location: /elsewhere\r\n"; break;
                    case 'folded': $location = '/final'; $extra = " more\r\n"; break;
                    case 'control': $location = "/final\x01"; break;
                    case 'userinfo': $location = 'http://synthetic-redirect-token@' . $address . '/final'; break;
                    case 'fragment': $location = '/final#synthetic-redirect-token'; break;
                    case 'malformed': $location = '/bad%zz'; break;
                    case 'interim': $prefix = "HTTP/1.1 103 Early Hints\r\nX-Interim: discarded\r\n\r\n"; $location = '/final'; break;
                    case 'interim-identical': $prefix = "HTTP/1.1 103 Early Hints\r\nLocation: /final\r\n\r\n"; $location = '/final'; break;
                    case 'interim-conflicting': $prefix = "HTTP/1.1 103 Early Hints\r\nLocation: http://10.0.0.1/\r\n\r\n"; $location = '/final'; break;
                    case 'trailer': $status = 200; $chunked = true; break;
                }
            } elseif ($path === '/budget/body') { $status = 302; $location = '/budget/final'; $body = str_repeat('x', 600000); }
            elseif ($path === '/budget/final') { $body = str_repeat('x', 600000); }
            elseif ($path === '/budget/headers') { $status = 302; $location = '/budget/header-final'; $extra = 'X-Pad: ' . str_repeat('x', 40000) . "\r\n"; }
            elseif ($path === '/budget/header-final') { $extra = 'X-Pad: ' . str_repeat('x', 40000) . "\r\n"; }
            elseif ($path === '/budget/combined') { $body = str_repeat('x', 1020000); $extra = 'X-Pad: ' . str_repeat('x', 40000) . "\r\n"; }
            elseif ($path === '/budget/tiny-chunks') { $chunked = true; $body = str_repeat('x', 200000); }
            elseif ($path === '/delay/start') { usleep(100000); $status = 302; $location = '/delay/final'; }
            elseif ($path === '/delay/final') { usleep(200000); }
            elseif ($path === '/final/false') { $body = '{"ok":false}'; }
            elseif ($path === '/final/json-invalid') { $body = 'not-json'; }
            elseif ($path === '/final/offline') { $status = 403; }
            elseif (str_starts_with($path, '/health/')) { $status = 302; $location = '/final/' . substr($path, 8); }
            elseif ($path === '/framing/start') { $status = 302; $location = '/framing/broken'; }
            elseif ($path === '/framing/broken') { $chunked = true; }
            $head = $prefix . 'HTTP/1.1 ' . $status . " Fixture\r\nConnection: close\r\n" . $extra;
            if ($location !== null) { $head .= 'Location: ' . $location . "\r\n"; }
            if ($chunked) {
                $head .= "Transfer-Encoding: chunked\r\n\r\n";
                $body = $path === '/framing/broken' ? "Z\r\ninvalid\r\n" : ($path === '/budget/tiny-chunks'
                    ? str_repeat("1\r\nx\r\n", 200000) . "0\r\n\r\n"
                    : dechex(strlen($body)) . "\r\n" . $body . "\r\n0\r\nLocation: http://10.0.0.1/\r\n\r\n");
            } else { $head .= 'Content-Length: ' . strlen($body) . "\r\n\r\n"; }
            $response = $head . $body;
            while ($response !== '') {
                $sent = @fwrite($connection, $response);
                if ($sent === false || $sent === 0) { break; }
                $response = substr($response, $sent);
            }
        } finally { fclose($connection); unset($authorization, $line, $uri, $response); }
    }
} finally { fclose($server); }
